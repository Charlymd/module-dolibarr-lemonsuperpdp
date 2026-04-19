<?php
/*
 * Copyright (C) 2026 SASU LEMON <https://hellolemon.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Cron CLI : synchronise les invoice_events SUPER PDP. Wrapper léger qui
 * délègue toute la logique à LemonSuperPDPCron::syncEvents() (utilisée
 * aussi par le planificateur interne Dolibarr).
 *
 * Usage CLI :
 *   php /var/www/html/custom/lemonsuperpdp/scripts/cron_sync_events.php
 *
 * Déclaration planificateur Dolibarr recommandée (préférée au CLI) :
 *   Type    : Exécution d'une méthode d'une classe PHP
 *   Classe  : LemonSuperPDPCron
 *   Fichier : /lemonsuperpdp/class/lemonsuperpdp_cron.class.php
 *   Méthode : syncEvents
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

dol_include_once('/lemonsuperpdp/class/lemonsuperpdp_cron.class.php');

echo "=== Sync events SUPER PDP ===\n";

$cron = new LemonSuperPDPCron($db);
$ret = $cron->syncEvents();
echo $cron->output."\n";
if ($ret < 0) {
	echo "ERREUR : ".$cron->error."\n";
	exit(1);
}
exit(0);
