<?php
/*
 * Copyright (C) 2026 SASU LEMON <https://hellolemon.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Objet métier : une facture fournisseur reçue via SUPER PDP (direction=in).
 *
 * Le cycle : le polling (cron ou bouton) liste GET /v1.beta/invoices?direction=in
 * avec le modèle EN16931 structuré (en_invoice) déjà parsé par la PA, enregistre
 * chaque facture ici, tente de rattacher le tiers fournisseur par SIREN/SIRET,
 * et si le rattachement est sans ambiguïté crée une FactureFournisseur Dolibarr
 * en BROUILLON (jamais auto-validée : l'examen humain reste obligatoire) avec
 * le fichier original (PDF Factur-X ou XML) attaché.
 *
 * Sans correspondance de tiers, la réception reste en quarantaine : l'écran
 * "Factur-X reçues" permet de choisir le tiers et d'importer manuellement.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

class LemonSuperPDPReception extends CommonObject
{
	public $element = 'lemonsuperpdp_reception';
	public $table_element = 'lemonsuperpdp_reception';

	public $rowid;
	public $entity;
	public $superpdp_id;
	public $source;              // 'pa' (polling plateforme) ou 'manual' (upload)
	public $fk_facture_fourn;
	public $fk_soc;
	public $supplier_name;
	public $supplier_siren;
	public $supplier_vat;
	public $invoice_number;
	public $invoice_type_code;
	public $invoice_date;
	public $due_date;
	public $total_ht;
	public $total_ttc;
	public $currency_code;
	public $status;
	public $lifecycle_status;    // dernier statut AFNOR émis côté acheteur (fr:206, fr:210...)
	public $error_message;
	public $payload_raw;
	public $date_fetched;
	public $date_imported;
	public $tms;
	public $fk_user_import;

	const STATUS_NEW        = 'new';         // reçue, tiers non résolu/import pas encore tenté
	const STATUS_IMPORTED   = 'imported';    // FactureFournisseur brouillon créée
	const STATUS_QUARANTINE = 'quarantine';  // tiers introuvable ou ambigu, action manuelle requise
	const STATUS_IGNORED    = 'ignored';     // écartée volontairement par l'utilisateur
	const STATUS_ERROR      = 'error';       // échec technique à l'import

	const SOURCE_PA     = 'pa';
	const SOURCE_MANUAL = 'manual';

	/** Pagination du polling : garde-fou contre une réponse API incohérente. */
	const MAX_PAGES = 50;

	public function __construct($db)
	{
		$this->db = $db;
	}

	private function _setFromRow($obj)
	{
		$this->id = $obj->rowid;
		$this->rowid = $obj->rowid;
		$this->entity = $obj->entity;
		$this->superpdp_id = $obj->superpdp_id;
		$this->source = $obj->source;
		$this->fk_facture_fourn = $obj->fk_facture_fourn;
		$this->fk_soc = $obj->fk_soc;
		$this->supplier_name = $obj->supplier_name;
		$this->supplier_siren = $obj->supplier_siren;
		$this->supplier_vat = $obj->supplier_vat;
		$this->invoice_number = $obj->invoice_number;
		$this->invoice_type_code = $obj->invoice_type_code;
		$this->invoice_date = $this->db->jdate($obj->invoice_date);
		$this->due_date = $this->db->jdate($obj->due_date);
		$this->total_ht = $obj->total_ht;
		$this->total_ttc = $obj->total_ttc;
		$this->currency_code = $obj->currency_code;
		$this->status = $obj->status;
		$this->lifecycle_status = $obj->lifecycle_status;
		$this->error_message = $obj->error_message;
		$this->payload_raw = $obj->payload_raw;
		$this->date_fetched = $this->db->jdate($obj->date_fetched);
		$this->date_imported = $this->db->jdate($obj->date_imported);
		$this->tms = $this->db->jdate($obj->tms);
		$this->fk_user_import = $obj->fk_user_import;
	}

	public function create($user)
	{
		global $conf;
		$now = dol_now();

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."lemonsuperpdp_reception (";
		$sql .= "entity, superpdp_id, source, fk_soc, supplier_name, supplier_siren, supplier_vat,";
		$sql .= " invoice_number, invoice_type_code, invoice_date, due_date, total_ht, total_ttc,";
		$sql .= " currency_code, status, error_message, payload_raw, date_fetched";
		$sql .= ") VALUES (";
		$sql .= ((int) $conf->entity);
		$sql .= ", ".(!empty($this->superpdp_id) ? ((int) $this->superpdp_id) : "NULL");
		$sql .= ", '".$this->db->escape(!empty($this->source) ? $this->source : self::SOURCE_PA)."'";
		$sql .= ", ".(!empty($this->fk_soc) ? ((int) $this->fk_soc) : "NULL");
		$sql .= ", ".($this->supplier_name !== null ? "'".$this->db->escape(dol_trunc($this->supplier_name, 252))."'" : "NULL");
		$sql .= ", ".($this->supplier_siren !== null ? "'".$this->db->escape($this->supplier_siren)."'" : "NULL");
		$sql .= ", ".($this->supplier_vat !== null ? "'".$this->db->escape($this->supplier_vat)."'" : "NULL");
		$sql .= ", ".($this->invoice_number !== null ? "'".$this->db->escape(dol_trunc($this->invoice_number, 61))."'" : "NULL");
		$sql .= ", ".($this->invoice_type_code !== null ? "'".$this->db->escape($this->invoice_type_code)."'" : "NULL");
		$sql .= ", ".(!empty($this->invoice_date) ? "'".$this->db->idate($this->invoice_date)."'" : "NULL");
		$sql .= ", ".(!empty($this->due_date) ? "'".$this->db->idate($this->due_date)."'" : "NULL");
		$sql .= ", ".($this->total_ht !== null ? ((float) $this->total_ht) : "NULL");
		$sql .= ", ".($this->total_ttc !== null ? ((float) $this->total_ttc) : "NULL");
		$sql .= ", ".($this->currency_code !== null ? "'".$this->db->escape($this->currency_code)."'" : "NULL");
		$sql .= ", '".$this->db->escape(!empty($this->status) ? $this->status : self::STATUS_NEW)."'";
		$sql .= ", ".($this->error_message !== null ? "'".$this->db->escape($this->error_message)."'" : "NULL");
		$sql .= ", ".($this->payload_raw !== null ? "'".$this->db->escape($this->payload_raw)."'" : "NULL");
		$sql .= ", '".$this->db->idate($now)."'";
		$sql .= ")";

		dol_syslog(get_class($this)."::create superpdp_id=".((int) $this->superpdp_id), LOG_DEBUG);
		$resql = $this->db->query($sql);
		if ($resql) {
			$this->id = $this->db->last_insert_id(MAIN_DB_PREFIX."lemonsuperpdp_reception");
			$this->rowid = $this->id;
			$this->date_fetched = $now;
			return $this->id;
		}
		$this->error = $this->db->lasterror();
		$this->errors[] = $this->error;
		return -1;
	}

	public function fetch($id)
	{
		$sql = "SELECT * FROM ".MAIN_DB_PREFIX."lemonsuperpdp_reception";
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

	public function update($user)
	{
		$sql = "UPDATE ".MAIN_DB_PREFIX."lemonsuperpdp_reception SET";
		$sql .= " fk_facture_fourn = ".(!empty($this->fk_facture_fourn) ? ((int) $this->fk_facture_fourn) : "NULL");
		$sql .= ", fk_soc = ".(!empty($this->fk_soc) ? ((int) $this->fk_soc) : "NULL");
		$sql .= ", status = '".$this->db->escape($this->status)."'";
		$sql .= ", lifecycle_status = ".(!empty($this->lifecycle_status) ? "'".$this->db->escape($this->lifecycle_status)."'" : "NULL");
		$sql .= ", error_message = ".($this->error_message !== null ? "'".$this->db->escape($this->error_message)."'" : "NULL");
		$sql .= ", date_imported = ".(!empty($this->date_imported) ? "'".$this->db->idate($this->date_imported)."'" : "NULL");
		$sql .= ", fk_user_import = ".(!empty($this->fk_user_import) ? ((int) $this->fk_user_import) : "NULL");
		$sql .= " WHERE rowid = ".((int) $this->id);

		dol_syslog(get_class($this)."::update", LOG_DEBUG);
		$resql = $this->db->query($sql);
		if ($resql) return 1;
		$this->error = $this->db->lasterror();
		return -1;
	}

	/**
	 * Retourne true si cette facture SUPER PDP a déjà été enregistrée.
	 */
	public function existsBySuperpdpId($superpdpId)
	{
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."lemonsuperpdp_reception";
		$sql .= " WHERE superpdp_id = ".((int) $superpdpId);
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
	 * Polling des factures reçues : itère sur GET /v1.beta/invoices?direction=in
	 * avec le curseur LEMONSUPERPDP_LAST_IN_ID, enregistre les nouvelles
	 * réceptions et importe automatiquement celles dont le tiers est résolu
	 * sans ambiguïté.
	 *
	 * @param DoliDB              $db      Connexion base
	 * @param User                $user    Utilisateur courant (cron ou web)
	 * @param SuperPDPClient|null $client  Client API (instancié si null)
	 * @return array{fetched:int, imported:int, quarantined:int, errors:int, lastId:int}
	 * @throws SuperPDPException
	 */
	public static function syncIncoming($db, $user, $client = null)
	{
		if ($client === null) {
			dol_include_once('/lemonsuperpdp/class/superpdp_client.class.php');
			$client = new SuperPDPClient($db);
		}

		$initialLastId = (int) getDolGlobalString('LEMONSUPERPDP_LAST_IN_ID', '0');
		$lastId = $initialLastId;
		$nbFetched = 0;
		$nbImported = 0;
		$nbQuarantined = 0;
		$nbErrors = 0;
		$nbPages = 0;

		$probe = new self($db);

		$hasAfter = true;
		while ($hasAfter && $nbPages < self::MAX_PAGES) {
			$nbPages++;
			$resp = $client->listIncomingInvoices($lastId);
			$data = !empty($resp['data']) && is_array($resp['data']) ? $resp['data'] : array();
			$hasAfter = !empty($resp['has_after']);

			foreach ($data as $inv) {
				if (empty($inv['id'])) continue;
				$lastId = max($lastId, (int) $inv['id']);

				if ($probe->existsBySuperpdpId((int) $inv['id'])) continue;

				$rec = self::buildFromApiInvoice($db, $inv);
				$nbFetched++;

				// Résolution du tiers fournisseur par SIREN/SIRET
				$socid = $rec->resolveThirdparty();
				if ($socid > 0) {
					$rec->fk_soc = $socid;
				} else {
					$rec->status = self::STATUS_QUARANTINE;
					$rec->error_message = ($socid === -1)
						? 'Plusieurs tiers Dolibarr correspondent au SIREN '.$rec->supplier_siren
						: 'Aucun tiers Dolibarr ne correspond (SIREN '.($rec->supplier_siren !== null ? $rec->supplier_siren : 'absent').')';
				}

				if ($rec->create($user) <= 0) {
					dol_syslog('LemonSuperPDPReception::syncIncoming insert failed : '.$rec->error, LOG_ERR);
					$nbErrors++;
					continue;
				}

				if ($rec->status === self::STATUS_QUARANTINE) {
					$nbQuarantined++;
					continue;
				}

				$ret = $rec->importAsSupplierInvoice($user, $client);
				if ($ret > 0) {
					$nbImported++;
				} else {
					$nbErrors++;
				}
			}
		}

		// Écriture du curseur uniquement en cas d'avancement.
		if ($lastId !== $initialLastId) {
			dolibarr_set_const($db, 'LEMONSUPERPDP_LAST_IN_ID', (string) $lastId, 'chaine', 0, '', 0);
		}

		return array(
			'fetched' => $nbFetched,
			'imported' => $nbImported,
			'quarantined' => $nbQuarantined,
			'errors' => $nbErrors,
			'lastId' => $lastId,
		);
	}

	/**
	 * Construit une réception (non persistée) depuis un objet invoice de l'API
	 * (invoice_overview avec en_invoice expandé).
	 *
	 * @param DoliDB $db
	 * @param array  $inv  Objet invoice de l'API
	 * @return self
	 */
	public static function buildFromApiInvoice($db, array $inv)
	{
		$en = !empty($inv['en_invoice']) && is_array($inv['en_invoice']) ? $inv['en_invoice'] : array();
		$seller = !empty($en['seller']) && is_array($en['seller']) ? $en['seller'] : array();
		$totals = !empty($en['totals']) && is_array($en['totals']) ? $en['totals'] : array();

		$rec = new self($db);
		$rec->superpdp_id = (int) $inv['id'];
		$rec->status = self::STATUS_NEW;
		$rec->supplier_name = isset($seller['name']) ? (string) $seller['name'] : null;
		$rec->supplier_vat = isset($seller['vat_identifier']) ? (string) $seller['vat_identifier'] : null;
		$rec->supplier_siren = self::extractSirenFromParty($seller);
		$rec->invoice_number = isset($en['number']) ? (string) $en['number'] : null;
		$rec->invoice_type_code = isset($en['type_code']) ? (string) $en['type_code'] : null;
		$rec->invoice_date = !empty($en['issue_date']) ? strtotime($en['issue_date'].' 12:00:00 UTC') : null;
		$rec->due_date = !empty($en['payment_due_date']) ? strtotime($en['payment_due_date'].' 12:00:00 UTC') : null;
		$rec->total_ht = isset($totals['total_without_vat']) ? (float) $totals['total_without_vat'] : null;
		$rec->total_ttc = isset($totals['total_with_vat']) ? (float) $totals['total_with_vat'] : null;
		$rec->currency_code = isset($en['currency_code']) ? (string) $en['currency_code'] : null;
		$rec->payload_raw = json_encode($inv);

		return $rec;
	}

	/**
	 * Extrait un SIREN (9 chiffres) d'un bloc seller/buyer EN16931.
	 * Sources par ordre de priorité : legal_registration_identifier
	 * (scheme 0002 = SIREN, 0009 = SIRET), identifiers[], electronic_address
	 * (scheme 0225 = SIREN annuaire PA), puis vat_identifier FR (FRkk + SIREN).
	 *
	 * @param array $party  Bloc seller ou buyer
	 * @return string|null  SIREN à 9 chiffres ou null
	 */
	public static function extractSirenFromParty(array $party)
	{
		$candidates = array();
		if (!empty($party['legal_registration_identifier']['value'])) {
			$candidates[] = (string) $party['legal_registration_identifier']['value'];
		}
		if (!empty($party['identifiers']) && is_array($party['identifiers'])) {
			foreach ($party['identifiers'] as $ident) {
				if (!empty($ident['value'])) $candidates[] = (string) $ident['value'];
			}
		}
		if (!empty($party['electronic_address']['value']) && !empty($party['electronic_address']['scheme'])
			&& in_array((string) $party['electronic_address']['scheme'], array('0225', '0002', '0009'), true)) {
			$candidates[] = (string) $party['electronic_address']['value'];
		}
		foreach ($candidates as $cand) {
			$digits = preg_replace('/[^0-9]/', '', $cand);
			if (strlen($digits) === 9 || strlen($digits) === 14) {
				return substr($digits, 0, 9);
			}
		}
		// TVA intra FR : FR + 2 caractères de clé + SIREN
		if (!empty($party['vat_identifier']) && preg_match('/^FR[0-9A-Z]{2}([0-9]{9})$/i', trim((string) $party['vat_identifier']), $m)) {
			return $m[1];
		}
		return null;
	}

	/**
	 * Cherche le tiers Dolibarr correspondant au SIREN extrait.
	 * Match sur idprof1 (SIREN exact) ou idprof2 (SIRET commençant par le SIREN).
	 *
	 * @return int  socid si correspondance unique, 0 si aucune, -1 si plusieurs
	 */
	public function resolveThirdparty()
	{
		if (empty($this->supplier_siren)) return 0;

		$siren = preg_replace('/[^0-9]/', '', $this->supplier_siren);
		if (strlen($siren) !== 9) return 0;

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."societe";
		$sql .= " WHERE entity IN (".getEntity('societe').")";
		$sql .= " AND (REPLACE(idprof1, ' ', '') = '".$this->db->escape($siren)."'";
		$sql .= " OR REPLACE(idprof2, ' ', '') LIKE '".$this->db->escape($siren)."%')";
		$sql .= " LIMIT 3";

		$resql = $this->db->query($sql);
		if (!$resql) return 0;
		$ids = array();
		while ($row = $this->db->fetch_object($resql)) {
			$ids[] = (int) $row->rowid;
		}
		$this->db->free($resql);

		if (count($ids) === 1) return $ids[0];
		if (count($ids) > 1) return -1;
		return 0;
	}

	/**
	 * Crée la FactureFournisseur Dolibarr en brouillon depuis le payload
	 * en_invoice, télécharge le fichier original et l'attache.
	 *
	 * @param User                $user         Utilisateur qui importe
	 * @param SuperPDPClient|null $client       Client API pour le téléchargement (instancié si null)
	 * @param int                 $socid        Tiers forcé (0 = utiliser fk_soc résolu)
	 * @param string|null         $localContent Contenu du fichier original pour un import
	 *                                          manuel (sinon téléchargé depuis la PA)
	 * @return int  ID FactureFournisseur créée, ou -1 (l'erreur est posée sur la ligne)
	 */
	public function importAsSupplierInvoice($user, $client = null, $socid = 0, $localContent = null)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

		$targetSoc = ($socid > 0) ? $socid : (int) $this->fk_soc;
		if ($targetSoc <= 0) {
			return $this->failImport($user, 'Aucun tiers fournisseur résolu');
		}

		$inv = json_decode((string) $this->payload_raw, true);
		$en = !empty($inv['en_invoice']) && is_array($inv['en_invoice']) ? $inv['en_invoice'] : array();
		if (empty($en['lines']) || !is_array($en['lines'])) {
			return $this->failImport($user, 'Payload en_invoice sans lignes');
		}

		// Devise : on n'importe que dans la devise de l'instance (les montants
		// seraient faux sinon). Les factures en devise étrangère restent en
		// quarantaine pour saisie manuelle.
		$instanceCurrency = !empty($conf->currency) ? $conf->currency : 'EUR';
		if (!empty($this->currency_code) && strtoupper($this->currency_code) !== strtoupper($instanceCurrency)) {
			$this->status = self::STATUS_QUARANTINE;
			$this->error_message = 'Devise '.$this->currency_code.' différente de la devise de l\'instance ('.$instanceCurrency.')';
			$this->update($user);
			return -1;
		}

		$this->db->begin();

		$ff = new FactureFournisseur($this->db);
		$ff->socid = $targetSoc;
		$ff->type = ((string) $this->invoice_type_code === '381') ? FactureFournisseur::TYPE_CREDIT_NOTE : FactureFournisseur::TYPE_STANDARD;
		$ff->ref_supplier = !empty($this->invoice_number) ? $this->invoice_number : 'SUPERPDP-'.((int) $this->superpdp_id);
		$ff->date = !empty($this->invoice_date) ? $this->invoice_date : dol_now();
		$ff->date_echeance = !empty($this->due_date) ? $this->due_date : '';
		$ff->note_private = ($this->source === self::SOURCE_MANUAL)
			? 'Importée manuellement (fichier Factur-X/XML converti via SUPER PDP). À vérifier avant validation.'
			: 'Importée automatiquement depuis SUPER PDP (facture plateforme n° '.((int) $this->superpdp_id).'). À vérifier avant validation.';

		$ffid = $ff->create($user);
		if ($ffid <= 0) {
			$this->db->rollback();
			return $this->failImport($user, 'Création FactureFournisseur : '.$ff->error);
		}

		$nbLinesKo = 0;
		foreach ($en['lines'] as $line) {
			if ($this->addSupplierInvoiceLine($ff, $line) < 0) $nbLinesKo++;
		}

		// Remises et frais de pied de document (BG-20/BG-21) : une ligne chacun.
		foreach (array('document_level_allowances' => -1, 'document_level_charges' => 1) as $key => $sign) {
			if (empty($en[$key]) || !is_array($en[$key])) continue;
			foreach ($en[$key] as $aoc) {
				$amount = isset($aoc['amount']) ? (float) $aoc['amount'] : 0.0;
				if ($amount == 0.0) continue;
				$desc = !empty($aoc['reason']) ? (string) $aoc['reason'] : ($sign < 0 ? 'Remise document' : 'Frais document');
				$rate = isset($aoc['vat_rate']) ? (float) $aoc['vat_rate'] : 0.0;
				$ret = $ff->addline($desc, $sign * $amount, $rate, 0, 0, 1);
				if ($ret < 0) $nbLinesKo++;
			}
		}

		if ($nbLinesKo > 0) {
			$this->db->rollback();
			return $this->failImport($user, $nbLinesKo.' ligne(s) en échec à la création : '.$ff->error);
		}

		// Contrôle de cohérence sur le total TTC annoncé par le XML.
		$ff->fetch($ffid);
		$warn = '';
		if ($this->total_ttc !== null && abs((float) $ff->total_ttc - (float) $this->total_ttc) > 0.02) {
			$warn = 'Écart de total TTC : XML '.price2num($this->total_ttc).' vs Dolibarr '.price2num($ff->total_ttc).'. Vérifier les lignes.';
			$this->db->query("UPDATE ".MAIN_DB_PREFIX."facture_fourn SET note_private = CONCAT(IFNULL(note_private, ''), '\n".$this->db->escape($warn)."') WHERE rowid = ".((int) $ffid));
		}

		$this->db->commit();

		// Téléchargement et attache du fichier original (non bloquant : la
		// facture brouillon existe déjà, une erreur réseau ne doit pas la perdre).
		$attachError = '';
		try {
			if ($localContent === null && $client === null) {
				dol_include_once('/lemonsuperpdp/class/superpdp_client.class.php');
				$client = new SuperPDPClient($this->db);
			}
			$this->attachOriginalFile($ff, $client, $localContent);
		} catch (Exception $e) {
			$attachError = 'Fichier original non attaché : '.$e->getMessage();
			dol_syslog('LemonSuperPDPReception::importAsSupplierInvoice attach : '.$e->getMessage(), LOG_WARNING);
		}

		$this->fk_soc = $targetSoc;
		$this->fk_facture_fourn = $ffid;
		$this->status = self::STATUS_IMPORTED;
		$this->error_message = trim($warn.($warn !== '' && $attachError !== '' ? ' — ' : '').$attachError);
		if ($this->error_message === '') $this->error_message = null;
		$this->date_imported = dol_now();
		$this->fk_user_import = (int) $user->id;
		$this->update($user);

		return $ffid;
	}

	/**
	 * Ajoute une ligne EN16931 à la FactureFournisseur.
	 * Prix unitaire = item_net_price ramené à la quantité de base
	 * (item_price_base_quantity, défaut 1). BR-27 garantit un prix >= 0,
	 * le signe d'une ligne négative est porté par la quantité.
	 *
	 * @return int  >0 OK, <0 KO
	 */
	private function addSupplierInvoiceLine($ff, array $line)
	{
		$item = !empty($line['item_information']) && is_array($line['item_information']) ? $line['item_information'] : array();
		$price = !empty($line['price_details']) && is_array($line['price_details']) ? $line['price_details'] : array();
		$vat = !empty($line['vat_information']) && is_array($line['vat_information']) ? $line['vat_information'] : array();

		$desc = !empty($item['name']) ? (string) $item['name'] : 'Article';
		if (!empty($item['description']) && (string) $item['description'] !== $desc) {
			$desc .= "\n".(string) $item['description'];
		}

		$qty = isset($line['invoiced_quantity']) ? (float) $line['invoiced_quantity'] : 1.0;
		if ($qty == 0.0) $qty = 1.0;

		$baseQty = (isset($price['item_price_base_quantity']) && (float) $price['item_price_base_quantity'] != 0.0)
			? (float) $price['item_price_base_quantity'] : 1.0;
		$pu = isset($price['item_net_price']) ? ((float) $price['item_net_price']) / $baseQty : 0.0;

		// Sécurité d'arrondi : si qty × pu s'écarte du net_amount annoncé,
		// on recale le prix unitaire sur net_amount/qty (source de vérité BT-131).
		if (isset($line['net_amount'])) {
			$net = (float) $line['net_amount'];
			if (abs(($qty * $pu) - $net) > 0.01) {
				$pu = $net / $qty;
			}
		}

		$rate = isset($vat['invoiced_item_vat_rate']) ? (float) $vat['invoiced_item_vat_rate'] : 0.0;

		return $ff->addline($desc, $pu, $rate, 0, 0, $qty);
	}

	/**
	 * Télécharge le fichier original depuis la PA et l'attache au répertoire
	 * documents de la facture fournisseur. Le nom évite volontairement
	 * {ref}.pdf, réservé au PDF généré par Dolibarr (écrasé au premier
	 * « Générer »).
	 *
	 * @throws SuperPDPException|Exception
	 */
	private function attachOriginalFile($ff, $client, $localContent = null)
	{
		global $conf;

		if ($localContent !== null) {
			$content = $localContent;
		} elseif (!empty($this->superpdp_id)) {
			$content = $client->downloadInvoice((int) $this->superpdp_id);
		} else {
			return;
		}
		$ext = (strpos($content, '%PDF') === 0) ? 'pdf' : 'xml';

		$upload_dir = $conf->fournisseur->facture->dir_output.'/'.get_exdir($ff->id, 2, 0, 0, $ff, 'invoice_supplier').dol_sanitizeFileName($ff->ref);
		if (!dol_is_dir($upload_dir)) {
			if (dol_mkdir($upload_dir) < 0) {
				throw new Exception('Impossible de créer le répertoire documents');
			}
		}

		$base = !empty($this->invoice_number) ? $this->invoice_number : (!empty($this->superpdp_id) ? 'superpdp-'.((int) $this->superpdp_id) : 'import-manuel-'.((int) $this->id));
		$filename = dol_sanitizeFileName($base.'-recue-superpdp.'.$ext);
		$filepath = $upload_dir.'/'.$filename;

		if (file_put_contents($filepath, $content) === false) {
			throw new Exception('Écriture du fichier impossible');
		}
		dolChmod($filepath);
	}

	/**
	 * Pose le statut error + message et retourne -1.
	 */
	private function failImport($user, $message)
	{
		$this->status = self::STATUS_ERROR;
		$this->error_message = dol_trunc($message, 1500);
		if (!empty($this->id)) {
			$this->update($user);
		}
		dol_syslog('LemonSuperPDPReception::importAsSupplierInvoice : '.$message, LOG_ERR);
		return -1;
	}

	/**
	 * Import manuel d'un fichier Factur-X (PDF) ou XML (CII/UBL) : conversion
	 * en en_invoice via la PA (POST /invoices/convert), création de la
	 * réception (source=manual) puis import en FactureFournisseur brouillon
	 * si le tiers est résolu, quarantaine sinon.
	 *
	 * @param DoliDB              $db       Connexion base
	 * @param User                $user     Utilisateur qui importe
	 * @param string              $content  Contenu brut du fichier uploadé
	 * @param SuperPDPClient|null $client   Client API (instancié si null)
	 * @return self  La réception créée (consulter status/error_message)
	 * @throws SuperPDPException
	 */
	public static function createFromManualUpload($db, $user, $content, $client = null)
	{
		if ($client === null) {
			dol_include_once('/lemonsuperpdp/class/superpdp_client.class.php');
			$client = new SuperPDPClient($db);
		}

		// Détection du format : PDF Factur-X au magic byte, sinon XML dont la
		// racine départage CII (CrossIndustryInvoice) et UBL (Invoice/CreditNote).
		if (strpos($content, '%PDF') === 0) {
			$fromFormat = 'factur-x';
		} elseif (stripos($content, 'CrossIndustryInvoice') !== false) {
			$fromFormat = 'cii';
		} else {
			$fromFormat = 'ubl';
		}

		$en = $client->convertInvoice($content, $fromFormat, 'en16931');

		// On reconstitue la même enveloppe que le polling ({en_invoice: ...})
		// pour partager buildFromApiInvoice() et importAsSupplierInvoice().
		$rec = self::buildFromApiInvoice($db, array('id' => 0, 'en_invoice' => $en));
		$rec->superpdp_id = null;
		$rec->source = self::SOURCE_MANUAL;

		$socid = $rec->resolveThirdparty();
		if ($socid > 0) {
			$rec->fk_soc = $socid;
		} else {
			$rec->status = self::STATUS_QUARANTINE;
			$rec->error_message = ($socid === -1)
				? 'Plusieurs tiers Dolibarr correspondent au SIREN '.$rec->supplier_siren
				: 'Aucun tiers Dolibarr ne correspond (SIREN '.($rec->supplier_siren !== null ? $rec->supplier_siren : 'absent').')';
		}

		if ($rec->create($user) <= 0) {
			throw new SuperPDPException('Enregistrement de la réception impossible : '.$rec->error);
		}

		if ($rec->status !== self::STATUS_QUARANTINE) {
			$rec->importAsSupplierInvoice($user, $client, 0, $content);
		} else {
			// Conserve le fichier original dans le payload pour l'import différé
			// (après création/choix du tiers).
			$rec->storeOriginalFileContent($user, $content);
		}

		return $rec;
	}

	/**
	 * Stocke le fichier original (base64) dans le payload de la réception,
	 * pour qu'un import manuel mis en quarantaine puisse attacher le fichier
	 * une fois le tiers choisi.
	 */
	public function storeOriginalFileContent($user, $content)
	{
		$inv = json_decode((string) $this->payload_raw, true);
		if (!is_array($inv)) $inv = array();
		$inv['original_file_b64'] = base64_encode($content);
		$this->payload_raw = json_encode($inv);
		$sql = "UPDATE ".MAIN_DB_PREFIX."lemonsuperpdp_reception";
		$sql .= " SET payload_raw = '".$this->db->escape($this->payload_raw)."'";
		$sql .= " WHERE rowid = ".((int) $this->id);
		$this->db->query($sql);
	}

	/**
	 * Restitue le fichier original conservé dans le payload (import manuel
	 * en quarantaine), ou null.
	 */
	public function getStoredOriginalFileContent()
	{
		$inv = json_decode((string) $this->payload_raw, true);
		if (is_array($inv) && !empty($inv['original_file_b64'])) {
			$decoded = base64_decode((string) $inv['original_file_b64'], true);
			return ($decoded !== false) ? $decoded : null;
		}
		return null;
	}

	/**
	 * Émet un statut de cycle de vie côté acheteur (fr:205 prise en charge,
	 * fr:206 approuvée, fr:210 refusée, fr:209 paiement transmis...) pour
	 * cette facture reçue, et le trace dans l'agenda de la facture fournisseur.
	 *
	 * @param User                $user        Utilisateur
	 * @param string              $statusCode  Code AFNOR fr:2xx
	 * @param SuperPDPClient|null $client      Client API (instancié si null)
	 * @param array               $details     Détails optionnels (montants...)
	 * @return int  1 OK, -1 KO ($this->error renseigné)
	 */
	public function sendLifecycleEvent($user, $statusCode, $client = null, $details = array())
	{
		if (empty($this->superpdp_id)) {
			$this->error = 'Réception sans identifiant SUPER PDP (import manuel) : statut non transmissible';
			return -1;
		}

		try {
			if ($client === null) {
				dol_include_once('/lemonsuperpdp/class/superpdp_client.class.php');
				$client = new SuperPDPClient($this->db);
			}
			$response = $client->submitEvent((int) $this->superpdp_id, $statusCode, $details);
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			dol_syslog('LemonSuperPDPReception::sendLifecycleEvent '.$statusCode.' : '.$e->getMessage(), LOG_ERR);
			return -1;
		}

		$this->lifecycle_status = $statusCode;
		$this->update($user);

		// Trace agenda sur la facture fournisseur liée.
		if (!empty($this->fk_facture_fourn)) {
			dol_include_once('/lemonsuperpdp/class/event.class.php');
			require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
			$label = LemonSuperPDPEvent::getStatusLabel($statusCode);
			$ac = new ActionComm($this->db);
			$ac->type_code = 'AC_OTH_AUTO';
			$ac->code = 'LEMONSUPERPDP_'.strtoupper(str_replace(array(':', '-'), '_', $statusCode));
			$ac->label = 'SUPER PDP : '.$label.' ('.$statusCode.') (émis)';
			$ac->note_private = 'Statut '.$statusCode.' émis vers SUPER PDP pour la facture reçue n° '.((int) $this->superpdp_id)."\n\nRéponse :\n".json_encode($response);
			$ac->elementtype = 'invoice_supplier';
			$ac->fk_element = (int) $this->fk_facture_fourn;
			$ac->datep = dol_now();
			$ac->datef = dol_now();
			$ac->percentage = -1;
			$ac->create($user);
		}

		return 1;
	}

	/**
	 * Charge la réception liée à une facture fournisseur donnée (la plus récente).
	 *
	 * @param int $fkFactureFourn
	 * @return int  1 trouvé, 0 absent, -1 erreur
	 */
	public function fetchByFactureFourn($fkFactureFourn)
	{
		global $conf;
		$sql = "SELECT * FROM ".MAIN_DB_PREFIX."lemonsuperpdp_reception";
		$sql .= " WHERE fk_facture_fourn = ".((int) $fkFactureFourn);
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
	 * Classe CSS du badge Dolibarr selon le statut.
	 */
	public function getBadgeClass()
	{
		switch ($this->status) {
			case self::STATUS_NEW:        return 'badge-status1';
			case self::STATUS_IMPORTED:   return 'badge-status4';
			case self::STATUS_QUARANTINE: return 'badge-status8';
			case self::STATUS_IGNORED:    return 'badge-status0';
			case self::STATUS_ERROR:      return 'badge-status8';
			default:                      return 'badge-status0';
		}
	}

	/**
	 * Clé de traduction du statut courant.
	 */
	public function getStatusLabelKey()
	{
		$map = array(
			self::STATUS_NEW        => 'LemonSuperPDPRecStatusNew',
			self::STATUS_IMPORTED   => 'LemonSuperPDPRecStatusImported',
			self::STATUS_QUARANTINE => 'LemonSuperPDPRecStatusQuarantine',
			self::STATUS_IGNORED    => 'LemonSuperPDPRecStatusIgnored',
			self::STATUS_ERROR      => 'LemonSuperPDPRecStatusError',
		);
		return isset($map[$this->status]) ? $map[$this->status] : 'LemonSuperPDPRecStatusNew';
	}
}
