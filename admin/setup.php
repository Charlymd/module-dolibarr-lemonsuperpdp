<?php
/*
 * Copyright (C) 2026 SASU LEMON <https://hellolemon.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Page de configuration du module LemonSuperPDP
 */

// Charger l'environnement Dolibarr
$res = 0;
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/lemonsuperpdp/class/superpdp_client.class.php');

// Sécurité
if (!$user->admin) {
	accessforbidden();
}

$langs->loadLangs(["admin", "lemonsuperpdp@lemonsuperpdp"]);

$action = GETPOST('action', 'aZ09');

$testResult = null;
$testError = null;

// Sauvegarde des paramètres
if ($action == 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
	if (GETPOST('token', 'alpha') != newToken()) {
		accessforbidden('Bad value for CSRF token');
	}
	$error = 0;

	$enabled = GETPOSTINT('LEMONSUPERPDP_ENABLED');
	$endpoint = trim(GETPOST('LEMONSUPERPDP_ENDPOINT', 'alpha'));
	$clientId = trim(GETPOST('LEMONSUPERPDP_CLIENT_ID', 'alphanohtml'));
	$clientSecret = trim(GETPOST('LEMONSUPERPDP_CLIENT_SECRET', 'alphanohtml'));
	$format = GETPOST('LEMONSUPERPDP_FORMAT', 'alpha');

	if (!in_array($format, array('facturx', 'ubl', 'cii'), true)) {
		$format = 'facturx';
	}
	if (empty($endpoint)) {
		$endpoint = 'https://api.superpdp.tech';
	}

	if (dolibarr_set_const($db, 'LEMONSUPERPDP_ENABLED', $enabled, 'int', 0, '', $conf->entity) < 0) {
		$error++;
	}
	if (dolibarr_set_const($db, 'LEMONSUPERPDP_ENDPOINT', $endpoint, 'chaine', 0, '', $conf->entity) < 0) {
		$error++;
	}
	if (dolibarr_set_const($db, 'LEMONSUPERPDP_CLIENT_ID', $clientId, 'chaine', 0, '', $conf->entity) < 0) {
		$error++;
	}
	// Ne pas écraser le secret si l'utilisateur a laissé les étoiles
	if ($clientSecret !== '' && $clientSecret !== '********') {
		if (dolibarr_set_const($db, 'LEMONSUPERPDP_CLIENT_SECRET', $clientSecret, 'chaine', 0, '', $conf->entity) < 0) {
			$error++;
		}
	}
	if (dolibarr_set_const($db, 'LEMONSUPERPDP_FORMAT', $format, 'chaine', 0, '', $conf->entity) < 0) {
		$error++;
	}

	// Toute modification invalide le token en cache
	dolibarr_set_const($db, 'LEMONSUPERPDP_ACCESS_TOKEN', '', 'chaine', 0, '', $conf->entity);

	if (!$error) {
		setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
	} else {
		setEventMessages($langs->trans("Error"), null, 'errors');
	}
}

// Test de connexion
if ($action == 'testconn') {
	if (GETPOST('token', 'alpha') != newToken()) {
		accessforbidden('Bad value for CSRF token');
	}
	try {
		$client = new SuperPDPClient($db);
		$testResult = $client->testConnection();
	} catch (SuperPDPException $e) {
		$testError = $e->getMessage();
		if ($e->responseBody) {
			$testError .= ' — '.dol_trunc($e->responseBody, 400);
		}
	} catch (Exception $e) {
		$testError = $e->getMessage();
	}
}

// Affichage
llxHeader('', $langs->trans("LemonSuperPDPSetup"));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans("LemonSuperPDPSetup"), $linkback, 'title_setup');

// Résultat du test
if ($testResult !== null) {
	print '<div class="ok">';
	print '<strong>'.$langs->trans("LemonSuperPDPTestSuccess").'</strong><br>';
	if (!empty($testResult['formal_name'])) {
		print $langs->trans("LemonSuperPDPCompanyName").' : '.dol_escape_htmltag($testResult['formal_name']).'<br>';
	}
	if (!empty($testResult['siren'])) {
		print 'SIREN : '.dol_escape_htmltag($testResult['siren']).'<br>';
	}
	print '</div>';
}
if ($testError !== null) {
	print '<div class="error">';
	print '<strong>'.$langs->trans("LemonSuperPDPTestError").'</strong><br>';
	print dol_escape_htmltag($testError);
	print '</div>';
}

print '<form method="POST" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

print '<table class="noborder centpercent">';

print '<tr class="liste_titre">';
print '<td>'.$langs->trans("Parameter").'</td>';
print '<td>'.$langs->trans("Value").'</td>';
print '</tr>';

// Activer / Désactiver
print '<tr class="oddeven">';
print '<td>'.$langs->trans("LemonSuperPDPEnabled").'</td>';
print '<td>';
print '<select name="LEMONSUPERPDP_ENABLED" class="flat">';
print '<option value="0"'.(!getDolGlobalInt('LEMONSUPERPDP_ENABLED') ? ' selected' : '').'>'.$langs->trans("No").'</option>';
print '<option value="1"'.(getDolGlobalInt('LEMONSUPERPDP_ENABLED') ? ' selected' : '').'>'.$langs->trans("Yes").'</option>';
print '</select>';
print '</td>';
print '</tr>';

