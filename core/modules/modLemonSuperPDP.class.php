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
		$this->version = '1.0.0';
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
	 *
	 * @param string $options Options when enabling module ('', 'noboxes')
	 * @return int 1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		$this->_load_tables('/lemonsuperpdp/sql/');
		return $this->_init(array(), $options);
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
