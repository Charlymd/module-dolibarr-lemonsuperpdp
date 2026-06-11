<?php
/*
 * Copyright (C) 2026 SASU LEMON <https://hellolemon.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Liste des factures fournisseurs reçues via SUPER PDP (direction=in).
 * Synchronisation manuelle, import des quarantaines avec choix du tiers,
 * lien vers la FactureFournisseur créée.
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
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
dol_include_once('/lemonsuperpdp/class/superpdp_client.class.php');
dol_include_once('/lemonsuperpdp/class/reception.class.php');

$langs->loadLangs(array('bills', 'companies', 'lemonsuperpdp@lemonsuperpdp'));

if (!isModEnabled('lemonsuperpdp')) {
	accessforbidden('Module not enabled');
}
if (!$user->hasRight('lemonsuperpdp', 'reception', 'lire')) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$id = GETPOSTINT('id');

// Filtres
$search_status = GETPOST('search_status', 'alpha');
$search_supplier = GETPOST('search_supplier', 'alphanohtml');
$search_number = GETPOST('search_number', 'alphanohtml');

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
	$sortfield = 'r.date_fetched';
}
if (!$sortorder) {
	$sortorder = 'DESC';
}

if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$search_status = '';
	$search_supplier = '';
	$search_number = '';
}

$form = new Form($db);

/*
 * Actions
 */

// Synchronisation manuelle
if ($action == 'sync' && $user->hasRight('lemonsuperpdp', 'reception', 'ecrire')) {
	if (GETPOST('token', 'alpha') != newToken()) {
		accessforbidden('Bad value for CSRF token');
	}
	if (!getDolGlobalInt('LEMONSUPERPDP_IN_ENABLED')) {
		setEventMessages($langs->trans('LemonSuperPDPRecDisabled'), null, 'warnings');
	} else {
		try {
			$res = LemonSuperPDPReception::syncIncoming($db, $user);
			setEventMessages($langs->trans('LemonSuperPDPRecSyncResult', $res['fetched'], $res['imported'], $res['quarantined'], $res['errors']), null, ($res['errors'] > 0 ? 'warnings' : 'mesgs'));
		} catch (Exception $e) {
			setEventMessages($e->getMessage(), null, 'errors');
		}
	}
	$action = '';
}

// Import d'une réception (quarantaine/erreur) avec tiers choisi ou résolu
if ($action == 'import' && $id > 0 && $user->hasRight('lemonsuperpdp', 'reception', 'ecrire')) {
	if (GETPOST('token', 'alpha') != newToken()) {
		accessforbidden('Bad value for CSRF token');
	}
	$rec = new LemonSuperPDPReception($db);
	if ($rec->fetch($id) > 0 && !in_array($rec->status, array(LemonSuperPDPReception::STATUS_IMPORTED), true)) {
		$socid = GETPOSTINT('socid');
		$ret = $rec->importAsSupplierInvoice($user, null, $socid);
		if ($ret > 0) {
			setEventMessages($langs->trans('LemonSuperPDPRecImported'), null, 'mesgs');
		} else {
			setEventMessages($langs->trans('LemonSuperPDPRecImportFailed').($rec->error_message ? ' : '.$rec->error_message : ''), null, 'errors');
		}
	}
	$action = '';
}

// Écarter / réintégrer une réception
if (($action == 'ignore' || $action == 'unignore') && $id > 0 && $user->hasRight('lemonsuperpdp', 'reception', 'ecrire')) {
	if (GETPOST('token', 'alpha') != newToken()) {
		accessforbidden('Bad value for CSRF token');
	}
	$rec = new LemonSuperPDPReception($db);
	if ($rec->fetch($id) > 0 && $rec->status !== LemonSuperPDPReception::STATUS_IMPORTED) {
		$rec->status = ($action == 'ignore') ? LemonSuperPDPReception::STATUS_IGNORED : LemonSuperPDPReception::STATUS_QUARANTINE;
		$rec->update($user);
	}
	$action = '';
}

/*
 * Vue
 */

llxHeader('', $langs->trans('LemonSuperPDPRecListTitle'));

