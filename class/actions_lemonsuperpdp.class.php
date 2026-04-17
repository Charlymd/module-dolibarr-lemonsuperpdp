<?php
/*
 * Copyright (C) 2026 SASU LEMON <https://hellolemon.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Hooks LemonSuperPDP : bouton d'envoi sur la fiche facture, gestion action.
 *
 * =============================================================================
 * ATTENTION — CODE SANDBOX TEMPORAIRE
 * =============================================================================
 *
 * Ce fichier contient des blocs de code dédiés au mode sandbox SUPER PDP.
 * Ils sont marqués par les balises suivantes :
 *
 *     // >>> SANDBOX MODE — À SUPPRIMER APRÈS LA PHASE PILOTE <<<
 *     ...
 *     // >>> FIN SANDBOX MODE <<<
 *
 * Contexte : pendant la phase pilote SUPER PDP (mars-août 2026), Lemon n'a
 * pas encore accès à son SIREN réel côté SUPER PDP. Pour tester l'intégration
 * de bout en bout, on utilise les entreprises fictives du sandbox (Burger
 * Queen SIREN 000000002). Comme Dolibarr n'émet qu'au nom de $mysoc (SIREN
 * Lemon 802711796), l'API SUPER PDP rejette ("SIREN émetteur ne correspond
 * pas à l'entreprise du token").
 *
 * Contournement : quand la constante LEMONSUPERPDP_SANDBOX_MODE = 1,
 * on extrait le XML Factur-X du PDF, on remplace le SIREN émetteur par
 * la valeur du champ Dolibarr "Identifiant professionnel 6" (idprof6, ici
 * hijacké pour y stocker le SIREN sandbox 000000002), puis on envoie
 * le XML modifié en format CII.
 *
 * À SUPPRIMER quand Lemon a accès à son SIREN réel sur SUPER PDP :
 *   1. Retirer la constante LEMONSUPERPDP_SANDBOX_MODE du descripteur
 *      module (core/modules/modLemonSuperPDP.class.php)
 *   2. Retirer le bloc correspondant dans admin/setup.php (UI + sauvegarde)
 *   3. Retirer le bloc entre // >>> SANDBOX MODE <<< dans ce fichier
 *   4. Supprimer les clés de traduction LemonSuperPDPSandboxMode* dans
 *      langs/{fr_FR,en_US}/lemonsuperpdp.lang
 *   5. Vider la constante côté Dolibarr en prod : dolibarr_del_const
 *   6. Vider le champ idprof6 de la société si utilisé uniquement pour ça
 *
 * Un grep global sur "SANDBOX MODE" donne tous les points à nettoyer.
 * =============================================================================
 */

class ActionsLemonSuperPDP
{
	public $db;
	public $results = array();
	public $resprints;
	public $errors = array();

	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Retourne le chemin absolu du PDF de la facture (sans garantie d'existence).
	 */
	private function getInvoicePdfPath($invoice)
	{
		global $conf;
		$ref = dol_sanitizeFileName($invoice->ref);
		return $conf->facture->dir_output.'/'.$ref.'/'.$ref.'.pdf';
	}

	/**
	 * Affiche le badge de statut courant + le bouton d'envoi sur la fiche facture.
	 *
	 * @param array    $parameters   Contextes et paramètres
	 * @param object   $object       Objet courant (Facture attendue en contexte invoicecard)
	 * @param string   $action       Action en cours
	 * @param object   $hookmanager  Gestionnaire de hooks
	 * @return int                   0 par défaut
	 */
	public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user;

		if (!isModEnabled('lemonsuperpdp')) return 0;
		if (!getDolGlobalInt('LEMONSUPERPDP_ENABLED')) return 0;

		$contexts = explode(':', $parameters['context']);
		if (!in_array('invoicecard', $contexts)) return 0;
		if (!is_object($object) || !isset($object->element) || $object->element !== 'facture') return 0;

		$langs->load("lemonsuperpdp@lemonsuperpdp");

		dol_include_once('/lemonsuperpdp/class/transmission.class.php');
		$t = new LemonSuperPDPTransmission($this->db);
		$hasSuccess = $t->hasSuccessfulTransmission($object->id);
		$lastFetched = $t->fetchLastByFacture($object->id);

		// Bouton d'envoi : uniquement si facture validée et non déjà transmise avec succès
		if (!$hasSuccess) {
			if (!$user->hasRight('lemonsuperpdp', 'transmission', 'ecrire')) {
				return 0;
			}
			$canSend = ((int) $object->statut) >= 1;
			$url = $_SERVER['PHP_SELF'].'?action=dosendsuperpdp&id='.((int) $object->id).'&token='.newToken();
			if ($canSend) {
				print '<a class="butAction" href="'.dol_escape_htmltag($url).'" title="'.dol_escape_htmltag($langs->trans('LemonSuperPDPSendInvoiceTooltip')).'">';
				print img_picto('', 'fa-paper-plane', 'class="fas paddingright pictofixedwidth"');
				print $langs->trans('LemonSuperPDPSendInvoice');
				print '</a>';
			} else {
				print '<span class="butActionRefused classfortooltip" title="'.dol_escape_htmltag($langs->trans('LemonSuperPDPSendInvoiceDraft')).'">';
				print $langs->trans('LemonSuperPDPSendInvoice');
				print '</span>';
			}
		}

		return 0;
	}

