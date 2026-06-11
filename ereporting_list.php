<?php
/*
 * Copyright (C) 2026 SASU LEMON <https://hellolemon.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * File d'attente e-reporting B2C : transactions et paiements déclarés
 * (ou à déclarer) à la PA SUPER PDP, qui agrège et transmet au PPF.
 */

$res = 0;
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
dol_include_once('/lemonsuperpdp/class/ereporting.class.php');

$langs->loadLangs(array('bills', 'lemonsuperpdp@lemonsuperpdp'));

if (!isModEnabled('lemonsuperpdp')) {
	accessforbidden('Module not enabled');
}
if (!$user->hasRight('lemonsuperpdp', 'ereporting', 'lire')) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

// Filtres
$search_status = GETPOST('search_status', 'alpha');
$search_type = GETPOST('search_type', 'alpha');

// Tri et pagination
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT('page');
if (empty($page) || $page < 0) {
	$page = 0;
}
$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : $conf->liste_limit;
$offset = $limit * $page;
if (!$sortfield) {
	$sortfield = 'e.rowid';
}
if (!$sortorder) {
	$sortorder = 'DESC';
}

if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$search_status = '';
	$search_type = '';
}

$form = new Form($db);

/*
 * Actions
 */

if ($action == 'process' && $user->hasRight('lemonsuperpdp', 'ereporting', 'ecrire')) {
	if (GETPOST('token', 'alpha') != newToken()) {
		accessforbidden('Bad value for CSRF token');
	}
	if (!getDolGlobalInt('LEMONSUPERPDP_EREPORTING_ENABLED')) {
		setEventMessages($langs->trans('LemonSuperPDPEreportingDisabled'), null, 'warnings');
	} else {
		try {
			$res = LemonSuperPDPEreporting::processPending($db, $user);
			setEventMessages($langs->trans('LemonSuperPDPEreportingProcessResult', $res['sent'], $res['errors'], $res['pending']), null, ($res['errors'] > 0 ? 'warnings' : 'mesgs'));
		} catch (Exception $e) {
			setEventMessages($e->getMessage(), null, 'errors');
		}
	}
	$action = '';
}

// Repasse une ligne en erreur à pending (après correction côté Dolibarr/SUPER PDP)
if ($action == 'retry' && GETPOSTINT('id') > 0 && $user->hasRight('lemonsuperpdp', 'ereporting', 'ecrire')) {
	if (GETPOST('token', 'alpha') != newToken()) {
		accessforbidden('Bad value for CSRF token');
	}
	$sql = "UPDATE ".MAIN_DB_PREFIX."lemonsuperpdp_ereporting";
	$sql .= " SET status = '".LemonSuperPDPEreporting::STATUS_PENDING."', error_message = NULL";
	$sql .= " WHERE rowid = ".GETPOSTINT('id');
	$sql .= " AND entity = ".((int) $conf->entity);
	$sql .= " AND status = '".LemonSuperPDPEreporting::STATUS_ERROR."'";
	$db->query($sql);
	$action = '';
}

/*
 * Vue
 */

llxHeader('', $langs->trans('LemonSuperPDPEreportingListTitle'));

$sql = "SELECT e.rowid, e.type, e.fk_facture, e.superpdp_id, e.status, e.payload, e.error_message,";
$sql .= " e.date_creation, e.date_sent, f.ref as facture_ref";
$sql .= " FROM ".MAIN_DB_PREFIX."lemonsuperpdp_ereporting e";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."facture f ON f.rowid = e.fk_facture";
$sql .= " WHERE e.entity = ".((int) $conf->entity);
if ($search_status !== '' && $search_status != '-1') {
	$sql .= " AND e.status = '".$db->escape($search_status)."'";
}
if ($search_type !== '' && $search_type != '-1') {
	$sql .= " AND e.type = '".$db->escape($search_type)."'";
}

$nbtotalofrecords = 0;
$resCount = $db->query(preg_replace('/^SELECT .* FROM/s', 'SELECT COUNT(*) as nb FROM', $sql));
if ($resCount) {
	$objCount = $db->fetch_object($resCount);
	$nbtotalofrecords = (int) $objCount->nb;
	$db->free($resCount);
}

