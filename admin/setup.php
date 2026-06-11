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
dol_include_once('/lemonsuperpdp/core/lib/lemonsuperpdp.lib.php');

// Sécurité
if (!$user->admin) {
	accessforbidden();
}

$langs->loadLangs(["admin", "lemonsuperpdp@lemonsuperpdp"]);

$action = GETPOST('action', 'aZ09');

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
	$inEnabled = GETPOSTINT('LEMONSUPERPDP_IN_ENABLED');
	$ereportingEnabled = GETPOSTINT('LEMONSUPERPDP_EREPORTING_ENABLED');
	$precheckDirectory = GETPOSTINT('LEMONSUPERPDP_PRECHECK_DIRECTORY');
	// >>> SANDBOX MODE — À SUPPRIMER APRÈS LA PHASE PILOTE <<<
	$sandboxMode = GETPOSTINT('LEMONSUPERPDP_SANDBOX_MODE');
	// >>> FIN SANDBOX MODE <<<

	if (!in_array($format, array('facturx', 'ubl', 'cii'), true)) {
		$format = 'facturx';
	}
	if (empty($endpoint)) {
		$endpoint = 'https://api.superpdp.tech';
	}
	// Mitigation SSRF : on refuse un endpoint non-HTTPS. Ça évite qu'un
	// admin pointe (volontairement ou non) vers http://localhost:XXX et
	// se retrouve à y envoyer le Bearer token OAuth.
	if (!preg_match('#^https://#i', $endpoint)) {
		setEventMessages($langs->trans('LemonSuperPDPEndpointMustBeHttps'), null, 'errors');
		$error++;
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
	if (dolibarr_set_const($db, 'LEMONSUPERPDP_IN_ENABLED', $inEnabled, 'int', 0, '', $conf->entity) < 0) {
		$error++;
	}
	if (dolibarr_set_const($db, 'LEMONSUPERPDP_EREPORTING_ENABLED', $ereportingEnabled, 'int', 0, '', $conf->entity) < 0) {
		$error++;
	}
	if (dolibarr_set_const($db, 'LEMONSUPERPDP_PRECHECK_DIRECTORY', $precheckDirectory, 'int', 0, '', $conf->entity) < 0) {
		$error++;
	}
	// >>> SANDBOX MODE — À SUPPRIMER APRÈS LA PHASE PILOTE <<<
	if (dolibarr_set_const($db, 'LEMONSUPERPDP_SANDBOX_MODE', $sandboxMode, 'int', 0, '', $conf->entity) < 0) {
		$error++;
	}
	// >>> FIN SANDBOX MODE <<<

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

		// Cohérence SIREN local (mysoc) ↔ SIREN de l'application OAuth SUPER PDP.
		// Sans ce check, une instance Dolibarr déclarant un SIREN différent de celui
		// lié à l'application OAuth émettrait des factures rejetées par la PA.
		// L'API SUPER PDP renvoie le SIREN dans `number` quand `number_scheme === 'fr_siren'`.
		$remoteSiren = '';
		if (!empty($testResult['number']) && (($testResult['number_scheme'] ?? '') === 'fr_siren')) {
			$remoteSiren = preg_replace('/\D/', '', (string) $testResult['number']);
		}
		$localSiren = preg_replace('/\D/', '', (string) ($mysoc->idprof1 ?? ''));

		// Mémoriser le SIREN OAuth pour que le diagnostic puisse vérifier la cohérence
		// sans refaire d'appel API à chaque chargement.
		if ($remoteSiren !== '') {
			dolibarr_set_const($db, 'LEMONSUPERPDP_OAUTH_SIREN', $remoteSiren, 'chaine', 0, '', 0);
		}
		$sirenMatches = ($remoteSiren !== '' && $localSiren !== '' && $remoteSiren === $localSiren);
		$sirenMismatch = ($remoteSiren !== '' && $localSiren !== '' && $remoteSiren !== $localSiren);
		$sirenLocalMissing = ($remoteSiren !== '' && $localSiren === '');

		$details = array();
		if (!empty($testResult['formal_name'])) {
			$details[] = $langs->trans("LemonSuperPDPCompanyName").' : '.dol_escape_htmltag($testResult['formal_name']);
		}
		if ($remoteSiren !== '') {
			$details[] = 'SIREN : '.dol_escape_htmltag($remoteSiren);
		}

		if ($sirenMismatch) {
			$err = $langs->trans("LemonSuperPDPTestSirenMismatch", $localSiren, $remoteSiren);
			if (!empty($details)) {
				$err .= ' — '.implode(' — ', $details);
			}
			setEventMessages($err, null, 'errors');
		} elseif ($sirenLocalMissing) {
			$warn = $langs->trans("LemonSuperPDPTestSirenLocalMissing", $remoteSiren);
			if (!empty($details)) {
				$warn .= ' — '.implode(' — ', $details);
			}
			setEventMessages($warn, null, 'warnings');
		} else {
			$msg = $langs->trans("LemonSuperPDPTestSuccess");
			if ($sirenMatches) {
				$details[] = $langs->trans("LemonSuperPDPTestSirenMatch");
			}
			if (!empty($details)) {
				$msg .= ' — '.implode(' — ', $details);
			}
			setEventMessages($msg, null, 'mesgs');
		}
	} catch (SuperPDPException $e) {
		$err = $langs->trans("LemonSuperPDPTestError").' — '.$e->getMessage();
		if ($e->responseBody) {
			$err .= ' — '.dol_trunc($e->responseBody, 400);
		}
		setEventMessages($err, null, 'errors');
	} catch (Exception $e) {
		setEventMessages($langs->trans("LemonSuperPDPTestError").' — '.$e->getMessage(), null, 'errors');
	}
}