// Endpoint
print '<tr class="oddeven">';
print '<td>'.$langs->trans("LemonSuperPDPEndpoint").'</td>';
print '<td>';
print '<input type="text" name="LEMONSUPERPDP_ENDPOINT" class="flat minwidth400" value="'.dol_escape_htmltag(getDolGlobalString('LEMONSUPERPDP_ENDPOINT', 'https://api.superpdp.tech')).'">';
print '<br><span class="opacitymedium">'.$langs->trans("LemonSuperPDPEndpointHelp").'</span>';
print '</td>';
print '</tr>';

// client_id
print '<tr class="oddeven">';
print '<td>'.$langs->trans("LemonSuperPDPClientId").'</td>';
print '<td>';
print '<input type="text" name="LEMONSUPERPDP_CLIENT_ID" class="flat minwidth400" value="'.dol_escape_htmltag(getDolGlobalString('LEMONSUPERPDP_CLIENT_ID', '')).'" autocomplete="off">';
print '</td>';
print '</tr>';

// client_secret (masqué)
print '<tr class="oddeven">';
print '<td>'.$langs->trans("LemonSuperPDPClientSecret").'</td>';
print '<td>';
$hasSecret = !empty(getDolGlobalString('LEMONSUPERPDP_CLIENT_SECRET', ''));
print '<input type="password" name="LEMONSUPERPDP_CLIENT_SECRET" class="flat minwidth400" value="'.($hasSecret ? '********' : '').'" autocomplete="new-password" placeholder="'.($hasSecret ? $langs->trans("LemonSuperPDPClientSecretKeep") : '').'">';
print '<br><span class="opacitymedium">'.$langs->trans("LemonSuperPDPClientSecretHelp").'</span>';
print '</td>';
print '</tr>';

// Format par défaut
print '<tr class="oddeven">';
print '<td>'.$langs->trans("LemonSuperPDPFormat").'</td>';
print '<td>';
$currentFormat = getDolGlobalString('LEMONSUPERPDP_FORMAT', 'facturx');
print '<select name="LEMONSUPERPDP_FORMAT" class="flat">';
print '<option value="facturx"'.($currentFormat == 'facturx' ? ' selected' : '').'>Factur-X (PDF/A-3 + XML CII)</option>';
print '<option value="ubl"'.($currentFormat == 'ubl' ? ' selected' : '').'>UBL France (XML)</option>';
print '<option value="cii"'.($currentFormat == 'cii' ? ' selected' : '').'>CII France (XML)</option>';
print '</select>';
print '<br><span class="opacitymedium">'.$langs->trans("LemonSuperPDPFormatHelp").'</span>';
print '</td>';
print '</tr>';

print '</table>';

print '<br>';
print '<div class="center">';
print '<input type="submit" class="button button-save" value="'.$langs->trans("Save").'">';
print '</div>';

print '</form>';

// Bouton de test de connexion (form séparé pour que le test ne sauvegarde pas les champs non validés)
print '<br>';
print '<form method="POST" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="testconn">';
print '<div class="center">';
print '<input type="submit" class="button" value="'.$langs->trans("LemonSuperPDPTestConnection").'">';
print '</div>';
print '</form>';

// Info
print '<br>';
print '<div class="info">';
print $langs->trans("LemonSuperPDPInfo");
print '</div>';

// Diagnostic
print '<br>';
print load_fiche_titre($langs->trans("LemonSuperPDPDiagTitle"), '', '');

$diagErrors = array();
$diagOk = array();

// LemonFacturX activé ?
if (isModEnabled('lemonfacturx')) {
	$diagOk[] = $langs->trans("LemonSuperPDPDiagFacturXEnabled");
} else {
	$diagErrors[] = $langs->trans("LemonSuperPDPDiagFacturXDisabled");
}

// Identifiants OAuth renseignés ?
if (!empty(getDolGlobalString('LEMONSUPERPDP_CLIENT_ID', '')) && !empty(getDolGlobalString('LEMONSUPERPDP_CLIENT_SECRET', ''))) {
	$diagOk[] = $langs->trans("LemonSuperPDPDiagCredentialsSet");
} else {
	$diagErrors[] = $langs->trans("LemonSuperPDPDiagCredentialsMissing");
}

// SIREN société émettrice (sera injecté dans les factures)
if (!empty($mysoc->idprof2)) {
	$diagOk[] = $langs->trans("LemonSuperPDPDiagSellerSIRET").' : '.dol_escape_htmltag($mysoc->idprof2);
} else {
	$diagErrors[] = $langs->trans("LemonSuperPDPDiagSellerSIRETMissing");
}

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("LemonSuperPDPDiagResults").'</td></tr>';
foreach ($diagOk as $ok) {
	print '<tr class="oddeven"><td><span style="color: green;">&#10004;</span> '.$ok.'</td><td></td></tr>';
}
foreach ($diagErrors as $err) {
	print '<tr class="oddeven"><td><span style="color: red;">&#10008;</span> <strong>'.$err.'</strong></td><td></td></tr>';
}
if (empty($diagErrors)) {
	print '<tr class="oddeven"><td colspan="2"><span style="color: green;"><strong>'.$langs->trans("LemonSuperPDPDiagAllOk").'</strong></span></td></tr>';
}
print '</table>';

llxFooter();
$db->close();
