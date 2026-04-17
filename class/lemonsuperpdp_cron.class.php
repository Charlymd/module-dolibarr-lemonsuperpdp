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
		$evObj = new LemonSuperPDPEvent($this->db);

		$lastId = (int) getDolGlobalString('LEMONSUPERPDP_LAST_EVENT_ID', '0');
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

				foreach ($data as $ev) {
					if (empty($ev['id']) || empty($ev['status_code'])) continue;
					$lastId = max($lastId, (int) $ev['id']);

					if ($evObj->existsBySuperpdpId((int) $ev['id'])) continue;

					$invoiceId = !empty($ev['invoice_id']) ? (int) $ev['invoice_id'] : 0;
					if ($invoiceId <= 0) continue;

					// Une seule requête pour récupérer rowid + fk_facture
					// (évite le N+1 quand on crée l'action agenda juste après).
					$sql = "SELECT rowid, fk_facture FROM ".MAIN_DB_PREFIX."lemonsuperpdp_transmission";
					$sql .= " WHERE superpdp_id = ".((int) $invoiceId);
					$sql .= " LIMIT 1";
					$sqlRes = $this->db->query($sql);
					$fkTransmission = 0;
					$fkFacture = 0;
					if ($sqlRes && ($row = $this->db->fetch_object($sqlRes))) {
						$fkTransmission = (int) $row->rowid;
						$fkFacture = (int) $row->fk_facture;
						$this->db->free($sqlRes);
					}
					if ($fkTransmission <= 0) continue;

					$inserted = LemonSuperPDPEvent::createAndLog($this->db, array(
						'fk_transmission'   => $fkTransmission,
						'superpdp_event_id' => (int) $ev['id'],
						'status_code'       => (string) $ev['status_code'],
						'message'           => !empty($ev['message']) ? (string) $ev['message'] : null,
						'direction'         => LemonSuperPDPEvent::DIRECTION_IN,
						'event_date'        => !empty($ev['created_at']) ? strtotime($ev['created_at']) : dol_now(),
						'payload_raw'       => json_encode($ev),
					), $user, $fkFacture > 0 ? $fkFacture : null);

					if ($inserted > 0) {
						$nbInserted++;
						$touchedTransmissions[$fkTransmission] = true;
					}
				}
			}

			// Met à jour le statut local de chaque transmission touchée
			// d'après son dernier event.
			$this->updateTransmissionsStatusFromEvents(array_keys($touchedTransmissions), $user);

			dolibarr_set_const($this->db, 'LEMONSUPERPDP_LAST_EVENT_ID', (string) $lastId, 'chaine', 0, '', 0);

			$this->output = $nbInserted.' nouvel(s) événement(s) inséré(s), dernier id = '.$lastId.', '.$nbPages.' pages lues';
			return 0;
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			dol_syslog('LemonSuperPDPCron::syncEvents : '.$e->getMessage(), LOG_ERR);
			return -1;
		}
	}

	/**
	 * Pour chaque transmission en paramètre, recalcule le status local à
	 * partir de son event le plus récent.
	 */
	private function updateTransmissionsStatusFromEvents($fkTransmissions, $user)
	{
		if (empty($fkTransmissions)) return;

		foreach ($fkTransmissions as $fkT) {
			$sql = "SELECT status_code FROM ".MAIN_DB_PREFIX."lemonsuperpdp_event";
			$sql .= " WHERE fk_transmission = ".((int) $fkT);
			$sql .= " ORDER BY event_date DESC, rowid DESC LIMIT 1";
			$resql = $this->db->query($sql);
			if (!$resql) continue;
			$row = $this->db->fetch_object($resql);
			$this->db->free($resql);
			if (empty($row)) continue;

			$t = new LemonSuperPDPTransmission($this->db);
			if ($t->fetch($fkT) <= 0) continue;
			$t->status_raw = $row->status_code;
			$mapped = LemonSuperPDPTransmission::mapStatusFromEventCode($row->status_code);
			if ($mapped !== null) {
				$t->status = $mapped;
			}
			$t->update($user);
		}
	}
}