$sql = "SELECT r.rowid, r.superpdp_id, r.fk_facture_fourn, r.fk_soc, r.supplier_name, r.supplier_siren,";
$sql .= " r.invoice_number, r.invoice_type_code, r.invoice_date, r.total_ht, r.total_ttc, r.currency_code,";
$sql .= " r.status, r.error_message, r.date_fetched, r.date_imported,";
$sql .= " s.nom as soc_name, ff.ref as ff_ref, ff.fk_statut as ff_status";
$sql .= " FROM ".MAIN_DB_PREFIX."lemonsuperpdp_reception r";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid = r.fk_soc";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."facture_fourn ff ON ff.rowid = r.fk_facture_fourn";
$sql .= " WHERE r.entity = ".((int) $conf->entity);
if ($search_status !== '' && $search_status != '-1') {
	$sql .= " AND r.status = '".$db->escape($search_status)."'";
}
if ($search_supplier !== '') {
	$sql .= " AND (r.supplier_name LIKE '%".$db->escape($db->escapeforlike($search_supplier))."%'";
	$sql .= " OR s.nom LIKE '%".$db->escape($db->escapeforlike($search_supplier))."%'";
	$sql .= " OR r.supplier_siren LIKE '%".$db->escape($db->escapeforlike($search_supplier))."%')";
}
if ($search_number !== '') {
	$sql .= " AND r.invoice_number LIKE '%".$db->escape($db->escapeforlike($search_number))."%'";
}

// Compte total pour la pagination
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
if ($search_supplier !== '') {
	$param .= '&search_supplier='.urlencode($search_supplier);
}
if ($search_number !== '') {
	$param .= '&search_number='.urlencode($search_number);
}
if ($limit > 0 && $limit != $conf->liste_limit) {
	$param .= '&limit='.((int) $limit);
}

$morehtmlright = '';
if ($user->hasRight('lemonsuperpdp', 'reception', 'ecrire')) {
	$morehtmlright .= '<a class="butAction butActionSmall" href="'.$_SERVER["PHP_SELF"].'?action=sync&token='.newToken().'">';
	$morehtmlright .= img_picto('', 'refresh', 'class="pictofixedwidth"').$langs->trans('LemonSuperPDPRecSyncNow').'</a>';
}

print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" name="formfilter">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="sortfield" value="'.dol_escape_htmltag($sortfield).'">';
print '<input type="hidden" name="sortorder" value="'.dol_escape_htmltag($sortorder).'">';

print_barre_liste($langs->trans('LemonSuperPDPRecListTitle'), $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, '', $num, $nbtotalofrecords, 'supplier_invoice', 0, $morehtmlright, '', $limit);

if (!getDolGlobalInt('LEMONSUPERPDP_IN_ENABLED')) {
	print '<div class="warning">'.$langs->trans('LemonSuperPDPRecDisabledHint').'</div>';
}

$statusChoices = array(
	LemonSuperPDPReception::STATUS_NEW        => $langs->trans('LemonSuperPDPRecStatusNew'),
	LemonSuperPDPReception::STATUS_IMPORTED   => $langs->trans('LemonSuperPDPRecStatusImported'),
	LemonSuperPDPReception::STATUS_QUARANTINE => $langs->trans('LemonSuperPDPRecStatusQuarantine'),
	LemonSuperPDPReception::STATUS_IGNORED    => $langs->trans('LemonSuperPDPRecStatusIgnored'),
	LemonSuperPDPReception::STATUS_ERROR      => $langs->trans('LemonSuperPDPRecStatusError'),
);

print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal liste centpercent">';

