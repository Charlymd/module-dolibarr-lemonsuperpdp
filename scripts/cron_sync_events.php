<?php
/*
 * Copyright (C) 2026 SASU LEMON <https://hellolemon.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Cron : synchronise les invoice_events SUPER PDP pour toutes les
 * transmissions connues. Utilise starting_after_id pour ne récupérer
 * que les nouveaux events depuis la dernière exécution.
 *
 * Usage CLI :
 *   php /var/www/html/custom/lemonsuperpdp/scripts/cron_sync_events.php
 *
 * Ou via le planificateur Dolibarr (Accueil > Configuration > Modules >
 * Planificateur) en exécutant : lemonsuperpdp/scripts/cron_sync_events.php
 */

// Guard CLI
if (php_sapi_name() !== 'cli' && !defined('CRON_MUTED')) {
	http_response_code(403);
	die('CLI only');
}

$res = 0;
if (!$res && file_exists(__DIR__.'/../../../main.inc.php')) {
	$res = @include __DIR__.'/../../../main.inc.php';
}
if (!$res && file_exists(__DIR__.'/../../../../main.inc.php')) {
	$res = @include __DIR__.'/../../../../main.inc.php';
}
if (!$res) {
	die('Include main.inc.php failed');
}

dol_include_once('/lemonsuperpdp/class/superpdp_client.class.php');
dol_include_once('/lemonsuperpdp/class/transmission.class.php');
dol_include_once('/lemonsuperpdp/class/event.class.php');

if (!isModEnabled('lemonsuperpdp')) {
	echo "Module lemonsuperpdp désactivé\n";
	exit(0);
}
if (!getDolGlobalInt('LEMONSUPERPDP_ENABLED')) {
	echo "LEMONSUPERPDP_ENABLED=0, arrêt\n";
	exit(0);
}

global $user;
if (empty($user) || empty($user->id)) {
	require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
	$user = new User($db);
	$user->fetch(1);  // user admin
}

echo "=== Sync events SUPER PDP ===\n";

$client = new SuperPDPClient($db);
$evObj = new LemonSuperPDPEvent($db);

$lastId = (int) getDolGlobalString('LEMONSUPERPDP_LAST_EVENT_ID', '0');
$nbInserted = 0;
$nbPages = 0;

try {
	$hasAfter = true;
	while ($hasAfter && $nbPages < 50) {
		$nbPages++;
		$resp = $client->listInvoiceEvents($lastId);
		$data = !empty($resp['data']) && is_array($resp['data']) ? $resp['data'] : array();
		$hasAfter = !empty($resp['has_after']);

		foreach ($data as $ev) {
			if (empty($ev['id']) || empty($ev['status_code'])) continue;
			$lastId = max($lastId, (int) $ev['id']);

			if ($evObj->existsBySuperpdpId((int) $ev['id'])) continue;

			// On recherche la transmission associée via invoice_id
			$invoiceId = !empty($ev['invoice_id']) ? (int) $ev['invoice_id'] : 0;
			if ($invoiceId <= 0) continue;

			$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."lemonsuperpdp_transmission";
			$sql .= " WHERE superpdp_id = ".((int) $invoiceId);
			$sql .= " LIMIT 1";
			$sqlRes = $db->query($sql);
			$fkTransmission = 0;
			if ($sqlRes && ($row = $db->fetch_object($sqlRes))) {
				$fkTransmission = (int) $row->rowid;
				$db->free($sqlRes);
			}
			if ($fkTransmission <= 0) {
				continue;
			}

			$newEv = new LemonSuperPDPEvent($db);
			$newEv->fk_transmission = $fkTransmission;
			$newEv->superpdp_event_id = (int) $ev['id'];
			$newEv->status_code = (string) $ev['status_code'];
			$newEv->message = !empty($ev['message']) ? (string) $ev['message'] : null;
			$newEv->direction = LemonSuperPDPEvent::DIRECTION_IN;
			$newEv->event_date = !empty($ev['created_at']) ? strtotime($ev['created_at']) : dol_now();
			$newEv->payload_raw = json_encode($ev);
			if ($newEv->create($user) > 0) {
				$nbInserted++;
				echo "  + event #".((int) $ev['id']).' '.$ev['status_code'].' (transmission '.$fkTransmission.")\n";
			}
		}
	}
} catch (Exception $e) {
	echo "ERREUR : ".$e->getMessage()."\n";
	dol_syslog('LemonSuperPDP cron: '.$e->getMessage(), LOG_ERR);
	exit(1);
}

dolibarr_set_const($db, 'LEMONSUPERPDP_LAST_EVENT_ID', (string) $lastId, 'chaine', 0, '', 0);

echo "Terminé : $nbInserted events insérés, dernier id = $lastId, $nbPages pages lues\n";
exit(0);
