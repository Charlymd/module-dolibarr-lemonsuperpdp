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
 * (status_code AFNOR fr:200..fr:213 + fr:501). Les events peuvent être
 * sortants (direction='out', émis par nous via POST /v1.beta/invoice_events)
 * ou entrants (direction='in', récupérés via le cron de polling sur
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
	public $reason_code;         // code motif normalisé du statut (MDT-113, BR-FR-CDV-15)
	public $reason;              // motif en texte libre (MDT-114) — conservé en local
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

	// Codes du cycle de vie facture électronique, centralisés ici pour éviter
	// les strings magiques.
	//
	// Référentiel établi sur pièces (2026-07) : l'API SUPER PDP (spec OpenAPI
	// v1.24.0.beta, schéma status_code) transporte DIRECTEMENT les codes
	// « ProcessConditionCode » de la réforme (MDT-105, XP Z12-012 chap. 5,
	// règle BR-FR-CDV-CL-06) préfixés « fr: ». Il n'y a donc AUCUN transcodage
	// à faire côté transport : fr:NNN sur le fil = code réforme NNN.
	//
	// Correspondance code ↔ statut (source : XP Z12-012 BR-FR-CDV-CL-05 +
	// doc API SUPER PDP ; les deux concordent) :
	//
	//   Code    | Statut réforme            | Posé par              | Motif exigé (BR-FR-CDV-15)
	//   --------+---------------------------+-----------------------+---------------------------
	//   fr:200  | Déposée                   | PA émettrice          | non
	//   fr:201  | Émise par la plateforme   | PA émettrice          | non
	//   fr:202  | Reçue par la plateforme   | PA destinataire       | non
	//   fr:203  | Mise à disposition        | PA destinataire       | non
	//   fr:204  | Prise en charge           | Acheteur              | non
	//   fr:205  | Approuvée                 | Acheteur              | non
	//   fr:206  | Approuvée partiellement   | Acheteur              | OUI
	//   fr:207  | En litige                 | Acheteur              | OUI
	//   fr:208  | Suspendue                 | Acheteur              | OUI
	//   fr:209  | Complétée                 | Vendeur               | non
	//   fr:210  | Refusée                   | Acheteur              | OUI
	//   fr:211  | Paiement transmis         | Acheteur              | non
	//   fr:212  | Encaissée                 | Vendeur               | non (mais montants MEN exigés, BR-FR-CDV-14)
	//   fr:213  | Rejetée                   | PA                    | OUI
	//   fr:501  | Irrecevable               | PA                    | OUI
	//
	// ATTENTION : avant 2026-07 ces constantes portaient une sémantique
	// décalée (fr:206 étiqueté « Approuvée », fr:209 « Paiement transmis »…).
	// Les noms ci-dessous suivent désormais la sémantique officielle.
	const STATUS_DEPOSEE              = 'fr:200';
	const STATUS_EMISE                = 'fr:201';
	const STATUS_RECUE                = 'fr:202';
	const STATUS_MISE_A_DISPOSITION   = 'fr:203';
	const STATUS_PRISE_EN_CHARGE      = 'fr:204';
	const STATUS_APPROUVEE            = 'fr:205';
	const STATUS_APPROUVEE_PARTIELLE  = 'fr:206';
	const STATUS_LITIGE               = 'fr:207';
	const STATUS_SUSPENDUE            = 'fr:208';
	const STATUS_COMPLETEE            = 'fr:209';
	const STATUS_REFUSEE              = 'fr:210';
	const STATUS_PAIEMENT_TRANSMIS    = 'fr:211';
	const STATUS_ENCAISSEE            = 'fr:212';
	const STATUS_REJETEE              = 'fr:213';
	const STATUS_IRRECEVABLE          = 'fr:501';

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
		$this->reason_code = isset($obj->reason_code) ? $obj->reason_code : null;
		$this->reason = isset($obj->reason) ? $obj->reason : null;
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
		$sql .= "fk_transmission, fk_facture, entity, superpdp_event_id, status_code, reason_code, reason, message, direction, flux, event_date, payload_raw, date_creation, fk_user_creat";
		$sql .= ") VALUES (";
		$sql .= (!empty($this->fk_transmission) ? ((int) $this->fk_transmission) : "NULL");
		$sql .= ", ".(!empty($this->fk_facture) ? ((int) $this->fk_facture) : "NULL");
		$sql .= ", ".((int) $conf->entity);
		$sql .= ", ".(!empty($this->superpdp_event_id) ? ((int) $this->superpdp_event_id) : "NULL");
		$sql .= ", '".$this->db->escape($this->status_code)."'";
		$sql .= ", ".(!empty($this->reason_code) ? "'".$this->db->escape($this->reason_code)."'" : "NULL");
		$sql .= ", ".(!empty($this->reason) ? "'".$this->db->escape($this->reason)."'" : "NULL");
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
	 *   reason_code (string, optionnel — code motif MDT-113, exigé par la
	 *                norme pour certains statuts, cf statusRequiresReason())
	 *   reason (string, optionnel — motif en texte libre, MDT-114)
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
		$ev->reason_code = isset($attrs['reason_code']) ? $attrs['reason_code'] : null;
		$ev->reason = isset($attrs['reason']) ? $attrs['reason'] : null;
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
	 *                                  message, created_at ; optionnels :
	 *                                  details[].reason, data.reason)
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

			// Motif du statut (MDT-113) : l'API le porte dans details[].reason
			// (event sortant échoé) ou dans data.reason (event entrant).
			$reasonCode = null;
			if (!empty($ev['details'][0]['reason'])) {
				$reasonCode = (string) $ev['details'][0]['reason'];
			} elseif (!empty($ev['data']['reason'])) {
				$reasonCode = (string) $ev['data']['reason'];
			}

			$ret = self::createAndLog($db, array(
				'fk_transmission'   => (int) $fkTransmission,
				'superpdp_event_id' => (int) $ev['id'],
				'status_code'       => (string) $ev['status_code'],
				'reason_code'       => $reasonCode,
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

		// Si le message stocké n'est que le code technique brut (ex. 'api:uploaded'),
		// on lui préfère le libellé lisible de getStatusLabel().
		$label = (!empty($this->message) && $this->message !== $this->status_code)
			? $this->message
			: self::getStatusLabel($this->status_code);
		$dirSuffix = ($this->direction === self::DIRECTION_OUT) ? ' (émis)' : ' (reçu)';

		$note = 'Événement SUPER PDP '.$this->status_code.' : '.$label.$dirSuffix;
		if (!empty($this->reason_code) || !empty($this->reason)) {
			$motifParts = array();
			if (!empty($this->reason_code)) $motifParts[] = (string) $this->reason_code;
			if (!empty($this->reason))      $motifParts[] = (string) $this->reason;
			$note .= "\nMotif : ".implode(' — ', $motifParts);
		}
		if (!empty($this->superpdp_event_id)) {
			$note .= "\nID SUPER PDP : ".((int) $this->superpdp_event_id);
		}
		if (!empty($this->payload_raw)) {
			$note .= "\n\nPayload brut :\n".$this->payload_raw;
		}

		$ac = new ActionComm($this->db);
		$ac->type_code    = 'AC_OTH_AUTO';
		$ac->code         = 'LEMONSUPERPDP_'.strtoupper(str_replace(array(':', '-'), '_', $this->status_code));
		// Code entre parenthèses seulement pour les statuts AFNOR fr:2xx/fr:501
		// (utile comme référence) — pas pour les codes techniques internes.
		$showCode = (strpos((string) $this->status_code, 'fr:') === 0);
		$ac->label        = 'SUPER PDP : '.$label.($showCode ? ' ('.$this->status_code.')' : '');
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
	 * Libellé humain d'un status_code réforme (fr:200..fr:213, fr:501).
	 * Utilisé en fallback quand l'API ne fournit pas de message.
	 *
	 * Libellés alignés sur la sémantique officielle XP Z12-012
	 * (BR-FR-CDV-CL-05) — cf le tableau de correspondance au-dessus des
	 * constantes STATUS_*. Passe par les fichiers de langue quand une clé
	 * LemonSuperPDPFrStatusXXX existe (fallback : libellés français ci-dessous).
	 */
	public static function getStatusLabel($statusCode)
	{
		global $langs;
		if (is_object($langs)) {
			$key = 'LemonSuperPDPFrStatus'.preg_replace('/[^A-Za-z0-9]/', '', (string) $statusCode);
			$langs->load('lemonsuperpdp@lemonsuperpdp');
			$trans = $langs->transnoentities($key);
			if ($trans !== $key) {
				return $trans;
			}
		}
		$map = array(
			'fr:200' => 'Déposée',
			'fr:201' => 'Émise par la plateforme',
			'fr:202' => 'Reçue par la plateforme',
			'fr:203' => 'Mise à disposition',
			'fr:204' => 'Prise en charge',
			'fr:205' => 'Approuvée',
			'fr:206' => 'Approuvée partiellement',
			'fr:207' => 'En litige',
			'fr:208' => 'Suspendue',
			'fr:209' => 'Complétée',
			'fr:210' => 'Refusée',
			'fr:211' => 'Paiement transmis',
			'fr:212' => 'Encaissée',
			'fr:213' => 'Rejetée',
			'fr:501' => 'Irrecevable',
			'ACK'    => 'Accusé de réception',
			'ACK-01' => 'Accusé de réception',
			'ACK-02' => 'Validation de format',
			'REJECT' => 'Rejet technique',
			'ROUTE'  => 'Routage confirmé',
			// Codes internes LemonSuperPDP (hors réforme fr:2xx) : suivi local
			// (envoi API, génération Factur-X, erreurs techniques) qui ne
			// transitent pas par l'AFNOR mais alimentent les mêmes events.
			'api:uploaded'      => 'Téléversée vers SUPER PDP',
			'api:recovered'     => 'Facture déjà présente sur SUPER PDP',
			// « Dépôt » et non « Format » : le contrôle d'entrée de la plateforme
			// peut refuser pour d'autres causes que le fichier lui-même
			// (adressage du destinataire, annuaire…) — ne pas accuser la Factur-X.
			'api:validated'     => 'Dépôt accepté par SUPER PDP',
			'api:invalid'       => 'Dépôt refusé par SUPER PDP',
			'api:error'         => 'Erreur API',
			'ERROR'             => 'Erreur',
			'facturx:generated' => 'Factur-X généré',
			'facturx:error'     => 'Erreur de génération Factur-X',
		);
		return isset($map[$statusCode]) ? $map[$statusCode] : $statusCode;
	}

	/**
	 * Vrai si le statut exige un motif (MDT-113) selon la règle BR-FR-CDV-15 :
	 * Approuvée partiellement (fr:206), En litige (fr:207), Suspendue (fr:208),
	 * Refusée (fr:210), Rejetée (fr:213), Irrecevable (fr:501).
	 *
	 * @param string $statusCode  Code réforme (ex : 'fr:210')
	 * @return bool
	 */
	public static function statusRequiresReason($statusCode)
	{
		return in_array($statusCode, array(
			self::STATUS_APPROUVEE_PARTIELLE,
			self::STATUS_LITIGE,
			self::STATUS_SUSPENDUE,
			self::STATUS_REFUSEE,
			self::STATUS_REJETEE,
			self::STATUS_IRRECEVABLE,
		), true);
	}

	/**
	 * Codes motifs normalisés (MDT-113) proposés à l'utilisateur.
	 *
	 * La liste officielle des motifs par statut est publiée dans l'annexe A
	 * (fichier Excel) de la XP Z12-012, feuille « Tableau des motifs de
	 * STATUTS » — elle n'est pas embarquée ici pour ne pas risquer de codes
	 * inventés. Elle se configure par instance via la constante Dolibarr
	 * LEMONSUPERPDP_REASON_CODES, au format JSON {"CODE": "Libellé", ...}.
	 * Tant qu'elle n'est pas configurée, l'UI propose une saisie libre du
	 * code (l'API SUPER PDP accepte une string dans details[].reason).
	 *
	 * @return array  Tableau code => libellé (vide si non configuré)
	 */
	public static function getReasonCodes()
	{
		$json = getDolGlobalString('LEMONSUPERPDP_REASON_CODES');
		if (!empty($json)) {
			$arr = json_decode($json, true);
			if (is_array($arr)) {
				return $arr;
			}
			dol_syslog('LemonSuperPDPEvent::getReasonCodes : LEMONSUPERPDP_REASON_CODES ne contient pas un JSON valide', LOG_WARNING);
		}
		return array();
	}

	/**
	 * Codes status_code que l'on peut POSTer via l'API (enum status_code_create
	 * de la spec SUPER PDP v1.24.0.beta), par opposition aux statuts posés par
	 * les plateformes elles-mêmes (fr:200..fr:203, fr:213, fr:501).
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
			case 'fr:201':
			case 'fr:202':
			case 'fr:203':
			case 'fr:204':
			case 'fr:208':
				return 'badge-status1';   // jaune — transmission/traitement en cours, suspendue
			case 'fr:205':
			case 'fr:206':
			case 'fr:209':
				return 'badge-status4';   // vert — approuvée (totale/partielle), complétée
			case 'fr:211':
				return 'badge-status5';   // bleu — paiement transmis
			case 'fr:212':
				return 'badge-status6';   // orange — encaissée
			case 'fr:210':
			case 'fr:213':
			case 'fr:501':
				return 'badge-status8';   // rouge — refusée / rejetée / irrecevable
			case 'fr:207':
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

		$badCodes = array('ERROR', 'REJECT', 'fr:207', 'fr:210', 'fr:213', 'fr:501');
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
		if (in_array($code, array('ERROR', 'REJECT', 'fr:210', 'fr:213', 'fr:501'), true)) return '#A32D2D';
		if ($code === 'fr:207')        return '#854F0B'; // brun — litige
		if (in_array($code, array('api:recovered', 'fr:208'), true)) return '#CC9900'; // ambre — avertissement / suspendue
		if (in_array($code, array('fr:212', 'fr:205', 'fr:206'), true))              return '#3B6D11';
		if (in_array($code, array('fr:200', 'fr:201', 'fr:202', 'fr:203',
		                          'fr:204', 'fr:209', 'fr:211', 'ACK', 'ACK-01',
		                          'ACK-02', 'ROUTE'), true))                          return '#185FA5';
		return '#888780';
	}
}
