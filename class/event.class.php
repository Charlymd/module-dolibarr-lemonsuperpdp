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
	public $entity;
	public $superpdp_event_id;
	public $status_code;
	public $message;
	public $direction;           // 'in' ou 'out'
	public $event_date;
	public $payload_raw;
	public $date_creation;
	public $tms;
	public $fk_user_creat;

	const DIRECTION_IN  = 'in';
	const DIRECTION_OUT = 'out';

	public function __construct($db)
	{
		$this->db = $db;
	}

	private function _setFromRow($obj)
	{
		$this->id = $obj->rowid;
		$this->rowid = $obj->rowid;
		$this->fk_transmission = $obj->fk_transmission;
		$this->entity = $obj->entity;
		$this->superpdp_event_id = $obj->superpdp_event_id;
		$this->status_code = $obj->status_code;
		$this->message = $obj->message;
		$this->direction = $obj->direction;
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
		$sql .= "fk_transmission, entity, superpdp_event_id, status_code, message, direction, event_date, payload_raw, date_creation, fk_user_creat";
		$sql .= ") VALUES (";
		$sql .= ((int) $this->fk_transmission);
		$sql .= ", ".((int) $conf->entity);
		$sql .= ", ".(!empty($this->superpdp_event_id) ? ((int) $this->superpdp_event_id) : "NULL");
		$sql .= ", '".$this->db->escape($this->status_code)."'";
		$sql .= ", ".(!empty($this->message) ? "'".$this->db->escape($this->message)."'" : "NULL");
		$sql .= ", '".$this->db->escape(!empty($this->direction) ? $this->direction : self::DIRECTION_IN)."'";
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
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."lemonsuperpdp_transmission t ON t.rowid = e.fk_transmission";
		$sql .= " WHERE t.fk_facture = ".((int) $fkFacture);
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
		$ac->type_code = 'AC_OTH_AUTO';
		$ac->code = 'LEMONSUPERPDP_'.strtoupper(str_replace(array(':', '-'), '_', $this->status_code));
		$ac->label = 'SUPER PDP : '.$label.' ('.$this->status_code.')';
		$ac->note_private = $note;
		$ac->elementtype = 'facture';
		$ac->fk_element = (int) $fkFacture;
		$ac->datep = !empty($this->event_date) ? $this->event_date : dol_now();
		$ac->datef = !empty($this->event_date) ? $this->event_date : dol_now();
		$ac->percentage = -1;

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
}
