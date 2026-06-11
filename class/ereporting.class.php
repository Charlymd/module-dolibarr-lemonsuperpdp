<?php
/*
 * Copyright (C) 2026 SASU LEMON <https://hellolemon.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Objet métier : une déclaration e-reporting B2C en file d'attente.
 *
 * La réforme impose de déclarer les données de transactions (e-reporting)
 * pour les ventes aux non-assujettis (B2C), ainsi que les données de
 * paiement. SUPER PDP expose POST /v1.beta/b2c_transactions et
 * /v1.beta/b2c_payments : la plateforme stocke, agrège et transmet au PPF
 * selon le régime de TVA configuré au niveau du compte SUPER PDP.
 *
 * Côté module : les triggers BILL_VALIDATE / BILL_PAYED sur les factures
 * B2C (tiers de type Particulier) alimentent cette file ; le cron
 * sendEreporting (ou le bouton « Transmettre maintenant ») pousse les
 * lignes pending par lots vers l'API.
 *
 * Codes catégorie Z12-012 : TLB1 (livraison de biens taxée), TPS1
 * (prestation de services taxée), TNT1 (transaction non taxée).
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

class LemonSuperPDPEreporting extends CommonObject
{
	public $element = 'lemonsuperpdp_ereporting';
	public $table_element = 'lemonsuperpdp_ereporting';

	public $rowid;
	public $entity;
	public $type;               // 'transaction' ou 'payment'
	public $fk_facture;
	public $fk_paiement;
	public $superpdp_id;
	public $status;
	public $payload;            // objet b2c_transaction ou b2c_payment (JSON)
	public $payload_response;
	public $error_message;
	public $date_creation;
	public $date_sent;
	public $tms;

	const TYPE_TRANSACTION = 'transaction';
	const TYPE_PAYMENT     = 'payment';

	const STATUS_PENDING = 'pending';
	const STATUS_SENT    = 'sent';
	const STATUS_ERROR   = 'error';

	/** Taille des lots envoyés à l'API. */
	const BATCH_SIZE = 100;

	public function __construct($db)
	{
		$this->db = $db;
	}

	private function _setFromRow($obj)
	{
		$this->id = $obj->rowid;
		$this->rowid = $obj->rowid;
		$this->entity = $obj->entity;
		$this->type = $obj->type;
		$this->fk_facture = $obj->fk_facture;
		$this->fk_paiement = $obj->fk_paiement;
		$this->superpdp_id = $obj->superpdp_id;
		$this->status = $obj->status;
		$this->payload = $obj->payload;
		$this->payload_response = $obj->payload_response;
		$this->error_message = $obj->error_message;
		$this->date_creation = $this->db->jdate($obj->date_creation);
		$this->date_sent = $this->db->jdate($obj->date_sent);
		$this->tms = $this->db->jdate($obj->tms);
	}

	public function create($user)
	{
		global $conf;
		$now = dol_now();

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."lemonsuperpdp_ereporting (";
		$sql .= "entity, type, fk_facture, fk_paiement, status, payload, date_creation";
		$sql .= ") VALUES (";
		$sql .= ((int) $conf->entity);
		$sql .= ", '".$this->db->escape($this->type)."'";
		$sql .= ", ".((int) $this->fk_facture);
		$sql .= ", ".(!empty($this->fk_paiement) ? ((int) $this->fk_paiement) : "NULL");
		$sql .= ", '".$this->db->escape(!empty($this->status) ? $this->status : self::STATUS_PENDING)."'";
		$sql .= ", ".($this->payload !== null ? "'".$this->db->escape($this->payload)."'" : "NULL");
		$sql .= ", '".$this->db->idate($now)."'";
		$sql .= ")";

		dol_syslog(get_class($this)."::create type=".$this->type." fk_facture=".((int) $this->fk_facture), LOG_DEBUG);
		$resql = $this->db->query($sql);
		if ($resql) {
			$this->id = $this->db->last_insert_id(MAIN_DB_PREFIX."lemonsuperpdp_ereporting");
			$this->rowid = $this->id;
			return $this->id;
		}
		$this->error = $this->db->lasterror();
		$this->errors[] = $this->error;
		return -1;
	}

	/**
	 * Vrai si la facture relève de l'e-reporting : tiers non assujetti à la
	 * TVA, qu'il soit particulier (typent 8) ou personne morale non assujettie
	 * (association...). Critère partagé avec le blocage d'envoi e-invoicing.
	 */
	public static function isB2CInvoice($facture)
	{
		if (empty($facture->thirdparty)) {
			$facture->fetch_thirdparty();
		}
		require_once dol_buildpath('/lemonsuperpdp/core/lib/lemonsuperpdp.lib.php');
		return lemonsuperpdp_is_non_assujetti($facture->thirdparty);
	}

	/**
	 * Vrai si une ligne d'e-reporting existe déjà pour cette facture et ce type
	 * (idempotence des triggers : dévalidation/revalidation, double paiement...).
	 */
	public static function existsForInvoice($db, $type, $fkFacture)
	{
		global $conf;
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."lemonsuperpdp_ereporting";
		$sql .= " WHERE fk_facture = ".((int) $fkFacture);
		$sql .= " AND type = '".$db->escape($type)."'";
		$sql .= " AND entity = ".((int) $conf->entity);
		$sql .= " AND status != '".self::STATUS_ERROR."'";
		$sql .= " LIMIT 1";
		$resql = $db->query($sql);
		if ($resql) {
			$found = ($db->num_rows($resql) > 0);
			$db->free($resql);
			return $found;
		}
		return false;
	}

	/**
	 * Code catégorie Z12-012 d'une ligne de facture Dolibarr.
	 * TNT1 si non taxée (taux 0), sinon TPS1 pour un service, TLB1 pour un bien.
	 */
	private static function lineCategoryCode($line)
	{
		if ((float) $line->tva_tx == 0.0) return 'TNT1';
		return ((int) ($line->product_type ?? 0) === 1) ? 'TPS1' : 'TLB1';
	}

	/**
	 * Met en file les transactions B2C d'une facture validée : une ligne
	 * d'e-reporting par code catégorie présent dans la facture (l'API
	 * n'accepte qu'un category_code par transaction), avec ventilation des
	 * montants HT/TVA par taux.
	 *
	 * @param DoliDB  $db
	 * @param Facture $facture  Facture validée (fetch_lines fait si besoin)
	 * @param User    $user
	 * @return int  Nombre de lignes mises en file (0 si déjà fait), -1 si erreur
	 */
	public static function queueTransactionForInvoice($db, $facture, $user)
	{
		global $conf;

		if (self::existsForInvoice($db, self::TYPE_TRANSACTION, $facture->id)) {
			return 0;
		}
		if (empty($facture->lines)) {
			$facture->fetch_lines();
		}

		// Avoir : montants déclarés en négatif (la facture porte des lignes
		// positives, le sens est donné par le type 2 = avoir).
		$sign = ((int) $facture->type === 2) ? -1 : 1;

		// Groupe par catégorie, puis par taux dans chaque catégorie.
		$groups = array();
		foreach ($facture->lines as $line) {
			if ((float) $line->qty == 0 && (float) $line->total_ht == 0) continue;
			$cat = self::lineCategoryCode($line);
			$rateKey = number_format((float) $line->tva_tx, 1, '.', '');
			if (!isset($groups[$cat][$rateKey])) {
				$groups[$cat][$rateKey] = array('ht' => 0.0, 'tva' => 0.0);
			}
			$groups[$cat][$rateKey]['ht'] += $sign * (float) $line->total_ht;
			$groups[$cat][$rateKey]['tva'] += $sign * (float) $line->total_tva;
		}
		if (empty($groups)) return 0;

		$dateStr = dol_print_date(!empty($facture->date) ? $facture->date : dol_now(), '%Y-%m-%d');
		$currency = !empty($facture->multicurrency_code) ? $facture->multicurrency_code : (!empty($conf->currency) ? $conf->currency : 'EUR');

		$nb = 0;
		foreach ($groups as $cat => $rates) {
			$subtotals = array();
			$totalHt = 0.0;
			$totalTva = 0.0;
			foreach ($rates as $rateKey => $amounts) {
				$subtotals[] = array(
					'tax_percent' => $rateKey,
					'taxable_amount' => number_format($amounts['ht'], 2, '.', ''),
					'tax_total' => number_format($amounts['tva'], 2, '.', ''),
				);
				$totalHt += $amounts['ht'];
				$totalTva += $amounts['tva'];
			}
			$payload = array(
				'date' => $dateStr,
				'currency' => $currency,
				'category_code' => $cat,
				'role_code' => 'SE',
				'tax_exclusive_amount' => number_format($totalHt, 2, '.', ''),
				'tax_total' => number_format($totalTva, 2, '.', ''),
				'tax_subtotals' => $subtotals,
			);

			$row = new self($db);
			$row->type = self::TYPE_TRANSACTION;
			$row->fk_facture = (int) $facture->id;
			$row->payload = json_encode($payload);
			if ($row->create($user) > 0) {
				$nb++;
			} else {
				dol_syslog('LemonSuperPDPEreporting::queueTransactionForInvoice insert KO : '.$row->error, LOG_ERR);
				return -1;
			}
		}
		return $nb;
	}

	/**
	 * Met en file la déclaration de paiement B2C d'une facture soldée :
	 * sous-totaux TTC ventilés par catégorie et taux de TVA.
	 *
	 * @param DoliDB  $db
	 * @param Facture $facture      Facture payée
	 * @param string  $paymentDate  Date du paiement (Y-m-d)
	 * @param User    $user
	 * @return int  1 mis en file, 0 si déjà fait, -1 si erreur
	 */
	public static function queuePaymentForInvoice($db, $facture, $paymentDate, $user)
	{
		global $conf;

		if (self::existsForInvoice($db, self::TYPE_PAYMENT, $facture->id)) {
			return 0;
		}
		if (empty($facture->lines)) {
			$facture->fetch_lines();
		}

		$sign = ((int) $facture->type === 2) ? -1 : 1;
		$currency = !empty($facture->multicurrency_code) ? $facture->multicurrency_code : (!empty($conf->currency) ? $conf->currency : 'EUR');

		$groups = array();
		foreach ($facture->lines as $line) {
			if ((float) $line->qty == 0 && (float) $line->total_ht == 0) continue;
			$cat = self::lineCategoryCode($line);
			$rateKey = number_format((float) $line->tva_tx, 1, '.', '');
			if (!isset($groups[$cat][$rateKey])) {
				$groups[$cat][$rateKey] = 0.0;
			}
			$groups[$cat][$rateKey] += $sign * (float) $line->total_ttc;
		}
		if (empty($groups)) return 0;

		$subtotals = array();
		foreach ($groups as $cat => $rates) {
			foreach ($rates as $rateKey => $ttc) {
				$subtotals[] = array(
					'category_code' => $cat,
					'tax_percent' => $rateKey,
					'amount' => number_format($ttc, 2, '.', ''),
					'currency_code' => $currency,
				);
			}
		}

		$payload = array(
			'date' => $paymentDate,
			'subtotals' => $subtotals,
		);

		$row = new self($db);
		$row->type = self::TYPE_PAYMENT;
		$row->fk_facture = (int) $facture->id;
		$row->payload = json_encode($payload);
		if ($row->create($user) > 0) {
			return 1;
		}
		dol_syslog('LemonSuperPDPEreporting::queuePaymentForInvoice insert KO : '.$row->error, LOG_ERR);
		return -1;
	}

	/**
	 * Pousse les lignes pending vers l'API SUPER PDP, par lots et par type.
	 *
	 * En cas d'erreur 4xx (payload refusé) les lignes du lot passent en
	 * error ; pour toute autre erreur (réseau, 5xx) elles restent pending
	 * avec le message noté, et seront retentées à la prochaine passe.
	 *
	 * @param DoliDB              $db
	 * @param User                $user
	 * @param SuperPDPClient|null $client
	 * @return array{sent:int, errors:int, pending:int}
	 */
	public static function processPending($db, $user, $client = null)
	{
		global $conf;

		if ($client === null) {
			dol_include_once('/lemonsuperpdp/class/superpdp_client.class.php');
			$client = new SuperPDPClient($db);
		}

		$sent = 0;
		$errors = 0;
		$pendingLeft = 0;

		foreach (array(self::TYPE_TRANSACTION, self::TYPE_PAYMENT) as $type) {
			$sql = "SELECT rowid, payload FROM ".MAIN_DB_PREFIX."lemonsuperpdp_ereporting";
			$sql .= " WHERE status = '".self::STATUS_PENDING."'";
			$sql .= " AND type = '".$db->escape($type)."'";
			$sql .= " AND entity = ".((int) $conf->entity);
			$sql .= " ORDER BY rowid ASC";

			$resql = $db->query($sql);
			if (!$resql) continue;
			$rows = array();
			while ($obj = $db->fetch_object($resql)) {
				$payload = json_decode((string) $obj->payload, true);
				if (is_array($payload)) {
					$rows[] = array('rowid' => (int) $obj->rowid, 'payload' => $payload);
				}
			}
			$db->free($resql);
			if (empty($rows)) continue;

			foreach (array_chunk($rows, self::BATCH_SIZE) as $batch) {
				$payloads = array_map(function ($r) {
					return $r['payload'];
				}, $batch);

				try {
					$response = ($type === self::TYPE_TRANSACTION)
						? $client->createB2CTransactions($payloads)
						: $client->createB2CPayments($payloads);
				} catch (SuperPDPException $e) {
					$isPayloadError = ($e->httpCode >= 400 && $e->httpCode < 500);
					$newStatus = $isPayloadError ? self::STATUS_ERROR : self::STATUS_PENDING;
					foreach ($batch as $r) {
						$upd = "UPDATE ".MAIN_DB_PREFIX."lemonsuperpdp_ereporting";
						$upd .= " SET status = '".$newStatus."'";
						$upd .= ", error_message = '".$db->escape(dol_trunc($e->getMessage(), 1500))."'";
						$upd .= " WHERE rowid = ".((int) $r['rowid']);
						$db->query($upd);
					}
					if ($isPayloadError) {
						$errors += count($batch);
					} else {
						$pendingLeft += count($batch);
					}
					dol_syslog('LemonSuperPDPEreporting::processPending lot '.$type.' KO : '.$e->getMessage(), LOG_ERR);
					continue;
				}

				// Mappe les ids retournés sur les lignes du lot (même ordre).
				$respData = (!empty($response['data']) && is_array($response['data'])) ? array_values($response['data']) : array();
				$now = dol_now();
				foreach ($batch as $idx => $r) {
					$superpdpId = isset($respData[$idx]['id']) ? (int) $respData[$idx]['id'] : 0;
					$upd = "UPDATE ".MAIN_DB_PREFIX."lemonsuperpdp_ereporting";
					$upd .= " SET status = '".self::STATUS_SENT."'";
					$upd .= ", superpdp_id = ".($superpdpId > 0 ? $superpdpId : "NULL");
					$upd .= ", payload_response = ".(isset($respData[$idx]) ? "'".$db->escape(json_encode($respData[$idx]))."'" : "NULL");
					$upd .= ", error_message = NULL";
					$upd .= ", date_sent = '".$db->idate($now)."'";
					$upd .= " WHERE rowid = ".((int) $r['rowid']);
					$db->query($upd);
					$sent++;
				}
			}
		}

		return array('sent' => $sent, 'errors' => $errors, 'pending' => $pendingLeft);
	}

	/**
	 * Classe CSS du badge Dolibarr selon le statut.
	 */
	public function getBadgeClass()
	{
		switch ($this->status) {
			case self::STATUS_PENDING: return 'badge-status1';
			case self::STATUS_SENT:    return 'badge-status4';
			case self::STATUS_ERROR:   return 'badge-status8';
			default:                   return 'badge-status0';
		}
	}

	/**
	 * Clé de traduction du statut courant.
	 */
	public function getStatusLabelKey()
	{
		$map = array(
			self::STATUS_PENDING => 'LemonSuperPDPEreportingStatusPending',
			self::STATUS_SENT    => 'LemonSuperPDPEreportingStatusSent',
			self::STATUS_ERROR   => 'LemonSuperPDPEreportingStatusError',
		);
		return isset($map[$this->status]) ? $map[$this->status] : 'LemonSuperPDPEreportingStatusPending';
	}
}