	/**
	 * Affiche le bloc "Transmission SUPER PDP" dans la fiche facture (colonne latérale).
	 */
	public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		if (!isModEnabled('lemonsuperpdp')) return 0;
		if (!getDolGlobalInt('LEMONSUPERPDP_ENABLED')) return 0;

		$contexts = explode(':', $parameters['context']);
		if (!in_array('invoicecard', $contexts)) return 0;
		if (!is_object($object) || !isset($object->element) || $object->element !== 'facture') return 0;

		$langs->load("lemonsuperpdp@lemonsuperpdp");

		dol_include_once('/lemonsuperpdp/class/transmission.class.php');
		$t = new LemonSuperPDPTransmission($this->db);
		$found = $t->fetchLastByFacture($object->id);

		print '<tr><td>'.$langs->trans('LemonSuperPDPTransmissionLabel').'</td>';
		print '<td>';
		if ($found > 0) {
			print '<span class="badge '.$t->getBadgeClass().'">'.$langs->trans($t->getStatusLabelKey()).'</span>';
			if (!empty($t->superpdp_id)) {
				print ' <span class="opacitymedium">ID SUPER PDP : '.((int) $t->superpdp_id).'</span>';
			}
			if (!empty($t->date_sent)) {
				print '<br><span class="opacitymedium">'.$langs->trans('LemonSuperPDPSentOn').' '.dol_print_date($t->date_sent, 'dayhour').'</span>';
			}
			if ($t->status === LemonSuperPDPTransmission::STATUS_ERROR && !empty($t->error_message)) {
				print '<br><span style="color:#c00;">'.dol_escape_htmltag($t->error_message).'</span>';
			}

			// Historique des événements de cycle de vie.
			dol_include_once('/lemonsuperpdp/class/event.class.php');
			$evObj = new LemonSuperPDPEvent($this->db);
			$events = $evObj->listByFacture($object->id);
			if (is_array($events) && count($events) > 0) {
				print '<br><br><strong>'.$langs->trans('LemonSuperPDPEventsHistory').'</strong>';
				print '<table class="noborder centpercent" style="margin-top:4px;">';
				print '<tr class="liste_titre">';
				print '<td>'.$langs->trans('Date').'</td>';
				print '<td>'.$langs->trans('LemonSuperPDPEventMessage').'</td>';
				print '<td class="center">'.$langs->trans('LemonSuperPDPEventCode').'</td>';
				print '<td class="center">'.$langs->trans('LemonSuperPDPEventDirection').'</td>';
				print '</tr>';
				foreach ($events as $ev) {
					$label = !empty($ev->message) ? $ev->message : LemonSuperPDPEvent::getStatusLabel($ev->status_code);
					$badge = LemonSuperPDPEvent::getBadgeClass($ev->status_code);
					$dirLabel = ($ev->direction === LemonSuperPDPEvent::DIRECTION_OUT)
						? $langs->trans('LemonSuperPDPEventDirectionOut')
						: $langs->trans('LemonSuperPDPEventDirectionIn');
					print '<tr class="oddeven">';
					print '<td class="nowrap">'.dol_print_date($ev->event_date, 'dayhour').'</td>';
					print '<td>'.dol_escape_htmltag($label).'</td>';
					print '<td class="center"><span class="badge '.$badge.'">'.dol_escape_htmltag($ev->status_code).'</span></td>';
					print '<td class="center opacitymedium">'.dol_escape_htmltag($dirLabel).'</td>';
					print '</tr>';
				}
				print '</table>';
			}

			// Bouton de rafraîchissement + dropdown "Envoyer un statut".
			global $user;
			if ($user->hasRight('lemonsuperpdp', 'transmission', 'ecrire') && !empty($t->superpdp_id)) {
				$refreshUrl = $_SERVER['PHP_SELF'].'?action=refreshsuperpdpevents&id='.((int) $object->id).'&token='.newToken();
				print '<br><a href="'.dol_escape_htmltag($refreshUrl).'" class="button buttonSmall">';
				print img_picto('', 'refresh', 'class="pictofixedwidth"');
				print $langs->trans('LemonSuperPDPRefreshStatus');
				print '</a>';

				$emittable = LemonSuperPDPEvent::getEmittableStatuses();
				print ' <select id="lemonsuperpdp_status_select" class="flat" style="margin-left:8px;">';
				print '<option value="">'.$langs->trans('LemonSuperPDPSendStatusPrompt').'</option>';
				foreach ($emittable as $code) {
					print '<option value="'.$code.'">'.$code.' — '.dol_escape_htmltag(LemonSuperPDPEvent::getStatusLabel($code)).'</option>';
				}
				print '</select>';
				print ' <a href="#" id="lemonsuperpdp_send_status" class="button buttonSmall">'.$langs->trans('LemonSuperPDPSendStatusButton').'</a>';
				$confirmTxt = dol_escape_js($langs->trans('LemonSuperPDPSendStatusConfirm'));
				$selectTxt = dol_escape_js($langs->trans('LemonSuperPDPSendStatusPrompt'));
				$baseUrl = $_SERVER['PHP_SELF'].'?action=sendstatussuperpdp&id='.((int) $object->id).'&token='.newToken().'&status_code=';
				print '<script>
document.getElementById("lemonsuperpdp_send_status").addEventListener("click", function(e){
  e.preventDefault();
  var sel = document.getElementById("lemonsuperpdp_status_select");
  var code = sel.value;
  if (!code) { alert("'.$selectTxt.'"); return; }
  if (!confirm("'.$confirmTxt.' " + code)) return;
  window.location.href = "'.dol_escape_js($baseUrl).'" + encodeURIComponent(code);
});
</script>';
			}
			// >>> SANDBOX MODE — À SUPPRIMER APRÈS LA PHASE PILOTE <<<
			// Lien de réinitialisation visible uniquement en mode sandbox.
			// En prod réelle, on ne réinitialise pas une transmission : si la
			// facture a été envoyée par erreur, on émet un avoir Dolibarr.
			if (getDolGlobalInt('LEMONSUPERPDP_SANDBOX_MODE')) {
				global $user;
				if ($user->hasRight('lemonsuperpdp', 'transmission', 'ecrire')) {
					$resetUrl = $_SERVER['PHP_SELF'].'?action=resettransmissionsuperpdp&id='.((int) $object->id).'&token='.newToken();
					print '<br><a href="'.dol_escape_htmltag($resetUrl).'" class="opacitymedium" onclick="return confirm(\''.dol_escape_js($langs->trans('LemonSuperPDPResetConfirm')).'\')">';
					print img_picto('', 'delete', 'class="pictofixedwidth"');
					print $langs->trans('LemonSuperPDPResetTransmission');
					print '</a>';
				}
			}
			// >>> FIN SANDBOX MODE <<<
		} else {
			print '<span class="opacitymedium">'.$langs->trans('LemonSuperPDPNotTransmitted').'</span>';
		}
		print '</td></tr>';

