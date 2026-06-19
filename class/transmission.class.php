<?php
/*
 * Copyright (C) 2026 SASU LEMON <https://hellolemon.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Objet métier : une transmission d'une facture Dolibarr vers SUPER PDP
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

class LemonSuperPDPTransmission extends CommonObject
{
	public $element = 'lemonsuperpdp_transmission';
	public $table_element = 'lemonsuperpdp_transmission';

	public $rowid;
	public $fk_facture;
	public $entity;
	public $superpdp_id;
	public $status;
	public $status_raw;
	public $recipient_address;
	public $format_sent;
	public $error_message;
	public $payload_response;
	public $date_sent;
	public $date_status_update;
	public $tms;
	public $fk_user_sent;

	const STATUS_PENDING  = 'pending';
	const STATUS_SENT     = 'sent';
	const STATUS_ACCEPTED = 'accepted';
	const STATUS_REFUSED  = 'refused';
	const STATUS_PAID     = 'paid';
	const STATUS_ERROR    = 'error';

	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Table de correspondance status_code AFNOR (fr:200..fr:212) vers
	 * status local de la transmission. Utilisée par le cron de polling
	 * et par le rafraîchissement manuel depuis la fiche facture pour
	 * garder une source unique de vérité.
	 *
	 * @param string $statusCode  Code AFNOR (ex : 'fr:212')
	 * @return string|null        Statut local correspondant ou null si non mappé
	 */
	public static function mapStatusFromEventCode($statusCode)
	{
		$map = array(
			'fr:200' => self::STATUS_SENT,
			'fr:202' => self::STATUS_ACCEPTED,
			'fr:204' => self::STATUS_ACCEPTED,
			'fr:206' => self::STATUS_ACCEPTED,
			'fr:212' => self::STATUS_PAID,
			'fr:201' => self::STATUS_REFUSED,
			'fr:203' => self::STATUS_REFUSED,
			'fr:210' => self::STATUS_REFUSED,
		);
		return isset($map[$statusCode]) ? $map[$statusCode] : null;
	}

	/**
	 * Ventile les lignes d'une facture par taux de TVA et produit le tableau
	 * "amounts" attendu par l'API SUPER PDP pour les events de paiement
	 * (fr:207, fr:212...).
	 *
	 * @param Facture $facture     Facture Dolibarr (fetch_lines() fait si besoin)
	 * @param string  $paymentDate Date au format Y-m-d
	 * @return array               Liste conforme au schéma SUPER PDP
	 */
	public static function buildAmountsByVatRate($facture, $paymentDate)
	{
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
		return $amounts;
	}

	private function _setFromRow($obj)
	{
		$this->id = $obj->rowid;
		$this->rowid = $obj->rowid;
		$this->fk_facture = $obj->fk_facture;
		$this->entity = $obj->entity;
		$this->superpdp_id = $obj->superpdp_id;
		$this->status = $obj->status;
		$this->status_raw = $obj->status_raw;
		$this->recipient_address = $obj->recipient_address;
		$this->format_sent = $obj->format_sent;
		$this->error_message = $obj->error_message;
		$this->payload_response = $obj->payload_response;
		$this->date_sent = $this->db->jdate($obj->date_sent);
		$this->date_status_update = $this->db->jdate($obj->date_status_update);
		$this->tms = $this->db->jdate($obj->tms);
		$this->fk_user_sent = $obj->fk_user_sent;
	}

	public function create($user)
	{
		global $conf;
		$now = dol_now();

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."lemonsuperpdp_transmission (";
		$sql .= "fk_facture, entity, status, format_sent, date_sent, fk_user_sent";
		$sql .= ") VALUES (";
		$sql .= ((int) $this->fk_facture);
		$sql .= ", ".((int) $conf->entity);
		$sql .= ", '".$this->db->escape(!empty($this->status) ? $this->status : self::STATUS_PENDING)."'";
		$sql .= ", '".$this->db->escape(!empty($this->format_sent) ? $this->format_sent : 'facturx')."'";
		$sql .= ", '".$this->db->idate($now)."'";
		$sql .= ", ".((int) $user->id);
		$sql .= ")";

		dol_syslog(get_class($this)."::create", LOG_DEBUG);
		$resql = $this->db->query($sql);
		if ($resql) {
			$this->id = $this->db->last_insert_id(MAIN_DB_PREFIX."lemonsuperpdp_transmission");
			$this->rowid = $this->id;
			$this->date_sent = $now;
			return $this->id;
		}
		$this->error = $this->db->lasterror();
		$this->errors[] = $this->error;
		return -1;
	}

	public function fetch($id)
	{
		$sql = "SELECT * FROM ".MAIN_DB_PREFIX."lemonsuperpdp_transmission";
		$sql .= " WHERE rowid = ".((int) $id);

		$resql = $this->db->query($sql);
		if ($resql) {
			if ($this->db->num_rows($resql)) {
				$obj = $this->db->fetch_object($resql);
				$this->_setFromRow($obj);
				$this->db->free($resql);
				return 1;
			}
			$this->db->free($resql);
			return 0;
		}
		$this->error = $this->db->lasterror();
		return -1;
	}

	/**
	 * Charge la dernière transmission (toutes statuts confondus) pour une facture donnée.
	 */
	public function fetchLastByFacture($fkFacture)
	{
		global $conf;
		$sql = "SELECT * FROM ".MAIN_DB_PREFIX."lemonsuperpdp_transmission";
		$sql .= " WHERE fk_facture = ".((int) $fkFacture);
		$sql .= " AND entity = ".((int) $conf->entity);
		$sql .= " ORDER BY rowid DESC LIMIT 1";

		$resql = $this->db->query($sql);
		if ($resql) {
			$found = 0;
			if ($this->db->num_rows($resql)) {
				$obj = $this->db->fetch_object($resql);
				$this->_setFromRow($obj);
				$found = 1;
			}
			$this->db->free($resql);
			return $found;
		}
		$this->error = $this->db->lasterror();
		return -1;
	}

	/**
	 * Résout un superpdp_id vers {rowid transmission, fk_facture Dolibarr}.
	 * Utilisé par le cron de polling pour rattacher un event entrant à sa
	 * transmission locale sans exposer le SQL aux appelants.
	 *
	 * @param int $superpdpId  ID de facture côté SUPER PDP
	 * @return array|null      ['id' => int, 'fk_facture' => int] ou null si absent
	 */
	public function fetchIdAndFactureBySuperpdpId($superpdpId)
	{
		$sql = "SELECT rowid, fk_facture FROM ".MAIN_DB_PREFIX."lemonsuperpdp_transmission";
		$sql .= " WHERE superpdp_id = ".((int) $superpdpId);
		$sql .= " LIMIT 1";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return null;
		}
		$row = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (empty($row)) return null;

		return array(
			'id' => (int) $row->rowid,
			'fk_facture' => (int) $row->fk_facture,
		);
	}

	/**
	 * Vrai si la facture a déjà une transmission réussie (sent/accepted/paid).
	 */
	public function hasSuccessfulTransmission($fkFacture)
	{
		global $conf;
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."lemonsuperpdp_transmission";
		$sql .= " WHERE fk_facture = ".((int) $fkFacture);
		$sql .= " AND entity = ".((int) $conf->entity);
		$sql .= " AND status IN ('".self::STATUS_SENT."', '".self::STATUS_ACCEPTED."', '".self::STATUS_PAID."')";
		$sql .= " LIMIT 1";

		$resql = $this->db->query($sql);
		if ($resql) {
			$found = ($this->db->num_rows($resql) > 0);
			$this->db->free($resql);
			return $found;
		}
		return false;
	}

	public function update($user)
	{
		$nullOrStr = function ($v) { return $v !== null ? "'".$this->db->escape($v)."'" : 'NULL'; };
		$sql  = "UPDATE ".MAIN_DB_PREFIX."lemonsuperpdp_transmission SET";
		$sql .= " superpdp_id = ".(!empty($this->superpdp_id) ? ((int) $this->superpdp_id) : "NULL");
		$sql .= ", status = '".$this->db->escape($this->status)."'";
		$sql .= ", format_sent = ".$nullOrStr($this->format_sent);
		$sql .= ", status_raw = ".$nullOrStr($this->status_raw);
		$sql .= ", recipient_address = ".$nullOrStr($this->recipient_address);
		$sql .= ", error_message = ".$nullOrStr($this->error_message);
		$sql .= ", payload_response = ".$nullOrStr($this->payload_response);
		$sql .= ", date_status_update = '".$this->db->idate(dol_now())."'";
		$sql .= " WHERE rowid = ".((int) $this->id);

		dol_syslog(get_class($this)."::update", LOG_DEBUG);
		$resql = $this->db->query($sql);
		if ($resql) return 1;
		$this->error = $this->db->lasterror();
		return -1;
	}

	/**
	 * Supprime toutes les transmissions d'une facture donnée, ainsi que leurs
	 * events associés (cascade manuelle, pas de FK SQL). Utile pour
	 * réinitialiser l'état en phase de test sandbox.
	 *
	 * @param int $fkFacture
	 * @return int  Nombre de transmissions supprimées, ou -1 en cas d'erreur
	 */
	public function deleteAllForFacture($fkFacture)
	{
		global $conf;

		// Récupère d'abord les rowids pour supprimer les events associés
		$sqlIds = "SELECT rowid FROM ".MAIN_DB_PREFIX."lemonsuperpdp_transmission";
		$sqlIds .= " WHERE fk_facture = ".((int) $fkFacture);
		$sqlIds .= " AND entity = ".((int) $conf->entity);
		$resIds = $this->db->query($sqlIds);
		$transmissionIds = array();
		if ($resIds) {
			while ($row = $this->db->fetch_object($resIds)) {
				$transmissionIds[] = (int) $row->rowid;
			}
			$this->db->free($resIds);
		}

		if (!empty($transmissionIds)) {
			require_once dirname(__FILE__).'/event.class.php';
			$evObj = new LemonSuperPDPEvent($this->db);
			foreach ($transmissionIds as $tid) {
				$evObj->deleteAllForTransmission($tid);
			}
		}

		$sql = "DELETE FROM ".MAIN_DB_PREFIX."lemonsuperpdp_transmission";
		$sql .= " WHERE fk_facture = ".((int) $fkFacture);
		$sql .= " AND entity = ".((int) $conf->entity);

		dol_syslog(get_class($this)."::deleteAllForFacture fk_facture=".((int) $fkFacture), LOG_DEBUG);
		$resql = $this->db->query($sql);
		if ($resql) {
			return $this->db->affected_rows($resql);
		}
		$this->error = $this->db->lasterror();
		return -1;
	}

	/**
	 * Retourne le code de badge Dolibarr associé au statut courant (status0..status8).
	 */
	public function getBadgeClass()
	{
		switch ($this->status) {
			case self::STATUS_PENDING:  return 'badge-status0';
			case self::STATUS_SENT:     return 'badge-status1';
			case self::STATUS_ACCEPTED: return 'badge-status4';
			case self::STATUS_PAID:     return 'badge-status5';
			case self::STATUS_REFUSED:  return 'badge-status8';
			case self::STATUS_ERROR:    return 'badge-status8';
			default:                    return 'badge-status0';
		}
	}

	/**
	 * Retourne la clé de traduction du statut courant.
	 */
	public function getStatusLabelKey()
	{
		$map = array(
			self::STATUS_PENDING  => 'LemonSuperPDPStatusPending',
			self::STATUS_SENT     => 'LemonSuperPDPStatusSent',
			self::STATUS_ACCEPTED => 'LemonSuperPDPStatusAccepted',
			self::STATUS_REFUSED  => 'LemonSuperPDPStatusRefused',
			self::STATUS_PAID     => 'LemonSuperPDPStatusPaid',
			self::STATUS_ERROR    => 'LemonSuperPDPStatusError',
		);
		return isset($map[$this->status]) ? $map[$this->status] : 'LemonSuperPDPStatusPending';
	}
}
