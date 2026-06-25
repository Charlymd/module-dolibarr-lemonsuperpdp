<?php
/*
 * Copyright (C) 2026 SASU LEMON <https://hellolemon.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Module descriptor for LemonSuperPDP
 * Transmission des factures électroniques via la Plateforme Agréée SUPER PDP
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modLemonSuperPDP extends DolibarrModules
{
	public function __construct($db)
	{
		global $conf;

		$this->db = $db;
		$this->numero = 210009;
		$this->rights_class = 'lemonsuperpdp';
		$this->family = "financial";
		$this->module_position = '91';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = "Émission et réception des factures électroniques via la Plateforme Agréée SUPER PDP";
		$this->descriptionlong = "Envoie les factures clients Factur-X (générées par LemonFacturX) via l'API de la Plateforme Agréée SUPER PDP, synchronise les statuts de cycle de vie (déposée, acceptée, refusée, encaissée), et importe les factures fournisseurs reçues sur la plateforme en factures fournisseurs Dolibarr brouillon.";
		$this->version = '1.2.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'bill';
		$this->editor_name = 'Lemon';
		$this->editor_url = 'https://hellolemon.fr';

		$this->module_parts = array(
			'triggers' => 1,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'theme' => 0,
			'tpl' => 0,
			'barcode' => 0,
			'models' => 0,
			'hooks' => array(
				'invoicecard',
				'invoicelist',
			),
		);

		// Onglet "Cycle de vie" sur la fiche facture client.
		// Déclaré ici dans le descripteur de module car Dolibarr l'enregistre
		// en base à l'activation et l'injecte via invoice_prepare_head().
		// Format Dolibarr : 'objecttype:+tabname:Label,Class,PathFile,Method:langfile@module:condition:url'
		// La méthode getLifecycleBadgeContent() retourne le contenu du badge (●  N coloré).
		$this->tabs = array(
			array('data' => 'invoice:+lifecycle:Facturation électronique,LemonSuperPDPEvent,/lemonsuperpdp/class/event.class.php,getLifecycleBadgeContent:lemonsuperpdp@lemonsuperpdp:$user->hasRight("facture","lire"):/lemonsuperpdp/tab_lifecycle.php?id=__ID__'),
		);

		$this->dirs = array();
		$this->config_page_url = array('setup.php@lemonsuperpdp');

		$this->hidden = false;
		$this->depends = array('modLemonFacturX');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array("lemonsuperpdp@lemonsuperpdp");

		$this->phpmin = array(7, 4);
		$this->need_dolibarr_version = array(18, 0, 0);

		$this->tables = array(
			'llx_lemonsuperpdp_transmission',
			'llx_lemonsuperpdp_event',
			'llx_lemonsuperpdp_reception',
			'llx_lemonsuperpdp_ereporting',
		);

		$this->const = array(
			array('LEMONSUPERPDP_ENABLED', 'int', '1', 'Activer la transmission via SUPER PDP', 1, 'current', 0),
			array('LEMONSUPERPDP_ENDPOINT', 'chaine', 'https://api.superpdp.tech', 'URL de base de l\'API SUPER PDP', 1, 'current', 0),
			array('LEMONSUPERPDP_CLIENT_ID', 'chaine', '', 'OAuth 2.1 client_id de l\'application SUPER PDP', 1, 'current', 0),
			array('LEMONSUPERPDP_CLIENT_SECRET', 'chaine', '', 'OAuth 2.1 client_secret de l\'application SUPER PDP', 1, 'current', 0),
			array('LEMONSUPERPDP_FORMAT', 'chaine', 'facturx', 'Format d\'envoi par défaut (facturx, ubl, cii)', 1, 'current', 0),
			array('LEMONSUPERPDP_ACCESS_TOKEN', 'chaine', '', 'Cache du token OAuth 2.1 (JSON)', 1, 'current', 0),
			array('LEMONSUPERPDP_LAST_EVENT_ID', 'chaine', '0', 'Dernier invoice_event synchronisé', 1, 'current', 0),
			array('LEMONSUPERPDP_IN_ENABLED', 'int', '0', 'Activer la réception des factures fournisseurs (direction=in)', 1, 'current', 0),
			array('LEMONSUPERPDP_LAST_IN_ID', 'chaine', '0', 'Dernière facture reçue synchronisée (curseur direction=in)', 1, 'current', 0),
			array('LEMONSUPERPDP_EREPORTING_ENABLED', 'int', '0', 'Activer l\'e-reporting B2C (transactions et paiements des factures aux particuliers)', 1, 'current', 0),
			array('LEMONSUPERPDP_PRECHECK_DIRECTORY', 'int', '1', 'Vérifier l\'annuaire des Plateformes Agréées avant chaque envoi', 1, 'current', 0),
			// >>> SANDBOX MODE — À SUPPRIMER APRÈS LA PHASE PILOTE <<<
			// Voir en-tête de class/actions_lemonsuperpdp.class.php pour le contexte.
			array('LEMONSUPERPDP_SANDBOX_MODE', 'int', '0', 'Mode sandbox : remplace le SIREN émetteur par celui du champ idprof6 avant envoi', 1, 'current', 0),
			array('LEMONSUPERPDP_SANDBOX_CLIENT_SIREN', 'chaine', '000000001', 'SIREN sandbox du destinataire fictif (Tricatel = 000000001)', 1, 'current', 0),
			// >>> FIN SANDBOX MODE <<<
		);

		if (!isset($conf->lemonsuperpdp) || !isset($conf->lemonsuperpdp->enabled)) {
			$conf->lemonsuperpdp = new stdClass();
			$conf->lemonsuperpdp->enabled = 0;
		}

		// Tâches planifiées : synchronisation périodique des events SUPER PDP.
		// Automatiquement créées dans llx_cronjob à l'activation du module.
		$this->cronjobs = array(
			0 => array(
				'label' => 'Sync events SUPER PDP',
				'jobtype' => 'method',
				'class' => '/lemonsuperpdp/class/lemonsuperpdp_cron.class.php',
				'objectname' => 'LemonSuperPDPCron',
				'method' => 'syncEvents',
				'parameters' => '',
				'comment' => 'Récupère les événements de cycle de vie depuis l\'API SUPER PDP (fr:200..fr:212).',
				'frequency' => 15,
				'unitfrequency' => 60,          // 60 = minute
				'priority' => 50,
				'status' => 1,                   // 1 = activé par défaut
				'test' => '$conf->lemonsuperpdp->enabled',
			),
			1 => array(
				'label' => 'Sync factures reçues SUPER PDP',
				'jobtype' => 'method',
				'class' => '/lemonsuperpdp/class/lemonsuperpdp_cron.class.php',
				'objectname' => 'LemonSuperPDPCron',
				'method' => 'syncIncoming',
				'parameters' => '',
				'comment' => 'Importe les factures fournisseurs reçues sur SUPER PDP (direction=in) en factures fournisseurs Dolibarr brouillon. Ne fait rien tant que LEMONSUPERPDP_IN_ENABLED=0.',
				'frequency' => 15,
				'unitfrequency' => 60,
				'priority' => 51,
				'status' => 1,
				'test' => '$conf->lemonsuperpdp->enabled',
			),
			2 => array(
				'label' => 'Envoi e-reporting B2C SUPER PDP',
				'jobtype' => 'method',
				'class' => '/lemonsuperpdp/class/lemonsuperpdp_cron.class.php',
				'objectname' => 'LemonSuperPDPCron',
				'method' => 'sendEreporting',
				'parameters' => '',
				'comment' => 'Pousse les déclarations e-reporting B2C en attente (transactions et paiements) vers SUPER PDP. Ne fait rien tant que LEMONSUPERPDP_EREPORTING_ENABLED=0.',
				'frequency' => 15,
				'unitfrequency' => 60,
				'priority' => 52,
				'status' => 1,
				'test' => '$conf->lemonsuperpdp->enabled',
			),
		);

		$this->rights = array();
		$r = 0;

		$this->rights[$r][0] = $this->numero * 100 + 1;
		$this->rights[$r][1] = 'Envoyer une facture via SUPER PDP';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'transmission';
		$this->rights[$r][5] = 'ecrire';
		$r++;

		$this->rights[$r][0] = $this->numero * 100 + 2;
		$this->rights[$r][1] = 'Consulter les transmissions SUPER PDP';
		$this->rights[$r][3] = 1;
		$this->rights[$r][4] = 'transmission';
		$this->rights[$r][5] = 'lire';
		$r++;

		$this->rights[$r][0] = $this->numero * 100 + 3;
		$this->rights[$r][1] = 'Consulter les factures reçues via SUPER PDP';
		$this->rights[$r][3] = 1;
		$this->rights[$r][4] = 'reception';
		$this->rights[$r][5] = 'lire';
		$r++;

		$this->rights[$r][0] = $this->numero * 100 + 4;
		$this->rights[$r][1] = 'Importer les factures reçues via SUPER PDP';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'reception';
		$this->rights[$r][5] = 'ecrire';
		$r++;

		$this->rights[$r][0] = $this->numero * 100 + 5;
		$this->rights[$r][1] = 'Consulter la file e-reporting B2C';
		$this->rights[$r][3] = 1;
		$this->rights[$r][4] = 'ereporting';
		$this->rights[$r][5] = 'lire';
		$r++;

		$this->rights[$r][0] = $this->numero * 100 + 6;
		$this->rights[$r][1] = 'Transmettre l\'e-reporting B2C';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'ereporting';
		$this->rights[$r][5] = 'ecrire';
		$r++;

		$this->rights[$r][0] = $this->numero * 100 + 91;
		$this->rights[$r][1] = 'Administrer le module LemonSuperPDP';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'admin';
		$this->rights[$r][5] = '';
		$r++;

		// Entrée de menu sous Facturation > Factures fournisseurs
		$this->menu = array();
		$r = 0;
		$this->menu[$r] = array(
			'fk_menu' => 'fk_mainmenu=billing,fk_leftmenu=suppliers_bills',
			'type' => 'left',
			'titre' => 'LemonSuperPDPMenuReceived',
			'mainmenu' => 'billing',
			'leftmenu' => 'lemonsuperpdp_reception',
			'url' => '/lemonsuperpdp/reception_list.php',
			'langs' => 'lemonsuperpdp@lemonsuperpdp',
			'position' => 1000,
			'enabled' => 'isModEnabled("lemonsuperpdp")',
			'perms' => '$user->hasRight("lemonsuperpdp", "reception", "lire")',
			'target' => '',
			'user' => 2,
		);
		$r++;
		$this->menu[$r] = array(
			'fk_menu' => 'fk_mainmenu=billing,fk_leftmenu=customers_bills',
			'type' => 'left',
			'titre' => 'LemonSuperPDPMenuEreporting',
			'mainmenu' => 'billing',
			'leftmenu' => 'lemonsuperpdp_ereporting',
			'url' => '/lemonsuperpdp/ereporting_list.php',
			'langs' => 'lemonsuperpdp@lemonsuperpdp',
			'position' => 1000,
			'enabled' => 'isModEnabled("lemonsuperpdp")',
			'perms' => '$user->hasRight("lemonsuperpdp", "ereporting", "lire")',
			'target' => '',
			'user' => 2,
		);
		$r++;
	}

	/**
	 * Function called when module is enabled.
	 * Charge les tables SQL, puis applique les migrations ALTER TABLE
	 * du cycle de vie (colonnes flux / direction / label / seen).
	 *
	 * @param string $options Options when enabling module ('', 'noboxes')
	 * @return int 1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		$this->_load_tables('/lemonsuperpdp/sql/');
		$this->_migrate_lifecycle_columns();
		return $this->_init(array(), $options);
	}

	/**
	 * Ajoute les colonnes flux / seen sur llx_lemonsuperpdp_event
	 * si elles n'existent pas encore.
	 * Vérifie information_schema avant chaque ALTER — idempotent.
	 *
	 * @return void
	 */
	private function _migrate_lifecycle_columns()
	{
		$table = MAIN_DB_PREFIX . 'lemonsuperpdp_event';

		// Colonnes à ajouter (direction existe déjà depuis la création initiale)
		$columns = array(
			'flux' => "VARCHAR(20) DEFAULT NULL COMMENT 'fournisseur | pdp | client'",
			'seen' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0 = non vu, 1 = vu'",
		);
		foreach ($columns as $col => $def) {
			$check = "SELECT 1 FROM information_schema.COLUMNS"
			       . " WHERE TABLE_SCHEMA = DATABASE()"
			       . " AND TABLE_NAME = '" . $this->db->escape($table) . "'"
			       . " AND COLUMN_NAME = '" . $this->db->escape($col) . "'"
			       . " LIMIT 1";
			$res = $this->db->query($check);
			if ($res && $this->db->num_rows($res) > 0) {
				continue;
			}
			$this->db->query("ALTER TABLE `" . $table . "` ADD COLUMN `" . $col . "` " . $def);
		}

		// Index sur (fk_transmission, flux) — fk_facture passe par la transmission
		$indexes = array(
			'idx_lsp_event_flux' => '(fk_transmission, flux)',
			'idx_lsp_event_code' => '(fk_transmission, status_code)',
		);
		foreach ($indexes as $idx_name => $idx_cols) {
			$check = "SELECT 1 FROM information_schema.STATISTICS"
			       . " WHERE TABLE_SCHEMA = DATABASE()"
			       . " AND TABLE_NAME = '" . $this->db->escape($table) . "'"
			       . " AND INDEX_NAME = '" . $this->db->escape($idx_name) . "'"
			       . " LIMIT 1";
			$res = $this->db->query($check);
			if ($res && $this->db->num_rows($res) > 0) {
				continue;
			}
			$this->db->query("ALTER TABLE `" . $table . "` ADD INDEX `" . $idx_name . "` " . $idx_cols);
		}

		// Rétro-alimentation flux sur events existants (basée sur status_code + direction)
		// fr:212 émis par nous (direction='out') → fournisseur ; reçu (direction='in') → client
		$fournisseur = "'fr:200','fr:201','fr:202','fr:203','fr:204','fr:205','api:uploaded','facturx:generated','facturx:error'";
		$pdp         = "'ACK','ACK-01','ACK-02','REJECT','ROUTE','ERROR'";
		$client      = "'fr:206','fr:207','fr:208','fr:209','fr:210','fr:211'";
		$backfills = array(
			"UPDATE `" . $table . "` SET flux = 'fournisseur' WHERE status_code IN (" . $fournisseur . ") AND (flux IS NULL OR flux = '')",
			"UPDATE `" . $table . "` SET flux = 'pdp'         WHERE status_code IN (" . $pdp         . ") AND (flux IS NULL OR flux = '')",
			"UPDATE `" . $table . "` SET flux = 'client'      WHERE status_code IN (" . $client      . ") AND (flux IS NULL OR flux = '')",
			"UPDATE `" . $table . "` SET flux = 'fournisseur' WHERE status_code = 'fr:212' AND direction = 'out' AND (flux IS NULL OR flux = '')",
			"UPDATE `" . $table . "` SET flux = 'client'      WHERE status_code = 'fr:212' AND direction = 'in'  AND (flux IS NULL OR flux = '')",
		);
		foreach ($backfills as $sql) {
			$this->db->query($sql);
		}
	}

	/**
	 * Function called when module is disabled.
	 *
	 * @param string $options Options when disabling module ('', 'noboxes')
	 * @return int 1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
