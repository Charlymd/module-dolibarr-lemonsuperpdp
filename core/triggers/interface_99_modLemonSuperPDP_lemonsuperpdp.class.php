<?php
/*
 * Copyright (C) 2026 SASU LEMON <https://hellolemon.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Trigger Dolibarr : envoie automatiquement le statut Encaissée (fr:212)
 * à SUPER PDP quand une facture passe à "Payée" (BILL_PAYED).
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

class InterfaceLemonsuperpdp extends DolibarrTriggers
{
	public function __construct($db)
	{
		$this->db = $db;
		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = "lemonsuperpdp";
		$this->description = "Trigger envoi automatique des statuts SUPER PDP (fr:212 Encaissée sur paiement)";
		$this->version = self::VERSION_DOLIBARR;
		$this->picto = 'bill';
	}

	/**
	 * Trigger handler.
	 *
	 * @param string       $action     Code de l'action (BILL_PAYED, etc.)
	 * @param CommonObject $object     Objet impacté
	 * @param User         $user       Utilisateur courant
	 * @param Translate    $langs      Langs
	 * @param Conf         $conf       Conf
	 * @return int 0 si pas traité, >0 si OK, <0 si erreur
	 */
	public function runTrigger($action, $object, $user, $langs, $conf)
	{
		if (!isModEnabled('lemonsuperpdp')) return 0;
		if (!getDolGlobalInt('LEMONSUPERPDP_ENABLED')) return 0;

		// Seuls BILL_PAYED et PAYMENT_CUSTOMER_CREATE nous intéressent pour l'instant.
		// BILL_PAYED : déclenché quand toutes les lignes de règlement couvrent le TTC
		//              de la facture, après validation du paiement.
		if ($action !== 'BILL_PAYED') return 0;

		if (!is_object($object) || !isset($object->element) || $object->element !== 'facture') return 0;

		dol_syslog('LemonSuperPDP trigger: '.$action.' sur facture '.$object->id, LOG_INFO);

		try {
			$this->sendEncaisseeEvent($object, $user);
		} catch (Exception $e) {
			dol_syslog('LemonSuperPDP trigger: échec envoi fr:212 facture '.$object->id.' : '.$e->getMessage(), LOG_ERR);
			// On n'échoue PAS le trigger : le paiement Dolibarr doit passer
			// même si SUPER PDP est indisponible. Le cron de polling rattrapera.
			return 0;
		}

		return 1;
	}

	/**
	 * Envoie l'event fr:212 Encaissée à SUPER PDP avec les montants ventilés par TVA.
	 */
	private function sendEncaisseeEvent($facture, $user)
	{
		dol_include_once('/lemonsuperpdp/class/transmission.class.php');
		dol_include_once('/lemonsuperpdp/class/event.class.php');
		dol_include_once('/lemonsuperpdp/class/superpdp_client.class.php');

		// Retrouve la transmission réussie liée à cette facture.
		$t = new LemonSuperPDPTransmission($this->db);
		if ($t->fetchLastByFacture($facture->id) <= 0) {
			dol_syslog('LemonSuperPDP trigger: pas de transmission pour facture '.$facture->id.', skip', LOG_DEBUG);
			return;
		}
		if (empty($t->superpdp_id)) {
			dol_syslog('LemonSuperPDP trigger: transmission sans superpdp_id, skip', LOG_DEBUG);
			return;
		}
		if ($t->status === LemonSuperPDPTransmission::STATUS_PAID) {
			dol_syslog('LemonSuperPDP trigger: facture déjà marquée Encaissée, skip', LOG_DEBUG);
			return;
		}

		// Construction des détails : montants par taux de TVA.
		if (empty($facture->lines)) {
			$facture->fetch_lines();
		}
		$amountsByRate = array();
		foreach ($facture->lines as $line) {
			$rate = (float) $line->tva_tx;
			$key = number_format($rate, 1, '.', '');
			if (!isset($amountsByRate[$key])) {
				$amountsByRate[$key] = 0.0;
			}
			$amountsByRate[$key] += (float) $line->total_ht;
		}

		// Date d'encaissement : on prend la date du dernier paiement si dispo,
		// sinon la date du jour.
		$paymentDate = date('Y-m-d');
		$sql = "SELECT MAX(p.datep) AS last_pay FROM ".MAIN_DB_PREFIX."paiement p";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."paiement_facture pf ON pf.fk_paiement = p.rowid";
		$sql .= " WHERE pf.fk_facture = ".((int) $facture->id);
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql)) && !empty($obj->last_pay)) {
			$paymentDate = date('Y-m-d', $this->db->jdate($obj->last_pay));
			$this->db->free($resql);
		}

		$amounts = array();
		foreach ($amountsByRate as $rate => $netAmount) {
			$amounts[] = array(
				'net_amount' => number_format($netAmount, 2, '.', ''),
				'currency_code' => 'EUR',
				'type_code' => 'MEN',
				'vat_rate' => $rate,
				'date' => $paymentDate,
			);
		}

		$details = array(array('amounts' => $amounts));

		$client = new SuperPDPClient($this->db);
		$response = $client->submitEvent((int) $t->superpdp_id, 'fr:212', $details);

		// Enregistre l'event dans la table locale.
		$ev = new LemonSuperPDPEvent($this->db);
		$ev->fk_transmission = $t->id;
		$ev->superpdp_event_id = !empty($response['id']) ? (int) $response['id'] : null;
		$ev->status_code = 'fr:212';
		$ev->message = 'Encaissée';
		$ev->direction = LemonSuperPDPEvent::DIRECTION_OUT;
		$ev->event_date = dol_now();
		$ev->payload_raw = json_encode($response);
		if ($ev->create($user) > 0) {
			$ev->createActionComm($facture->id, $user);
		}

		// Met à jour le statut de la transmission.
		$t->status = LemonSuperPDPTransmission::STATUS_PAID;
		$t->status_raw = 'fr:212';
		$t->update($user);

		dol_syslog('LemonSuperPDP trigger: fr:212 envoyé pour facture '.$facture->ref.' (transmission '.$t->id.')', LOG_INFO);
	}
}