$sql .= $db->order($sortfield, $sortorder);
$sql .= $db->plimit($limit + 1, $offset);

$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
	llxFooter();
	$db->close();
	exit;
}
$num = $db->num_rows($resql);

$param = '';
if ($search_status !== '' && $search_status != '-1') {
	$param .= '&search_status='.urlencode($search_status);
}
if ($search_type !== '' && $search_type != '-1') {
	$param .= '&search_type='.urlencode($search_type);
}
if ($limit > 0 && $limit != $conf->liste_limit) {
	$param .= '&limit='.((int) $limit);
}

$morehtmlright = '';
if ($user->hasRight('lemonsuperpdp', 'ereporting', 'ecrire')) {
	$morehtmlright .= '<a class="butAction butActionSmall" href="'.$_SERVER["PHP_SELF"].'?action=process&token='.newToken().'">';
	$morehtmlright .= img_picto('', 'refresh', 'class="pictofixedwidth"').$langs->trans('LemonSuperPDPEreportingProcessNow').'</a>';
}

print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" name="formfilter">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="sortfield" value="'.dol_escape_htmltag($sortfield).'">';
print '<input type="hidden" name="sortorder" value="'.dol_escape_htmltag($sortorder).'">';

print_barre_liste($langs->trans('LemonSuperPDPEreportingListTitle'), $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, '', $num, $nbtotalofrecords, 'bill', 0, $morehtmlright, '', $limit);

if (!getDolGlobalInt('LEMONSUPERPDP_EREPORTING_ENABLED')) {
	print '<div class="warning">'.$langs->trans('LemonSuperPDPEreportingDisabledHint').'</div>';
}

$statusChoices = array(
	LemonSuperPDPEreporting::STATUS_PENDING => $langs->trans('LemonSuperPDPEreportingStatusPending'),
	LemonSuperPDPEreporting::STATUS_SENT    => $langs->trans('LemonSuperPDPEreportingStatusSent'),
	LemonSuperPDPEreporting::STATUS_ERROR   => $langs->trans('LemonSuperPDPEreportingStatusError'),
);
$typeChoices = array(
	LemonSuperPDPEreporting::TYPE_TRANSACTION => $langs->trans('LemonSuperPDPEreportingTypeTransaction'),
	LemonSuperPDPEreporting::TYPE_PAYMENT     => $langs->trans('LemonSuperPDPEreportingTypePayment'),
);

print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal liste centpercent">';

// Ligne de filtres
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre center">'.$form->selectarray('search_type', $typeChoices, $search_type, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth125').'</td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre center">'.$form->selectarray('search_status', $statusChoices, $search_status, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth125').'</td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre right">';
print '<input type="image" class="liste_titre" name="button_search" src="'.img_picto($langs->trans("Search"), 'search.png', '', '', 1).'" value="'.dol_escape_htmltag($langs->trans("Search")).'" title="'.dol_escape_htmltag($langs->trans("Search")).'">';
print '<input type="image" class="liste_titre" name="button_removefilter" src="'.img_picto($langs->trans("RemoveFilter"), 'searchclear.png', '', '', 1).'" value="'.dol_escape_htmltag($langs->trans("RemoveFilter")).'" title="'.dol_escape_htmltag($langs->trans("RemoveFilter")).'">';
print '</td>';
print '</tr>';