// Affichage
llxHeader('', $langs->trans("LemonSuperPDPSetup"));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans("LemonSuperPDPSetup"), $linkback, 'title_setup');

// Bandeau "Nouvelle version disponible" si le check GitHub remonte une version > locale
require_once dirname(__DIR__).'/core/modules/modLemonSuperPDP.class.php';
$modDesc = new modLemonSuperPDP($db);
$updateInfo = lemonsuperpdp_check_latest_release($db, $modDesc->version);
if ($updateInfo !== null) {
	print '<div class="warning" style="margin:8px 0;padding:10px;border-left:4px solid #e67e22;background:#fff3e0;">';
	print '<strong>'.$langs->trans("LemonSuperPDPUpdateAvailable").'</strong> : ';
	print $langs->trans("LemonSuperPDPUpdateAvailableMsg", dol_escape_htmltag($updateInfo['version']), dol_escape_htmltag($modDesc->version));
	print ' <a href="'.dol_escape_htmltag($updateInfo['url']).'" target="_blank" rel="noopener">'.$langs->trans("LemonSuperPDPUpdateSeeRelease").'</a>';
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
print '<br><span class="opacitymedium">'.$langs->trans("LemonSuperPDPFormatHelp", DOL_URL_ROOT).'</span>';
print '</td>';
print '</tr>';

// Réception des factures fournisseurs (direction=in)
print '<tr class="oddeven">';
print '<td>'.$langs->trans("LemonSuperPDPInEnabled").'</td>';
print '<td>';
$inCurrent = getDolGlobalInt('LEMONSUPERPDP_IN_ENABLED');
print '<select name="LEMONSUPERPDP_IN_ENABLED" class="flat">';
print '<option value="0"'.(!$inCurrent ? ' selected' : '').'>'.$langs->trans("No").'</option>';
print '<option value="1"'.($inCurrent ? ' selected' : '').'>'.$langs->trans("Yes").'</option>';
print '</select>';
print '<br><span class="opacitymedium">'.$langs->trans("LemonSuperPDPInEnabledHelp").'</span>';
if ($inCurrent) {
	print '<br><a href="'.dol_buildpath('/lemonsuperpdp/reception_list.php', 1).'">'.$langs->trans("LemonSuperPDPRecListTitle").'</a>';
}
print '</td>';
print '</tr>';

// E-reporting B2C (transactions et paiements des factures aux particuliers)
print '<tr class="oddeven">';
print '<td>'.$langs->trans("LemonSuperPDPEreportingEnabled").'</td>';
print '<td>';
$ereportingCurrent = getDolGlobalInt('LEMONSUPERPDP_EREPORTING_ENABLED');
print '<select name="LEMONSUPERPDP_EREPORTING_ENABLED" class="flat">';
print '<option value="0"'.(!$ereportingCurrent ? ' selected' : '').'>'.$langs->trans("No").'</option>';
print '<option value="1"'.($ereportingCurrent ? ' selected' : '').'>'.$langs->trans("Yes").'</option>';
print '</select>';
print '<br><span class="opacitymedium">'.$langs->trans("LemonSuperPDPEreportingEnabledHelp").'</span>';
if ($ereportingCurrent) {
	print '<br><a href="'.dol_buildpath('/lemonsuperpdp/ereporting_list.php', 1).'">'.$langs->trans("LemonSuperPDPEreportingListTitle").'</a>';
}
print '</td>';
print '</tr>';

// Pre-check annuaire des Plateformes Agréées avant chaque envoi
print '<tr class="oddeven">';
print '<td>'.$langs->trans("LemonSuperPDPPrecheckDirectory").'</td>';
print '<td>';
$precheckCurrent = getDolGlobalInt('LEMONSUPERPDP_PRECHECK_DIRECTORY', 1);
print '<select name="LEMONSUPERPDP_PRECHECK_DIRECTORY" class="flat">';
print '<option value="0"'.(!$precheckCurrent ? ' selected' : '').'>'.$langs->trans("No").'</option>';
print '<option value="1"'.($precheckCurrent ? ' selected' : '').'>'.$langs->trans("Yes").'</option>';
print '</select>';
print '<br><span class="opacitymedium">'.$langs->trans("LemonSuperPDPPrecheckDirectoryHelp").'</span>';
print '</td>';
print '</tr>';

// >>> SANDBOX MODE — À SUPPRIMER APRÈS LA PHASE PILOTE <<<
// Ce bloc de configuration n'a de sens que pendant la phase pilote SUPER PDP.
// Voir l'en-tête de class/actions_lemonsuperpdp.class.php pour le contexte.
print '<tr class="oddeven">';
print '<td>'.$langs->trans("LemonSuperPDPSandboxMode").'</td>';
print '<td>';
$sandboxCurrent = getDolGlobalInt('LEMONSUPERPDP_SANDBOX_MODE');
print '<select name="LEMONSUPERPDP_SANDBOX_MODE" class="flat">';
print '<option value="0"'.(!$sandboxCurrent ? ' selected' : '').'>'.$langs->trans("No").'</option>';
print '<option value="1"'.($sandboxCurrent ? ' selected' : '').'>'.$langs->trans("Yes").'</option>';
print '</select>';
print '<br><span class="opacitymedium">'.$langs->trans("LemonSuperPDPSandboxModeHelp").'</span>';
if ($sandboxCurrent) {
	$idprof6 = !empty($mysoc->idprof6) ? $mysoc->idprof6 : '';
	if (empty($idprof6)) {
		print '<br><span style="color:#c00;"><strong>'.$langs->trans("LemonSuperPDPSandboxModeIdProf6Missing").'</strong></span>';
	} else {
		print '<br><span style="color:#c70;">'.$langs->trans("LemonSuperPDPSandboxModeActiveWarning", dol_escape_htmltag($idprof6)).'</span>';
	}
}
print '</td>';
print '</tr>';
// >>> FIN SANDBOX MODE <<<

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
	$diagOk[] = $langs->trans("LemonSuperPDPDiagFacturXEnabled", DOL_URL_ROOT);
} else {
	$diagErrors[] = $langs->trans("LemonSuperPDPDiagFacturXDisabled", DOL_URL_ROOT);
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

// Cohérence SIREN société ↔ SIREN de l'application OAuth SUPER PDP.
// Le SIREN OAuth est mémorisé lors du dernier "Tester la connexion" réussi ;
// le diagnostic ne déclenche pas d'appel API tout seul.
$cachedOauthSiren = preg_replace('/\D/', '', (string) getDolGlobalString('LEMONSUPERPDP_OAUTH_SIREN', ''));
$localSirenDiag = preg_replace('/\D/', '', (string) ($mysoc->idprof1 ?? ''));
if ($cachedOauthSiren === '') {
	$diagErrors[] = $langs->trans("LemonSuperPDPDiagSirenNotTested");
} elseif ($localSirenDiag === '') {
	$diagErrors[] = $langs->trans("LemonSuperPDPDiagSirenLocalMissing", $cachedOauthSiren);
} elseif ($localSirenDiag === $cachedOauthSiren) {
	$diagOk[] = $langs->trans("LemonSuperPDPDiagSirenMatchOauth", $cachedOauthSiren);
} else {
	$diagErrors[] = $langs->trans("LemonSuperPDPDiagSirenMismatchOauth", $localSirenDiag, $cachedOauthSiren);
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

// Bloc "À propos de Lemon" — vitrine éditeur
print '<div style="margin:30px 0;padding:20px 25px;border:1px solid #e0e0e0;border-left:4px solid #FFD21F;border-radius:6px;background:linear-gradient(135deg,#fffef7 0%,#fafafa 100%);">';
print '<h3 style="margin:0 0 10px 0;color:#333;">'.$langs->trans("LemonSuperPDPAboutTitle").'</h3>';
print '<p style="margin:0 0 12px 0;color:#555;">'.$langs->trans("LemonSuperPDPAboutIntro").'</p>';
print '<ul style="margin:0 0 15px 20px;color:#555;">';
print '<li><strong>'.$langs->trans("LemonSuperPDPAboutSvc1Title").'</strong> : '.$langs->trans("LemonSuperPDPAboutSvc1Desc").'</li>';
print '<li><strong>'.$langs->trans("LemonSuperPDPAboutSvc2Title").'</strong> : '.$langs->trans("LemonSuperPDPAboutSvc2Desc").'</li>';
print '<li><strong>'.$langs->trans("LemonSuperPDPAboutSvc3Title").'</strong> : '.$langs->trans("LemonSuperPDPAboutSvc3Desc").'</li>';
print '<li><strong>'.$langs->trans("LemonSuperPDPAboutSvc4Title").'</strong> : '.$langs->trans("LemonSuperPDPAboutSvc4Desc").'</li>';
print '<li><strong>'.$langs->trans("LemonSuperPDPAboutSvc5Title").'</strong> : '.$langs->trans("LemonSuperPDPAboutSvc5Desc").'</li>';
print '</ul>';
print '<p style="margin:0;">';
print '<a href="https://hellolemon.fr" target="_blank" rel="noopener" class="butAction" style="text-decoration:none;">'.$langs->trans("LemonSuperPDPAboutCTA").'</a>';
print ' <span style="color:#999;margin-left:15px;">'.$langs->trans("LemonSuperPDPAboutLocation").'</span>';
print '</p>';
print '</div>';

llxFooter();
$db->close();
