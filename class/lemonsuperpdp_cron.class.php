<?php
/*
 * Copyright (C) 2026 SASU LEMON <https://hellolemon.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Méthodes de tâches planifiées Dolibarr pour LemonSuperPDP.
 *
 * Utilisé par le planificateur interne Dolibarr (type "Méthode") plutôt
 * que par exécution shell directe, pour éviter d'avoir à activer
 * dolibarr_cron_allow_cli dans conf.php.
 *
 * Déclaration de la tâche Dolibarr :
 *   Type    : Exécution d'une méthode d'une classe PHP
 *   Classe  : LemonSuperPDPCron
 *   Fichier : /lemonsuperpdp/class/lemonsuperpdp_cron.class.php
 *   Méthode : syncEvents
 *   Paramètre : (vide)
 */

class LemonSuperPDPCron
{
	/** Pagination : on s'arrête au-delà pour éviter une boucle infinie en cas de réponse API incohérente. */
	const MAX_PAGES = 50;

	public $db;
	public $error = '';
	public $output = '';

	public function __construct($db = null)
	{
		if ($db !== null) {
			$this->db = $db;
		} else {
			global $db;
			$this->db = $db;
		}
	}

	/**
	 * Synchronise les invoice_events SUPER PDP : itère sur
	 * GET /v1.beta/invoice_events avec starting_after_id, insère les
	 * nouveaux events en base et met à jour le statut de la transmission
	 * associée.
	 *
	 * @param string $param  Paramètre libre (non utilisé)
	 * @return int           0 = succès, <0 = erreur
	 */
	public function syncEvents($param = '')
	{
		global $user;

		$this->output = '';
		$this->error = '';

		if (!isModEnabled('lemonsuperpdp')) {
			$this->output = 'Module lemonsuperpdp désactivé';
			return 0;
		}
		if (!getDolGlobalInt('LEMONSUPERPDP_ENABLED')) {
			$this->output = 'LEMONSUPERPDP_ENABLED=0';
			return 0;
		}

		// Utilisateur courant : celui du cron (user admin si tâche lancée
		// depuis le planificateur interne, ou null en CLI).
		if (empty($user) || empty($user->id)) {
			require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
			$user = new User($this->db);
			$user->fetch(1);
		}

		dol_include_once('/lemonsuperpdp/class/superpdp_client.class.php');
		dol_include_once('/lemonsuperpdp/class/transmission.class.php');
		dol_include_once('/lemonsuperpdp/class/event.class.php');

		$client = new SuperPDPClient($this->db);
		$tObj = new LemonSuperPDPTransmission($this->db);

		$initialLastId = (int) getDolGlobalString('LEMONSUPERPDP_LAST_EVENT_ID', '0');
		$lastId = $initialLastId;
		$nbInserted = 0;
		$nbPages = 0;
		$touchedTransmissions = array();

		try {
			$hasAfter = true;
			while ($hasAfter && $nbPages < self::MAX_PAGES) {
				$nbPages++;
				$resp = $client->listInvoiceEvents($lastId);
				$data = !empty($resp['data']) && is_array($resp['data']) ? $resp['data'] : array();
				$hasAfter = !empty($resp['has_after']);

				// Regroupement par invoice_id : une seule résolution transmission
				// par facture, même si la page contient plusieurs events liés.
				$eventsByInvoice = array();
				foreach ($data as $ev) {
					if (empty($ev['id']) || empty($ev['status_code']) || empty($ev['invoice_id'])) continue;
					$lastId = max($lastId, (int) $ev['id']);
					$eventsByInvoice[(int) $ev['invoice_id']][] = $ev;
				}

				foreach ($eventsByInvoice as $invoiceId => $invoiceEvents) {
					$info = $tObj->fetchIdAndFactureBySuperpdpId($invoiceId);
					if ($info === null) continue;

					$result = LemonSuperPDPEvent::syncFromApiPayload(
						$this->db,
						$invoiceEvents,
						$info['id'],
						$info['fk_facture'] > 0 ? $info['fk_facture'] : null,
						$user
					);
					if ($result['inserted'] > 0) {
						$nbInserted += $result['inserted'];
						$touchedTransmissions[$info['id']] = true;
					}
				}
			}

			// Met à jour le statut local de chaque transmission touchée
			// d'après son dernier event.
			$this->updateTransmissionsStatusFromEvents(array_keys($touchedTransmissions), $user);

			// Écriture du curseur uniquement si avancement : évite d'écrire
			// une constante à chaque passe de cron quand rien ne bouge.
			if ($lastId !== $initialLastId) {
				dolibarr_set_const($this->db, 'LEMONSUPERPDP_LAST_EVENT_ID', (string) $lastId, 'chaine', 0, '', 0);
			}

			$this->output = $nbInserted.' nouvel(s) événement(s) inséré(s), dernier id = '.$lastId.', '.$nbPages.' pages lues';
			return 0;
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			dol_syslog('LemonSuperPDPCron::syncEvents : '.$e->getMessage(), LOG_ERR);
			return -1;
		}
	}