// En-têtes
print '<tr class="liste_titre">';
print_liste_field_titre('DateCreation', $_SERVER["PHP_SELF"], 'e.date_creation', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('Type', $_SERVER["PHP_SELF"], 'e.type', '', $param, 'class="center"', $sortfield, $sortorder);
print_liste_field_titre('Bill', $_SERVER["PHP_SELF"], 'f.ref', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('LemonSuperPDPEreportingCategory', $_SERVER["PHP_SELF"], '', '', $param, 'class="center"');
print_liste_field_titre('Amount', $_SERVER["PHP_SELF"], '', '', $param, 'class="right"');
print_liste_field_titre('Status', $_SERVER["PHP_SELF"], 'e.status', '', $param, 'class="center"', $sortfield, $sortorder);
print_liste_field_titre('LemonSuperPDPEreportingDateSent', $_SERVER["PHP_SELF"], 'e.date_sent', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('', $_SERVER["PHP_SELF"], '', '', $param, 'class="center maxwidthsearch"');
print '</tr>';

$canWrite = $user->hasRight('lemonsuperpdp', 'ereporting', 'ecrire');

$i = 0;
$imax = min($num, $limit);
while ($i < $imax) {
	$obj = $db->fetch_object($resql);
	$i++;

	$er = new LemonSuperPDPEreporting($db);
	$er->status = $obj->status;

	$payload = json_decode((string) $obj->payload, true);
	$isTransaction = ($obj->type === LemonSuperPDPEreporting::TYPE_TRANSACTION);

	// Montant affiché : HT pour une transaction, somme TTC des sous-totaux pour un paiement.
	$amount = null;
	$category = '';
	if (is_array($payload)) {
		if ($isTransaction) {
			$amount = isset($payload['tax_exclusive_amount']) ? (float) $payload['tax_exclusive_amount'] : null;
			$category = isset($payload['category_code']) ? (string) $payload['category_code'] : '';
		} elseif (!empty($payload['subtotals']) && is_array($payload['subtotals'])) {
			$amount = 0.0;
			$cats = array();
			foreach ($payload['subtotals'] as $st) {
				$amount += isset($st['amount']) ? (float) $st['amount'] : 0.0;
				if (!empty($st['category_code'])) $cats[(string) $st['category_code']] = true;
			}
			$category = implode(', ', array_keys($cats));
		}
	}

	print '<tr class="oddeven">';
	print '<td class="nowrap">'.dol_print_date($db->jdate($obj->date_creation), 'dayhour').'</td>';
	print '<td class="center">'.$langs->trans($isTransaction ? 'LemonSuperPDPEreportingTypeTransaction' : 'LemonSuperPDPEreportingTypePayment').'</td>';

	print '<td>';
	if (!empty($obj->fk_facture) && !empty($obj->facture_ref)) {
		$facture = new Facture($db);
		$facture->id = (int) $obj->fk_facture;
		$facture->ref = $obj->facture_ref;
		print $facture->getNomUrl(1);
	}
	print '</td>';

	print '<td class="center">'.dol_escape_htmltag($category).'</td>';
	print '<td class="right nowrap">'.($amount !== null ? price($amount).($isTransaction ? ' '.$langs->trans('HT') : '') : '').'</td>';

	print '<td class="center">';
	$badge = '<span class="badge '.$er->getBadgeClass().'">'.$langs->trans($er->getStatusLabelKey()).'</span>';
	if (!empty($obj->error_message)) {
		print $form->textwithpicto($badge, dol_escape_htmltag($obj->error_message), 1, 'help', '', 0, 3);
	} else {
		print $badge;
	}
	print '</td>';

	print '<td class="nowrap">'.($obj->date_sent ? dol_print_date($db->jdate($obj->date_sent), 'dayhour') : '').'</td>';

	print '<td class="center nowrap">';
	if ($canWrite && $obj->status === LemonSuperPDPEreporting::STATUS_ERROR) {
		print '<a class="editfielda" href="'.$_SERVER["PHP_SELF"].'?action=retry&id='.((int) $obj->rowid).'&token='.newToken().$param.'" title="'.dol_escape_htmltag($langs->trans('LemonSuperPDPEreportingRetry')).'">'.img_picto($langs->trans('LemonSuperPDPEreportingRetry'), 'refresh').'</a>';
	}
	print '</td>';

	print '</tr>';
}

if ($num == 0) {
	print '<tr class="oddeven"><td colspan="8" class="opacitymedium center">'.$langs->trans('NoRecordFound').'</td></tr>';
}

print '</table>';
print '</div>';
print '</form>';

llxFooter();
$db->close();