// Ligne de filtres
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_number" value="'.dol_escape_htmltag($search_number).'"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth150" name="search_supplier" value="'.dol_escape_htmltag($search_supplier).'"></td>';
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
print_liste_field_titre('LemonSuperPDPRecDateFetched', $_SERVER["PHP_SELF"], 'r.date_fetched', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('LemonSuperPDPRecNumber', $_SERVER["PHP_SELF"], 'r.invoice_number', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('Supplier', $_SERVER["PHP_SELF"], 'r.supplier_name', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('Date', $_SERVER["PHP_SELF"], 'r.invoice_date', '', $param, 'class="center"', $sortfield, $sortorder);
print_liste_field_titre('AmountTTC', $_SERVER["PHP_SELF"], 'r.total_ttc', '', $param, 'class="right"', $sortfield, $sortorder);
print_liste_field_titre('Status', $_SERVER["PHP_SELF"], 'r.status', '', $param, 'class="center"', $sortfield, $sortorder);
print_liste_field_titre('SupplierInvoice', $_SERVER["PHP_SELF"], '', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('', $_SERVER["PHP_SELF"], '', '', $param, 'class="center maxwidthsearch"');
print '</tr>';

$canWrite = $user->hasRight('lemonsuperpdp', 'reception', 'ecrire');

$i = 0;
$imax = min($num, $limit);
while ($i < $imax) {
	$obj = $db->fetch_object($resql);
	$i++;

	$rec = new LemonSuperPDPReception($db);
	$rec->status = $obj->status;

	print '<tr class="oddeven">';

	// Date de réception
	print '<td class="nowrap">'.dol_print_date($db->jdate($obj->date_fetched), 'dayhour').'</td>';

	// Numéro + type
	print '<td>'.dol_escape_htmltag((string) $obj->invoice_number);
	if ((string) $obj->invoice_type_code === '381') {
		print ' <span class="opacitymedium">('.$langs->trans('CreditNote').')</span>';
	}
	print '</td>';

	// Fournisseur : tiers lié sinon nom du XML + SIREN
	print '<td>';
	if (!empty($obj->fk_soc)) {
		$soc = new Societe($db);
		$soc->id = (int) $obj->fk_soc;
		$soc->name = $obj->soc_name;
		print $soc->getNomUrl(1);
	} else {
		print dol_escape_htmltag((string) $obj->supplier_name);
	}
	if (!empty($obj->supplier_siren)) {
		print ' <span class="opacitymedium">('.dol_escape_htmltag($obj->supplier_siren).')</span>';
	}
	print '</td>';

	// Date facture
	print '<td class="center nowrap">'.($obj->invoice_date ? dol_print_date($db->jdate($obj->invoice_date), 'day') : '').'</td>';

	// Montant TTC
	print '<td class="right nowrap">'.($obj->total_ttc !== null ? price($obj->total_ttc, 0, $langs, 1, -1, -1, (string) $obj->currency_code) : '').'</td>';

	// Statut + message éventuel en tooltip
	print '<td class="center">';
	$badge = '<span class="badge '.$rec->getBadgeClass().'">'.$langs->trans($rec->getStatusLabelKey()).'</span>';
	if (!empty($obj->error_message)) {
		print $form->textwithpicto($badge, dol_escape_htmltag($obj->error_message), 1, 'help', '', 0, 3);
	} else {
		print $badge;
	}
	print '</td>';

	// Facture fournisseur liée
	print '<td>';
	if (!empty($obj->fk_facture_fourn) && !empty($obj->ff_ref)) {
		require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
		$ff = new FactureFournisseur($db);
		$ff->id = (int) $obj->fk_facture_fourn;
		$ff->ref = $obj->ff_ref;
		print $ff->getNomUrl(1);
		if ((int) $obj->ff_status === 0) {
			print ' <span class="opacitymedium">('.$langs->trans('Draft').')</span>';
		}
	}
	print '</td>';

	// Actions
	print '<td class="center nowrap">';
	if ($canWrite && in_array($obj->status, array(LemonSuperPDPReception::STATUS_QUARANTINE, LemonSuperPDPReception::STATUS_ERROR, LemonSuperPDPReception::STATUS_NEW, LemonSuperPDPReception::STATUS_IGNORED), true)) {
		// Choix du tiers (composant natif, pré-rempli si déjà résolu) + import
		print '<div class="nowrap inline-block">';
		print $form->select_company((int) $obj->fk_soc, 'socid_'.((int) $obj->rowid), 's.fournisseur = 1', 'LemonSuperPDPRecChooseSupplier', 0, 0, array(), 0, 'maxwidth150');
		print ' <a class="butActionSmall lemonsuperpdp-import" data-recid="'.((int) $obj->rowid).'" href="'.$_SERVER["PHP_SELF"].'?action=import&id='.((int) $obj->rowid).'&token='.newToken().$param.'">'.$langs->trans('LemonSuperPDPRecImport').'</a>';
		print '</div> ';
		if ($obj->status !== LemonSuperPDPReception::STATUS_IGNORED) {
			print '<a class="editfielda paddingleft" href="'.$_SERVER["PHP_SELF"].'?action=ignore&id='.((int) $obj->rowid).'&token='.newToken().$param.'" title="'.dol_escape_htmltag($langs->trans('LemonSuperPDPRecIgnore')).'">'.img_picto($langs->trans('LemonSuperPDPRecIgnore'), 'disable').'</a>';
		} else {
			print '<a class="editfielda paddingleft" href="'.$_SERVER["PHP_SELF"].'?action=unignore&id='.((int) $obj->rowid).'&token='.newToken().$param.'" title="'.dol_escape_htmltag($langs->trans('LemonSuperPDPRecUnignore')).'">'.img_picto($langs->trans('LemonSuperPDPRecUnignore'), 'enable').'</a>';
		}
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

// Au clic sur Importer, ajoute à l'URL le tiers choisi dans le select de la ligne.
print '<script>
jQuery(function($) {
	$("a.lemonsuperpdp-import").on("click", function(e) {
		var recid = $(this).data("recid");
		var socid = $("#socid_" + recid).val();
		if (socid && socid > 0) {
			e.preventDefault();
			window.location = $(this).attr("href") + "&socid=" + socid;
		}
	});
});
</script>';

llxFooter();
$db->close();