	/**
	 * Synchronise les factures fournisseurs reçues (direction=in) :
	 * polling avec curseur LEMONSUPERPDP_LAST_IN_ID, création des
	 * FactureFournisseur brouillon pour les tiers résolus, quarantaine sinon.
	 *
	 * Déclaration de la tâche Dolibarr :
	 *   Classe  : LemonSuperPDPCron
	 *   Fichier : /lemonsuperpdp/class/lemonsuperpdp_cron.class.php
	 *   Méthode : syncIncoming
	 *
	 * @param string $param  Paramètre libre (non utilisé)
	 * @return int           0 = succès, <0 = erreur
	 */
	public function syncIncoming($param = '')
	{
		global $user;

		$this->output = '';
		$this->error = '';

		if (!isModEnabled('lemonsuperpdp')) {
			$this->output = 'Module lemonsuperpdp désactivé';
			return 0;
		}
		if (!getDolGlobalInt('LEMONSUPERPDP_ENABLED')) {
			$this->output = 'LEMONSUPERPDP_ENABLED=0';
			return 0;
		}
		if (!getDolGlobalInt('LEMONSUPERPDP_IN_ENABLED')) {
			$this->output = 'Réception désactivée (LEMONSUPERPDP_IN_ENABLED=0)';
			return 0;
		}
		if (!isModEnabled('supplier_invoice') && !isModEnabled('fournisseur')) {
			$this->output = 'Module Fournisseurs/Factures fournisseurs désactivé';
			return 0;
		}

		if (empty($user) || empty($user->id)) {
			require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
			$user = new User($this->db);
			$user->fetch(1);
		}

		dol_include_once('/lemonsuperpdp/class/superpdp_client.class.php');
		dol_include_once('/lemonsuperpdp/class/reception.class.php');

		try {
			$res = LemonSuperPDPReception::syncIncoming($this->db, $user);
			$this->output = $res['fetched'].' facture(s) reçue(s), '.$res['imported'].' importée(s) en brouillon, '
				.$res['quarantined'].' en quarantaine, '.$res['errors'].' erreur(s), dernier id = '.$res['lastId'];
			return ($res['errors'] > 0) ? -1 : 0;
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			dol_syslog('LemonSuperPDPCron::syncIncoming : '.$e->getMessage(), LOG_ERR);
			return -1;
		}
	}

	/**
	 * Pousse les déclarations e-reporting B2C en attente (transactions et
	 * paiements) vers l'API SUPER PDP.
	 *
	 * Déclaration de la tâche Dolibarr :
	 *   Classe  : LemonSuperPDPCron
	 *   Fichier : /lemonsuperpdp/class/lemonsuperpdp_cron.class.php
	 *   Méthode : sendEreporting
	 *
	 * @param string $param  Paramètre libre (non utilisé)
	 * @return int           0 = succès, <0 = erreur
	 */
	public function sendEreporting($param = '')
	{
		global $user;

		$this->output = '';
		$this->error = '';

		if (!isModEnabled('lemonsuperpdp')) {
			$this->output = 'Module lemonsuperpdp désactivé';
			return 0;
		}
		if (!getDolGlobalInt('LEMONSUPERPDP_ENABLED')) {
			$this->output = 'LEMONSUPERPDP_ENABLED=0';
			return 0;
		}
		if (!getDolGlobalInt('LEMONSUPERPDP_EREPORTING_ENABLED')) {
			$this->output = 'E-reporting désactivé (LEMONSUPERPDP_EREPORTING_ENABLED=0)';
			return 0;
		}

		if (empty($user) || empty($user->id)) {
			require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
			$user = new User($this->db);
			$user->fetch(1);
		}

		dol_include_once('/lemonsuperpdp/class/ereporting.class.php');

		try {
			$res = LemonSuperPDPEreporting::processPending($this->db, $user);
			$this->output = $res['sent'].' déclaration(s) transmise(s), '.$res['errors'].' refusée(s), '.$res['pending'].' à retenter';
			return ($res['errors'] > 0) ? -1 : 0;
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			dol_syslog('LemonSuperPDPCron::sendEreporting : '.$e->getMessage(), LOG_ERR);
			return -1;
		}
	}

	/**
	 * Pour chaque transmission en paramètre, recalcule le status local à
	 * partir de son event le plus récent.
	 *
	 * Stratégie : un seul SELECT IN sur tous les fkTransmissions touchés,
	 * tri décroissant par date, retenue du premier vu par transmission côté
	 * PHP. Puis UPDATE direct (sans fetch intermédiaire) pour mettre à jour
	 * status + status_raw.
	 */
	private function updateTransmissionsStatusFromEvents($fkTransmissions, $user)
	{
		if (empty($fkTransmissions)) return;

		$ids = array_map('intval', $fkTransmissions);
		$sql = "SELECT fk_transmission, status_code FROM ".MAIN_DB_PREFIX."lemonsuperpdp_event";
		$sql .= " WHERE fk_transmission IN (".implode(',', $ids).")";
		$sql .= " ORDER BY event_date DESC, rowid DESC";

		$resql = $this->db->query($sql);
		if (!$resql) return;

		$latestByTransmission = array();
		while ($row = $this->db->fetch_object($resql)) {
			$fkT = (int) $row->fk_transmission;
			if (!isset($latestByTransmission[$fkT])) {
				$latestByTransmission[$fkT] = (string) $row->status_code;
			}
		}
		$this->db->free($resql);

		foreach ($latestByTransmission as $fkT => $lastCode) {
			$mapped = LemonSuperPDPTransmission::mapStatusFromEventCode($lastCode);
			$setStatus = ($mapped !== null) ? ", status = '".$this->db->escape($mapped)."'" : '';
			$upd = "UPDATE ".MAIN_DB_PREFIX."lemonsuperpdp_transmission";
			$upd .= " SET status_raw = '".$this->db->escape($lastCode)."'";
			$upd .= $setStatus;
			$upd .= ", date_status_update = '".$this->db->idate(dol_now())."'";
			$upd .= " WHERE rowid = ".((int) $fkT);
			$this->db->query($upd);
		}
	}
}
