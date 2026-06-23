<?php
/*
 * Copyright (C) 2026 SASU LEMON <https://hellolemon.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Objet métier : un événement de cycle de vie d'une facture SUPER PDP
 * (status_code AFNOR fr:200..fr:212). Les events peuvent être sortants
 * (direction='out', émis par nous via POST /v1.beta/invoice_events) ou
 * entrants (direction='in', récupérés via le cron de polling sur
 * GET /v1.beta/invoice_events).
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

class LemonSuperPDPEvent extends CommonObject
{
	public $element = 'lemonsuperpdp_event';
	public $table_element = 'lemonsuperpdp_event';

	public $rowid;
	public $fk_transmission;
	public $fk_facture;
	public $entity;
	public $superpdp_event_id;
	public $status_code;
	public $message;
	public $direction;           // 'in' ou 'out'
	public $flux;                // 'fournisseur' | 'pdp' | 'client'
	public $event_date;
	public $payload_raw;
	public $date_creation;
	public $tms;
	public $fk_user_creat;

	const DIRECTION_IN  = 'in';
	const DIRECTION_OUT = 'out';

	// Codes AFNOR du cycle de vie facture électronique (spec DGFiP / PA).
	// Utilisés partout dans le module, centralisés ici pour éviter les
	// strings magiques et faciliter la migration future vers la spec v2.
	const STATUS_DEPOSEE              = 'fr:200';
	const STATUS_REJET_EMETTRICE      = 'fr:201';
	const STATUS_RECUE_DESTINATAIRE   = 'fr:202';
	const STATUS_REJET_DESTINATAIRE   = 'fr:203';
	const STATUS_MISE_A_DISPOSITION   = 'fr:204';
	const STATUS_PRISE_EN_CHARGE      = 'fr:205';
	const STATUS_APPROUVEE            = 'fr:206';
	const STATUS_APPROUVEE_PARTIELLE  = 'fr:207';
	const STATUS_PAIEMENT_EN_COURS    = 'fr:208';
	const STATUS_PAIEMENT_TRANSMIS    = 'fr:209';
	const STATUS_REFUSEE              = 'fr:210';
	const STATUS_LITIGE               = 'fr:211';
	const STATUS_ENCAISSEE            = 'fr:212';

	public function __construct($db)
	{
		$this->db = $db;
	}

	private function _setFromRow($obj)
	{
		$this->id = $obj->rowid;
		$this->rowid = $obj->rowid;
		$this->fk_transmission = $obj->fk_transmission;
		$this->fk_facture = isset($obj->fk_facture) ? $obj->fk_facture : null;
		$this->entity = $obj->entity;
		$this->superpdp_event_id = $obj->superpdp_event_id;
		$this->status_code = $obj->status_code;
		$this->message = $obj->message;
		$this->direction = $obj->direction;
		$this->flux = isset($obj->flux) ? $obj->flux : null;
		$this->event_date = $this->db->jdate($obj->event_date);
		$this->payload_raw = $obj->payload_raw;
		$this->date_creation = $this->db->jdate($obj->date_creation);
		$this->tms = $this->db->jdate($obj->tms);
		$this->fk_user_creat = $obj->fk_user_creat;
	}

	public function create($user)
	{
		global $conf;
		$now = dol_now();

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."lemonsuperpdp_event (";
		$sql .= "fk_transmission, fk_facture, entity, superpdp_event_id, status_code, message, direction, flux, event_date, payload_raw, date_creation, fk_user_creat";
		$sql .= ") VALUES (";
		$sql .= (!empty($this->fk_transmission) ? ((int) $this->fk_transmission) : "NULL");
		$sql .= ", ".(!empty($this->fk_facture) ? ((int) $this->fk_facture) : "NULL");
		$sql .= ", ".((int) $conf->entity);
		$sql .= ", ".(!empty($this->superpdp_event_id) ? ((int) $this->superpdp_event_id) : "NULL");
		$sql .= ", '".$this->db->escape($this->status_code)."'";
		$sql .= ", ".(!empty($this->message) ? "'".$this->db->escape($this->message)."'" : "NULL");
		$sql .= ", '".$this->db->escape(!empty($this->direction) ? $this->direction : self::DIRECTION_IN)."'";
		$sql .= ", ".(!empty($this->flux) ? "'".$this->db->escape($this->flux)."'" : "NULL");
		$sql .= ", '".$this->db->idate(!empty($this->event_date) ? $this->event_date : $now)."'";
		$sql .= ", ".(!empty($this->payload_raw) ? "'".$this->db->escape($this->payload_raw)."'" : "NULL");
		$sql .= ", '".$this->db->idate($now)."'";
		$sql .= ", ".(!empty($user) ? ((int) $user->id) : "NULL");
		$sql .= ")";

		dol_syslog(get_class($this)."::create", LOG_DEBUG);
		$resql = $this->db->query($sql);
		if ($resql) {
			$this->id = $this->db->last_insert_id(MAIN_DB_PREFIX."lemonsuperpdp_event");
			$this->rowid = $this->id;
			return $this->id;
		}
		$this->error = $this->db->lasterror();
		$this->errors[] = $this->error;
		return -1;
	}

	/**
	 * Helper de création : instancie un event avec les champs passés en
	 * tableau, l'enregistre en base puis crée l'action agenda associée
	 * si un fkFacture est fourni. Centralise le pattern répété par les
	 * hooks, le cron, le trigger et l'envoi manuel.
	 *
	 * Champs attendus dans $attrs :
	 *   fk_transmission (int, requis)
	 *   status_code (string, requis)
	 *   direction (string, DIRECTION_IN|DIRECTION_OUT, requis)
	 *   superpdp_event_id (int, optionnel)
	 *   message (string, optionnel)
	 *   event_date (timestamp, optionnel, défaut dol_now())
	 *   payload_raw (string, optionnel)
	 *
	 * @param DoliDB    $db         Connexion base
	 * @param array     $attrs      Champs de l'event
	 * @param User      $user       Utilisateur courant
	 * @param int|null  $fkFacture  Facture cible pour l'action agenda (null = pas d'action)
	 * @return int                  ID créé (>0) ou <=0 si échec de l'insert
	 */
	public static function createAndLog($db, array $attrs, $user, $fkFacture = null)
	{
		$ev = new self($db);
		$ev->fk_transmission = isset($attrs['fk_transmission']) ? (int) $attrs['fk_transmission'] : null;
		$ev->fk_facture = isset($attrs['fk_facture']) ? (int) $attrs['fk_facture'] : null;
		$ev->superpdp_event_id = isset($attrs['superpdp_event_id']) ? (int) $attrs['superpdp_event_id'] : null;
		$ev->status_code = isset($attrs['status_code']) ? (string) $attrs['status_code'] : '';
		$ev->message = isset($attrs['message']) ? $attrs['message'] : null;
		$ev->direction = isset($attrs['direction']) ? $attrs['direction'] : self::DIRECTION_IN;
		$ev->flux = isset($attrs['flux']) ? $attrs['flux'] : null;
		$ev->event_date = isset($attrs['event_date']) ? $attrs['event_date'] : dol_now();
		$ev->payload_raw = isset($attrs['payload_raw']) ? $attrs['payload_raw'] : null;

		// Pour facturx:generated, enrichit le message avec le nom du dernier PDF
		// généré (last_main_doc sur la facture) — sans modifier LemonFacturX.
		if ($ev->status_code === 'facturx:generated') {
			$fk = !empty($ev->fk_facture) ? (int) $ev->fk_facture : (int) $fkFacture;
			if ($fk > 0) {
				$sqlDoc = "SELECT last_main_doc FROM " . MAIN_DB_PREFIX . "facture WHERE rowid = " . $fk;
				$resDoc = $db->query($sqlDoc);
				if ($resDoc && $db->num_rows($resDoc) > 0) {
					$objDoc = $db->fetch_object($resDoc);
					if (!empty($objDoc->last_main_doc)) {
						$docName = basename($objDoc->last_main_doc);
						$ev->message = rtrim((string) $ev->message, '.') . ' — ' . $docName;
					}
				}
			}
		}

		$ret = $ev->create($user);
		if ($ret > 0 && !empty($fkFacture)) {
			$ev->createActionComm((int) $fkFacture, $user);
		}
		return $ret;
	}

	/**
	 * Ingestion d'un lot d'events API SUPER PDP pour une transmission donnée.
	 *
	 * Factorise la logique commune à trois appelants :
	 *  - ActionsLemonSuperPDP::refreshEventsForFacture (bouton Rafraîchir)
	 *  - LemonSuperPDPCron::syncEvents               (cron de polling)
	 *  - scripts/cron_sync_events.php                (cron CLI alternatif)
	 *
	 * Pour chaque event du payload : filtre les doublons (par superpdp_event_id),
	 * insère via createAndLog() et repère le statut le plus récent.
	 *
	 * @param DoliDB   $db              Connexion base
	 * @param array    $events          Tableau d'events tels que renvoyés par
	 *                                  l'API (attendus : id, status_code,
	 *                                  message, created_at)
	 * @param int      $fkTransmission  ID transmission locale
	 * @param int|null $fkFacture       ID facture pour l'action agenda (null = pas d'action)
	 * @param User     $user            Utilisateur qui enregistre
	 *
	 * @return array{inserted:int, lastStatusCode:?string, lastTimestamp:int}
	 */
	public static function syncFromApiPayload($db, array $events, $fkTransmission, $fkFacture, $user)
	{
		$inserted = 0;
		$lastStatusCode = null;
		$lastTs = 0;

		$evProbe = new self($db);
		foreach ($events as $ev) {
			if (empty($ev['id']) || empty($ev['status_code'])) continue;

			$ts = !empty($ev['created_at']) ? strtotime($ev['created_at']) : 0;
			if ($ts >= $lastTs) {
				$lastTs = $ts;
				$lastStatusCode = (string) $ev['status_code'];
			}

			if ($evProbe->existsBySuperpdpId((int) $ev['id'])) continue;

			$ret = self::createAndLog($db, array(
				'fk_transmission'   => (int) $fkTransmission,
				'superpdp_event_id' => (int) $ev['id'],
				'status_code'       => (string) $ev['status_code'],
				'message'           => !empty($ev['message']) ? (string) $ev['message'] : null,
				'direction'         => self::DIRECTION_IN,
				'event_date'        => $ts > 0 ? $ts : dol_now(),
				'payload_raw'       => json_encode($ev),
			), $user, !empty($fkFacture) ? (int) $fkFacture : null);

			if ($ret > 0) $inserted++;
		}

		return array(
			'inserted' => $inserted,
			'lastStatusCode' => $lastStatusCode,
			'lastTimestamp' => $lastTs,
		);
	}

	/**
	 * Retourne true si un event avec cet id SUPER PDP existe déjà en base
	 * (utilisé par le cron de polling pour éviter les doublons).
	 */
	public function existsBySuperpdpId($superpdpEventId)
	{
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."lemonsuperpdp_event";
		$sql .= " WHERE superpdp_event_id = ".((int) $superpdpEventId);
		$sql .= " LIMIT 1";
		$resql = $this->db->query($sql);
		if ($resql) {
			$found = ($this->db->num_rows($resql) > 0);
			$this->db->free($resql);
			return $found;
		}
		return false;
	}

	/**
	 * Liste tous les events liés aux transmissions d'une facture donnée,
	 * ordonnés par date (plus récent en dernier).
	 *
	 * @param int $fkFacture
	 * @return array|int  Tableau d'objets hydratés, ou -1 en cas d'erreur.
	 */
	public function listByFacture($fkFacture)
	{
		global $conf;
		$sql = "SELECT e.* FROM ".MAIN_DB_PREFIX."lemonsuperpdp_event e";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."lemonsuperpdp_transmission t ON t.rowid = e.fk_transmission";
		$sql .= " WHERE COALESCE(t.fk_facture, e.fk_facture) = ".((int) $fkFacture);
		$sql .= " AND e.entity = ".((int) $conf->entity);
		$sql .= " ORDER BY e.event_date ASC, e.rowid ASC";

		$resql = $this->db->query($sql);
		if ($resql) {
			$events = array();
			while ($obj = $this->db->fetch_object($resql)) {
				$ev = new self($this->db);
				$ev->_setFromRow($obj);
				$events[] = $ev;
			}
			$this->db->free($resql);
			return $events;
		}
		$this->error = $this->db->lasterror();
		return -1;
	}

	/**
	 * Supprime tous les events liés à une transmission (nettoyage de la
	 * réinitialisation sandbox).
	 */
	public function deleteAllForTransmission($fkTransmission)
	{
		$sql = "DELETE FROM ".MAIN_DB_PREFIX."lemonsuperpdp_event";
		$sql .= " WHERE fk_transmission = ".((int) $fkTransmission);
		$resql = $this->db->query($sql);
		if ($resql) return $this->db->affected_rows($resql);
		$this->error = $this->db->lasterror();
		return -1;
	}

	/**
	 * Crée une entrée dans llx_actioncomm pour faire apparaître l'event
	 * dans l'onglet "Événements/Agenda" standard de la fiche facture.
	 * Doit être appelé après create().
	 *
	 * @param int   $fkFacture  ID de la facture Dolibarr concernée
	 * @param User  $user       Utilisateur courant
	 * @return int              ID de l'action créée, ou 0 si erreur (non-bloquant)
	 */
	public function createActionComm($fkFacture, $user)
	{
		if (empty($fkFacture)) return 0;

		require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';

		// Récupère le tiers de la facture pour renseigner fk_soc (nécessaire pour que
		// Dolibarr affiche l'événement dans l'onglet Événements de la fiche facture).
		$fkSoc = 0;
		$sqlSoc = "SELECT fk_soc FROM ".MAIN_DB_PREFIX."facture WHERE rowid = ".((int) $fkFacture);
		$resSoc = $this->db->query($sqlSoc);
		if ($resSoc && $this->db->num_rows($resSoc)) {
			$fkSoc = (int) $this->db->fetch_object($resSoc)->fk_soc;
		}

		$label = !empty($this->message) ? $this->message : self::getStatusLabel($this->status_code);
		$dirSuffix = ($this->direction === self::DIRECTION_OUT) ? ' (émis)' : ' (reçu)';

		$note = 'Événement SUPER PDP '.$this->status_code.' : '.$label.$dirSuffix;
		if (!empty($this->superpdp_event_id)) {
			$note .= "\nID SUPER PDP : ".((int) $this->superpdp_event_id);
		}
		if (!empty($this->payload_raw)) {
			$note .= "\n\nPayload brut :\n".$this->payload_raw;
		}

		$ac = new ActionComm($this->db);
		$ac->type_code    = 'AC_OTH_AUTO';
		$ac->code         = 'LEMONSUPERPDP_'.strtoupper(str_replace(array(':', '-'), '_', $this->status_code));
		$ac->label        = 'SUPER PDP : '.$label.' ('.$this->status_code.')';
		$ac->note_private = $note;
		$ac->elementtype  = 'invoice';
		$ac->fk_element   = (int) $fkFacture;
		$ac->fk_soc       = $fkSoc;
		$ac->userownerid  = is_object($user) && !empty($user->id) ? (int) $user->id : 0;
		$ac->datep        = !empty($this->event_date) ? $this->event_date : dol_now();
		$ac->datef        = !empty($this->event_date) ? $this->event_date : dol_now();
		$ac->percentage   = 100;

		$ret = $ac->create($user);
		if ($ret < 0) {
			dol_syslog('LemonSuperPDPEvent::createActionComm erreur : '.$ac->error, LOG_WARNING);
			return 0;
		}
		return (int) $ret;
	}

	/**
	 * Libellé humain d'un status_code AFNOR (fr:200..fr:212).
	 * Utilisé en fallback quand l'API ne fournit pas de message.
	 */
	public static function getStatusLabel($statusCode)
	{
		$map = array(
			'fr:200' => 'Déposée',
			'fr:201' => 'Rejetée par la plateforme émettrice',
			'fr:202' => 'Reçue par la plateforme destinataire',
			'fr:203' => 'Rejetée par la plateforme destinataire',
			'fr:204' => 'Mise à disposition',
			'fr:205' => 'Prise en charge',
			'fr:206' => 'Approuvée',
			'fr:207' => 'Approuvée partiellement',
			'fr:208' => 'Paiement en cours',
			'fr:209' => 'Paiement transmis',
			'fr:210' => 'Refusée',
			'fr:211' => 'Litige',
			'fr:212' => 'Encaissée',
			'ACK'    => 'Accusé de réception',
			'ACK-01' => 'Accusé de réception',
			'ACK-02' => 'Validation de format',
			'REJECT' => 'Rejet technique',
			'ROUTE'  => 'Routage confirmé',
		);
		return isset($map[$statusCode]) ? $map[$statusCode] : $statusCode;
	}

	/**
	 * Codes status_code que l'émetteur peut POSTer (par opposition aux
	 * events générés automatiquement par la plateforme : fr:200..fr:203).
	 */
	public static function getEmittableStatuses()
	{
		return array('fr:204', 'fr:205', 'fr:206', 'fr:207', 'fr:208', 'fr:209', 'fr:210', 'fr:211', 'fr:212');
	}

	/**
	 * Classe CSS du badge Dolibarr selon le statut.
	 */
	public static function getBadgeClass($statusCode)
	{
		switch ($statusCode) {
			case 'fr:200':
			case 'fr:204':
			case 'fr:205':
				return 'badge-status1';   // jaune — en cours
			case 'fr:202':
			case 'fr:206':
				return 'badge-status4';   // vert — OK
			case 'fr:207':
			case 'fr:208':
			case 'fr:209':
				return 'badge-status5';   // bleu — en cours paiement
			case 'fr:212':
				return 'badge-status6';   // orange — terminé/payée
			case 'fr:201':
			case 'fr:203':
			case 'fr:210':
				return 'badge-status8';   // rouge — rejet
			case 'fr:211':
				return 'badge-status9';   // rouge foncé — litige
			default:
				return 'badge-status0';   // gris
		}
	}

	/**
	 * Contenu du badge affiché dans l'onglet "Facturation électronique".
	 * Appelé par complete_head_from_modules() via la déclaration $this->tabs.
	 * Retourne '<span style="color:COLOR">●</span> N' ou '' si aucun event.
	 *
	 * @param  int        $fk_facture  ID de la facture
	 * @param  mixed      $dummy       Ignoré (signature Dolibarr)
	 * @return string
	 */
	public function getLifecycleBadgeContent($fk_facture, $dummy = null)
	{
		global $conf;
		$fk_facture = (int) $fk_facture;

		// LEFT JOIN pour inclure les events sans transmission (ex: facturx:generated)
		$sql = "SELECT e.status_code, t.status AS t_status, t.status_raw AS t_status_raw"
		     . " FROM " . MAIN_DB_PREFIX . "lemonsuperpdp_event e"
		     . " LEFT JOIN " . MAIN_DB_PREFIX . "lemonsuperpdp_transmission t ON t.rowid = e.fk_transmission"
		     . " WHERE COALESCE(t.fk_facture, e.fk_facture) = " . $fk_facture
		     . " AND e.entity = " . ((int) $conf->entity)
		     . " ORDER BY e.event_date DESC, e.rowid DESC";
		$res = $this->db->query($sql);
		if (!$res) return '';

		$count = $this->db->num_rows($res);
		if ($count === 0) {
			// Pas d'event : afficher un point rouge si la transmission est en erreur
			$sqlT = "SELECT status FROM " . MAIN_DB_PREFIX . "lemonsuperpdp_transmission"
			      . " WHERE fk_facture = " . $fk_facture
			      . " AND entity = " . ((int) $conf->entity)
			      . " ORDER BY rowid DESC LIMIT 1";
			$resT = $this->db->query($sqlT);
			if ($resT && $this->db->num_rows($resT) > 0) {
				$t = $this->db->fetch_object($resT);
				if ($t->status === 'error') {
					return '<span style="color:#A32D2D">●</span> 0';
				}
			}
			return '';
		}

		$lastObj    = $this->db->fetch_object($res);
		$lastCode   = $lastObj ? $lastObj->status_code : '';
		$tStatus    = $lastObj ? (string) $lastObj->t_status : '';
		$tStatusRaw = $lastObj ? (string) $lastObj->t_status_raw : '';

		$badCodes = array('ERROR', 'REJECT', 'fr:201', 'fr:203', 'fr:210', 'fr:211');
		if (in_array($lastCode, $badCodes, true) || $tStatus === 'error') {
			$color = self::_badgeColor($lastCode); // rouge ou orange selon le code
		} elseif ($tStatusRaw === 'recovered') {
			$color = '#CC9900'; // jaune/ambre — transmission récupérée (avertissement)
		} elseif (in_array($tStatus, array('sent', 'accepted', 'paid'), true)) {
			$color = '#3B6D11'; // vert — transmission réussie
		} else {
			$color = self::_badgeColor($lastCode);
		}

		return '<span style="color:' . $color . '">&#9679;</span> ' . $count;
	}

	private static function _badgeColor($code)
	{
		if (in_array($code, array('ERROR', 'fr:210', 'REJECT', 'fr:201', 'fr:203'), true)) return '#A32D2D';
		if ($code === 'fr:211')        return '#854F0B';
		if ($code === 'api:recovered') return '#CC9900'; // ambre — avertissement
		if (in_array($code, array('fr:212', 'fr:206', 'fr:207'), true))              return '#3B6D11';
		if (in_array($code, array('fr:204', 'fr:205', 'fr:208', 'fr:209',
		                          'fr:200', 'fr:202', 'ACK', 'ACK-01',
		                          'ACK-02', 'ROUTE'), true))                          return '#185FA5';
		return '#888780';
	}
}