		return 0;
	}

	/**
	 * Ajoute l'entrée "Envoyer via SUPER PDP" dans le menu déroulant des actions
	 * en masse sur la liste des factures.
	 */
	public function addMoreMassActions($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user;

		if (!isModEnabled('lemonsuperpdp')) return 0;
		if (!getDolGlobalInt('LEMONSUPERPDP_ENABLED')) return 0;
		if (!$user->hasRight('lemonsuperpdp', 'transmission', 'ecrire')) return 0;

		$contexts = explode(':', $parameters['context']);
		if (!in_array('invoicelist', $contexts)) return 0;

		$langs->load("lemonsuperpdp@lemonsuperpdp");
		$this->resprints = '<option value="dosendsuperpdp_bulk">'.dol_escape_htmltag($langs->trans('LemonSuperPDPSendSelected')).'</option>';
		return 0;
	}

	/**
	 * Traite l'action en masse "Envoyer via SUPER PDP" sur les factures sélectionnées.
	 */
	public function doMassActions($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user, $conf, $db;

		if ($action !== 'dosendsuperpdp_bulk') return 0;
		if (!isModEnabled('lemonsuperpdp')) return 0;
		if (!getDolGlobalInt('LEMONSUPERPDP_ENABLED')) return 0;

		$contexts = explode(':', $parameters['context']);
		if (!in_array('invoicelist', $contexts)) return 0;

		if (!$user->hasRight('lemonsuperpdp', 'transmission', 'ecrire')) {
			setEventMessages($langs->trans('NotEnoughPermissions'), null, 'errors');
			return 0;
		}

		$langs->load("lemonsuperpdp@lemonsuperpdp");

		$toselect = !empty($parameters['toselect']) && is_array($parameters['toselect']) ? $parameters['toselect'] : array();
		if (empty($toselect)) {
			setEventMessages($langs->trans('NoRecordSelected'), null, 'warnings');
			return 0;
		}

		require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

		$nbOk = 0;
		$nbKo = 0;
		$nbSkipped = 0;

		foreach ($toselect as $id) {
			$facture = new Facture($db);
			if ($facture->fetch((int) $id) <= 0) {
				$nbKo++;
				continue;
			}
			// On réutilise la même logique que l'envoi unitaire via doActions.
			// Pour garder le code simple et éviter la duplication, on met $action
			// à 'dosendsuperpdp' juste le temps d'un appel, puis on le restaure.
			$bulkAction = 'dosendsuperpdp';
			$this->doActions($parameters, $facture, $bulkAction, $hookmanager);
			// doActions réinitialise $bulkAction = ''. On regarde la dernière
			// transmission pour savoir si ça a passé.
			dol_include_once('/lemonsuperpdp/class/transmission.class.php');
			$t = new LemonSuperPDPTransmission($db);
			if ($t->fetchLastByFacture($facture->id) > 0) {
				if ($t->status === LemonSuperPDPTransmission::STATUS_SENT) {
					$nbOk++;
				} elseif ($t->status === LemonSuperPDPTransmission::STATUS_ERROR) {
					$nbKo++;
				} else {
					$nbSkipped++;
				}
			} else {
				$nbSkipped++;
			}
		}

		$summary = $langs->trans('LemonSuperPDPBulkResult', $nbOk, $nbKo, $nbSkipped);
		if ($nbKo === 0 && $nbOk > 0) {
			setEventMessages($summary, null, 'mesgs');
		} elseif ($nbKo > 0) {
			setEventMessages($summary, null, 'warnings');
		} else {
			setEventMessages($summary, null, 'mesgs');
		}

		return 0;
	}

	/**
	 * Intercepte les actions du module : dosendsuperpdp (envoi) et
	 * resettransmissionsuperpdp (reset sandbox).
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user, $conf;

		// Action : rafraîchir les events SUPER PDP pour la facture courante.
		if ($action === 'refreshsuperpdpevents') {
			if (!isModEnabled('lemonsuperpdp')) return 0;
			if (!is_object($object) || !isset($object->element) || $object->element !== 'facture') return 0;
			$langs->load("lemonsuperpdp@lemonsuperpdp");
			if (GETPOST('token', 'alpha') != newToken()) {
				setEventMessages('Bad CSRF token', null, 'errors');
				$action = '';
				return 0;
			}
			if (!$user->hasRight('lemonsuperpdp', 'transmission', 'lire')) {
				setEventMessages($langs->trans('NotEnoughPermissions'), null, 'errors');
				$action = '';
				return 0;
			}
			try {
				$nb = $this->refreshEventsForFacture($object, $user);
				setEventMessages($langs->trans('LemonSuperPDPRefreshDone', (int) $nb), null, 'mesgs');
			} catch (Exception $e) {
				dol_syslog('LemonSuperPDP refresh events: '.$e->getMessage(), LOG_ERR);
				setEventMessages($langs->trans('LemonSuperPDPRefreshError').' — '.$e->getMessage(), null, 'errors');
			}
			$action = '';
			return 0;
		}

		// Action : envoyer un statut de cycle de vie (fr:204..fr:212).
		if ($action === 'sendstatussuperpdp') {
			if (!isModEnabled('lemonsuperpdp')) return 0;
			if (!is_object($object) || !isset($object->element) || $object->element !== 'facture') return 0;
			$langs->load("lemonsuperpdp@lemonsuperpdp");
			if (GETPOST('token', 'alpha') != newToken()) {
				setEventMessages('Bad CSRF token', null, 'errors');
				$action = '';
				return 0;
			}
			if (!$user->hasRight('lemonsuperpdp', 'transmission', 'ecrire')) {
				setEventMessages($langs->trans('NotEnoughPermissions'), null, 'errors');
				$action = '';
				return 0;
			}
			$statusCode = GETPOST('status_code', 'alphanohtml');
			dol_include_once('/lemonsuperpdp/class/event.class.php');
			if (!in_array($statusCode, LemonSuperPDPEvent::getEmittableStatuses(), true)) {
				setEventMessages($langs->trans('LemonSuperPDPStatusInvalid').' : '.dol_escape_htmltag($statusCode), null, 'errors');
				$action = '';
				return 0;
			}
			try {
				$this->sendManualStatus($object, $user, $statusCode);
				setEventMessages($langs->trans('LemonSuperPDPStatusSent').' : '.$statusCode, null, 'mesgs');
			} catch (Exception $e) {
				dol_syslog('LemonSuperPDP send status: '.$e->getMessage(), LOG_ERR);
				setEventMessages($langs->trans('LemonSuperPDPStatusSendError').' — '.$e->getMessage(), null, 'errors');
			}
			$action = '';
			return 0;
		}

		// >>> SANDBOX MODE — À SUPPRIMER APRÈS LA PHASE PILOTE <<<
		if ($action === 'resettransmissionsuperpdp') {
			if (!isModEnabled('lemonsuperpdp')) return 0;
			if (!is_object($object) || !isset($object->element) || $object->element !== 'facture') return 0;
			$langs->load("lemonsuperpdp@lemonsuperpdp");
			if (GETPOST('token', 'alpha') != newToken()) {
				setEventMessages('Bad CSRF token', null, 'errors');
				$action = '';
				return 0;
			}
			if (!$user->hasRight('lemonsuperpdp', 'transmission', 'ecrire')) {
				setEventMessages($langs->trans('NotEnoughPermissions'), null, 'errors');
				$action = '';
				return 0;
			}
			dol_include_once('/lemonsuperpdp/class/transmission.class.php');
			$t = new LemonSuperPDPTransmission($this->db);
			$nb = $t->deleteAllForFacture($object->id);
			if ($nb < 0) {
				setEventMessages($langs->trans('LemonSuperPDPResetError'), null, 'errors');
			} else {
				setEventMessages($langs->trans('LemonSuperPDPResetDone', (int) $nb), null, 'mesgs');
			}
			$action = '';
			return 0;
		}
		// >>> FIN SANDBOX MODE <<<

		if ($action !== 'dosendsuperpdp') return 0;
		if (!isModEnabled('lemonsuperpdp')) return 0;
		if (!getDolGlobalInt('LEMONSUPERPDP_ENABLED')) return 0;
		if (!is_object($object) || !isset($object->element) || $object->element !== 'facture') return 0;

		$langs->load("lemonsuperpdp@lemonsuperpdp");

		if (GETPOST('token', 'alpha') != newToken()) {
			setEventMessages('Bad CSRF token', null, 'errors');
			$action = '';
			return 0;
		}

		if (!$user->hasRight('lemonsuperpdp', 'transmission', 'ecrire')) {
			setEventMessages($langs->trans('NotEnoughPermissions'), null, 'errors');
			$action = '';
			return 0;
		}

		if (((int) $object->statut) < 1) {
			setEventMessages($langs->trans('LemonSuperPDPSendInvoiceDraft'), null, 'errors');
			$action = '';
			return 0;
		}

		dol_include_once('/lemonsuperpdp/class/transmission.class.php');
		$t = new LemonSuperPDPTransmission($this->db);
		if ($t->hasSuccessfulTransmission($object->id)) {
			setEventMessages($langs->trans('LemonSuperPDPAlreadySent'), null, 'warnings');
			$action = '';
			return 0;
		}

		$pdfPath = $this->getInvoicePdfPath($object);
		if (!file_exists($pdfPath)) {
			setEventMessages($langs->trans('LemonSuperPDPPdfNotFound').' : '.$pdfPath, null, 'errors');
			$action = '';
			return 0;
		}

		$format = getDolGlobalString('LEMONSUPERPDP_FORMAT', 'facturx');
		$formatSent = $format;
		$fileToSend = $pdfPath;

		// Créer la ligne transmission en pending
		$t->fk_facture = $object->id;
		$t->format_sent = $format;
		$t->status = LemonSuperPDPTransmission::STATUS_PENDING;
		$ret = $t->create($user);
		if ($ret < 0) {
			dol_syslog('LemonSuperPDP: erreur création transmission : '.$t->error, LOG_ERR);
			setEventMessages($langs->trans('LemonSuperPDPCreateError'), null, 'errors');
			$action = '';
			return 0;
		}

		// >>> SANDBOX MODE — À SUPPRIMER APRÈS LA PHASE PILOTE <<<
		// Contournement phase pilote : le SIREN émetteur réel ($mysoc->idprof2)
		// ne correspond pas à l'entreprise du token OAuth (cf. en-tête fichier).
		// On extrait le XML du PDF Factur-X, on remplace le SIREN, on envoie en CII.
		// Tout le bloc ci-dessous est à supprimer quand Lemon aura son SIREN
		// réel validé côté SUPER PDP.
		$sandboxMode = (bool) getDolGlobalInt('LEMONSUPERPDP_SANDBOX_MODE');
		$tmpXmlPath = null;
		if ($sandboxMode) {
			global $mysoc;
			$fakeSiren = !empty($mysoc->idprof6) ? preg_replace('/[^0-9]/', '', $mysoc->idprof6) : '';
			$realSiren = !empty($mysoc->idprof2) ? substr(preg_replace('/[^0-9]/', '', $mysoc->idprof2), 0, 9) : '';
			if (empty($fakeSiren)) {
				$t->status = LemonSuperPDPTransmission::STATUS_ERROR;
				$t->error_message = $langs->trans('LemonSuperPDPSandboxModeIdProf6Missing');
				$t->update($user);
				setEventMessages($t->error_message, null, 'errors');
				$action = '';
				return 0;
			}
			// Récupération du SIREN réel du destinataire pour le remplacer par
			// celui de Tricatel sandbox (000000001). Sans ça, SUPER PDP refuse
			// parce que l'annuaire sandbox ne connaît pas le SIREN du vrai client.
			$realClientSiren = '';
			if (empty($object->thirdparty)) {
				$object->fetch_thirdparty();
			}
			if (!empty($object->thirdparty)) {
				$clientSrc = !empty($object->thirdparty->idprof1) ? $object->thirdparty->idprof1 : $object->thirdparty->idprof2;
				$realClientSiren = substr(preg_replace('/[^0-9]/', '', (string) $clientSrc), 0, 9);
			}
			$fakeClientSiren = '000000001';  // Tricatel sandbox SUPER PDP

			try {
				// On réutilise la lib atgp/factur-x déjà embarquée dans LemonFacturX
				// (pas de dépendance composer dans LemonSuperPDP).
				$facturxVendor = DOL_DOCUMENT_ROOT.'/custom/lemonfacturx/vendor/autoload.php';
				if (!file_exists($facturxVendor)) {
					throw new Exception('Autoload LemonFacturX introuvable : '.$facturxVendor);
				}
				require_once $facturxVendor;
				$reader = new \Atgp\FacturX\Reader();
				$pdfBinary = file_get_contents($pdfPath);
				$xml = $reader->extractXML($pdfBinary, false);

				// Substitutions ciblées : SIREN + numéro TVA intracommunautaire.
				// Règle BR-FR-09 : la clé TVA doit être cohérente avec le SIREN,
				// formule FR : clé = (12 + 3 × (SIREN mod 97)) mod 97.
				// Sans swap du numéro TVA, SUPER PDP rejette la facture comme
				// "Invalide" (incohérence entre SIREN et racine du VAT FR).
				$calcVat = function ($siren) {
					$s = (int) preg_replace('/[^0-9]/', '', $siren);
					$key = ((12 + 3 * ($s % 97)) % 97);
					return 'FR'.str_pad((string) $key, 2, '0', STR_PAD_LEFT).str_pad((string) $s, 9, '0', STR_PAD_LEFT);
				};
				$fakeVendorVat = $calcVat($fakeSiren);
				$realVendorVat = !empty($mysoc->tva_intra) ? preg_replace('/\s+/', '', $mysoc->tva_intra) : '';
				$fakeClientVat = $calcVat($fakeClientSiren);
				$realClientVat = '';
				if (!empty($object->thirdparty) && !empty($object->thirdparty->tva_intra)) {
					$realClientVat = preg_replace('/\s+/', '', $object->thirdparty->tva_intra);
				}

				$search = array('>'.$realSiren.'<', '"'.$realSiren.'"');
				$replace = array('>'.$fakeSiren.'<', '"'.$fakeSiren.'"');
				if (strlen($realClientSiren) === 9 && $realClientSiren !== $realSiren) {
					$search[] = '>'.$realClientSiren.'<';
					$search[] = '"'.$realClientSiren.'"';
					$replace[] = '>'.$fakeClientSiren.'<';
					$replace[] = '"'.$fakeClientSiren.'"';
				}
				if (!empty($realVendorVat)) {
					$search[] = '>'.$realVendorVat.'<';
					$search[] = '"'.$realVendorVat.'"';
					$replace[] = '>'.$fakeVendorVat.'<';
					$replace[] = '"'.$fakeVendorVat.'"';
				}
				if (!empty($realClientVat) && $realClientVat !== $realVendorVat) {
					$search[] = '>'.$realClientVat.'<';
					$search[] = '"'.$realClientVat.'"';
					$replace[] = '>'.$fakeClientVat.'<';
					$replace[] = '"'.$fakeClientVat.'"';
				}
				$patched = str_replace($search, $replace, $xml);

				$tmpXmlPath = tempnam(sys_get_temp_dir(), 'lemonspd_').'.xml';
				file_put_contents($tmpXmlPath, $patched);
				$fileToSend = $tmpXmlPath;
				$formatSent = 'cii';
				$t->format_sent = 'cii-sandbox';
				dol_syslog('LemonSuperPDP [SANDBOX]: vendeur '.$realSiren.'->'.$fakeSiren.', client '.$realClientSiren.'->'.$fakeClientSiren.', envoi en CII', LOG_WARNING);
			} catch (Exception $e) {
				dol_syslog('LemonSuperPDP [SANDBOX]: échec extraction/patch XML : '.$e->getMessage(), LOG_ERR);
				$t->status = LemonSuperPDPTransmission::STATUS_ERROR;
				$t->error_message = 'Mode sandbox : '.$e->getMessage();
				$t->update($user);
				setEventMessages($t->error_message, null, 'errors');
				$action = '';
				return 0;
			}
		}
		// >>> FIN SANDBOX MODE <<<

		dol_include_once('/lemonsuperpdp/class/superpdp_client.class.php');
		try {
			$client = new SuperPDPClient($this->db);
			$response = $client->submitInvoice($fileToSend, $formatSent);

			$t->superpdp_id = !empty($response['id']) ? (int) $response['id'] : null;
			$t->status = LemonSuperPDPTransmission::STATUS_SENT;
			$t->payload_response = json_encode($response);
			$t->update($user);

			$msg = $langs->trans('LemonSuperPDPSendSuccess');
			if (!empty($t->superpdp_id)) {
				$msg .= ' — ID SUPER PDP : '.((int) $t->superpdp_id);
			}
			// >>> SANDBOX MODE <<<
			if ($sandboxMode) {
				$msg .= ' ('.$langs->trans('LemonSuperPDPSandboxModeSent').')';
			}
			// >>> FIN SANDBOX MODE <<<
			setEventMessages($msg, null, 'mesgs');
		} catch (SuperPDPException $e) {
			dol_syslog('LemonSuperPDP: échec envoi facture '.$object->ref.' : '.$e->getMessage(), LOG_ERR);
			// Cas edge : SUPER PDP nous dit que la facture est déjà présente.
			// Le message contient l'id de la facture existante (ex : "La facture
			// est déjà existante (id 39519)"). On en profite pour recoller la
			// transmission locale à la facture distante plutôt que rester en
			// état Error (évite de créer une nouvelle facture côté SUPER PDP
			// juste parce qu'on a réinitialisé en local).
			$recovered = false;
			if ($e->httpCode === 400 && preg_match('/id\s+(\d+)/', $e->getMessage(), $m)) {
				$t->superpdp_id = (int) $m[1];
				$t->status = LemonSuperPDPTransmission::STATUS_SENT;
				$t->error_message = null;
				$t->payload_response = json_encode(array('recovered' => true, 'source_message' => $e->getMessage()));
				$t->update($user);
				$recovered = true;
				setEventMessages($langs->trans('LemonSuperPDPRecoveredExisting', (int) $t->superpdp_id), null, 'warnings');
			}
			if (!$recovered) {
				$t->status = LemonSuperPDPTransmission::STATUS_ERROR;
				$t->error_message = $e->getMessage();
				if ($e->responseBody) {
					$t->payload_response = $e->responseBody;
				}
				$t->update($user);
				setEventMessages($langs->trans('LemonSuperPDPSendError').' — '.$e->getMessage(), null, 'errors');
			}
		} catch (Exception $e) {
			dol_syslog('LemonSuperPDP: exception envoi : '.$e->getMessage(), LOG_ERR);
			$t->status = LemonSuperPDPTransmission::STATUS_ERROR;
			$t->error_message = $e->getMessage();
			$t->update($user);
			setEventMessages($langs->trans('LemonSuperPDPSendError').' — '.$e->getMessage(), null, 'errors');
		}

		// >>> SANDBOX MODE <<<
		if ($tmpXmlPath !== null && file_exists($tmpXmlPath)) {
			@unlink($tmpXmlPath);
		}
		// >>> FIN SANDBOX MODE <<<

		$action = '';
		return 0;
	}

	/**
	 * Récupère les events SUPER PDP pour une facture donnée et les insère
	 * en base s'ils n'existent pas déjà. Appelé par le bouton "Rafraîchir"
	 * sur la fiche facture et par le cron de synchronisation.
	 *
	 * @return int Nombre d'events nouvellement insérés
	 */
	public function refreshEventsForFacture($facture, $user)
	{
		dol_include_once('/lemonsuperpdp/class/transmission.class.php');
		dol_include_once('/lemonsuperpdp/class/event.class.php');
		dol_include_once('/lemonsuperpdp/class/superpdp_client.class.php');

		$t = new LemonSuperPDPTransmission($this->db);
		if ($t->fetchLastByFacture($facture->id) <= 0 || empty($t->superpdp_id)) {
			return 0;
		}

		$client = new SuperPDPClient($this->db);
		$data = $client->getInvoice((int) $t->superpdp_id);

		$nbInserted = 0;
		$evObj = new LemonSuperPDPEvent($this->db);

		// L'objet facture SUPER PDP contient un champ invoice_events étendu
		// depuis l'API v1.9.0. Fallback : liste globale filtrée.
		$events = array();
		if (!empty($data['invoice_events']) && is_array($data['invoice_events'])) {
			$events = $data['invoice_events'];
		}

		foreach ($events as $ev) {
			if (empty($ev['id']) || empty($ev['status_code'])) continue;
			if ($evObj->existsBySuperpdpId((int) $ev['id'])) continue;

			$newEv = new LemonSuperPDPEvent($this->db);
			$newEv->fk_transmission = $t->id;
			$newEv->superpdp_event_id = (int) $ev['id'];
			$newEv->status_code = (string) $ev['status_code'];
			$newEv->message = !empty($ev['message']) ? (string) $ev['message'] : null;
			$newEv->direction = LemonSuperPDPEvent::DIRECTION_IN;
			$newEv->event_date = !empty($ev['created_at']) ? strtotime($ev['created_at']) : dol_now();
			$newEv->payload_raw = json_encode($ev);
			if ($newEv->create($user) > 0) {
				$nbInserted++;
			}
		}

		// Met à jour le statut de la transmission selon le dernier event connu.
		if ($nbInserted > 0 || !empty($events)) {
			$lastCode = '';
			$lastTs = 0;
			foreach ($events as $ev) {
				$ts = !empty($ev['created_at']) ? strtotime($ev['created_at']) : 0;
				if ($ts >= $lastTs) {
					$lastTs = $ts;
					$lastCode = $ev['status_code'];
				}
			}
			if (!empty($lastCode)) {
				$map = array(
					'fr:200' => LemonSuperPDPTransmission::STATUS_SENT,
					'fr:202' => LemonSuperPDPTransmission::STATUS_ACCEPTED,
					'fr:204' => LemonSuperPDPTransmission::STATUS_ACCEPTED,
					'fr:206' => LemonSuperPDPTransmission::STATUS_ACCEPTED,
					'fr:212' => LemonSuperPDPTransmission::STATUS_PAID,
					'fr:201' => LemonSuperPDPTransmission::STATUS_REFUSED,
					'fr:203' => LemonSuperPDPTransmission::STATUS_REFUSED,
					'fr:210' => LemonSuperPDPTransmission::STATUS_REFUSED,
				);
				if (isset($map[$lastCode])) {
					$t->status = $map[$lastCode];
				}
				$t->status_raw = $lastCode;
				$t->update($user);
			}
		}

		return $nbInserted;
	}

	/**
	 * Envoie un statut de cycle de vie manuellement. Pour fr:212 et fr:207,
	 * construit automatiquement les montants ventilés par taux TVA à partir
	 * des lignes de la facture.
	 */
	public function sendManualStatus($facture, $user, $statusCode)
	{
		dol_include_once('/lemonsuperpdp/class/transmission.class.php');
		dol_include_once('/lemonsuperpdp/class/event.class.php');
		dol_include_once('/lemonsuperpdp/class/superpdp_client.class.php');

		$t = new LemonSuperPDPTransmission($this->db);
		if ($t->fetchLastByFacture($facture->id) <= 0 || empty($t->superpdp_id)) {
			throw new Exception('Aucune transmission avec ID SUPER PDP pour cette facture');
		}

		$details = array();
		if ($statusCode === 'fr:212' || $statusCode === 'fr:207') {
			if (empty($facture->lines)) {
				$facture->fetch_lines();
			}
			$amountsByRate = array();
			foreach ($facture->lines as $line) {
				$rate = (float) $line->tva_tx;
				$key = number_format($rate, 1, '.', '');
				if (!isset($amountsByRate[$key])) {
					$amountsByRate[$key] = 0.0;
				}
				$amountsByRate[$key] += (float) $line->total_ht;
			}
			$today = date('Y-m-d');
			$amounts = array();
			foreach ($amountsByRate as $rate => $netAmount) {
				$amounts[] = array(
					'net_amount' => number_format($netAmount, 2, '.', ''),
					'currency_code' => 'EUR',
					'type_code' => 'MEN',
					'vat_rate' => $rate,
					'date' => $today,
				);
			}
			$details = array(array('amounts' => $amounts));
		}

		$client = new SuperPDPClient($this->db);
		$response = $client->submitEvent((int) $t->superpdp_id, $statusCode, $details);

		$ev = new LemonSuperPDPEvent($this->db);
		$ev->fk_transmission = $t->id;
		$ev->superpdp_event_id = !empty($response['id']) ? (int) $response['id'] : null;
		$ev->status_code = $statusCode;
		$ev->message = LemonSuperPDPEvent::getStatusLabel($statusCode);
		$ev->direction = LemonSuperPDPEvent::DIRECTION_OUT;
		$ev->event_date = dol_now();
		$ev->payload_raw = json_encode($response);
		$ev->create($user);

		if ($statusCode === 'fr:212') {
			$t->status = LemonSuperPDPTransmission::STATUS_PAID;
		}
		$t->status_raw = $statusCode;
		$t->update($user);
	}
}
