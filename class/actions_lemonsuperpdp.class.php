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

	/** Cache mémoire de la dernière transmission par facture (hot-path fiche). */
	private $transmissionCache = array();

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
	 * Retourne la dernière transmission d'une facture, en mémo-cache pour
	 * éviter 2-3 queries SQL redondantes sur le même affichage de fiche
	 * (les hooks addMoreActionsButtons + formObjectOptions sont appelés
	 * l'un après l'autre sur le même request).
	 *
	 * @param int $fkFacture
	 * @return LemonSuperPDPTransmission|null  Objet hydraté, ou null si aucune transmission.
	 */
	private function getLastTransmission($fkFacture)
	{
		$fkFacture = (int) $fkFacture;
		if (array_key_exists($fkFacture, $this->transmissionCache)) {
			return $this->transmissionCache[$fkFacture];
		}
		dol_include_once('/lemonsuperpdp/class/transmission.class.php');
		$t = new LemonSuperPDPTransmission($this->db);
		$found = $t->fetchLastByFacture($fkFacture);
		$this->transmissionCache[$fkFacture] = ($found > 0) ? $t : null;
		return $this->transmissionCache[$fkFacture];
	}

	/**
	 * Garde-fou commun à tous les hooks du module : module activé, option
	 * globale à ON, contexte hook attendu, objet de type facture.
	 *
	 * @param array    $parameters      Paramètres du hook courant
	 * @param object   $object          Objet courant du hook
	 * @param string[] $allowedContexts Contextes hook acceptés (ex: ['invoicecard'])
	 * @return bool                     true si on peut continuer, false sinon
	 */
	private function isInvoiceContextAllowed($parameters, $object, array $allowedContexts)
	{
		if (!isModEnabled('lemonsuperpdp')) return false;
		if (!getDolGlobalInt('LEMONSUPERPDP_ENABLED')) return false;

		$contexts = explode(':', $parameters['context']);
		if (empty(array_intersect($allowedContexts, $contexts))) return false;

		if (!is_object($object) || !isset($object->element) || $object->element !== 'facture') return false;

		return true;
	}

	/**
	 * Vérifie CSRF + permission et renvoie vrai si tout est OK. Si non,
	 * pose un setEventMessages d'erreur et réinitialise $action.
	 *
	 * @param string $action      Action courante (passée par référence, vidée sur échec)
	 * @param string $right       Code de permission ('ecrire' ou 'lire')
	 * @return bool               true si OK, false si refus
	 */
	private function checkCsrfAndRight(&$action, $right)
	{
		global $langs, $user;

		if (GETPOST('token', 'alpha') != newToken()) {
			setEventMessages('Bad CSRF token', null, 'errors');
			$action = '';
			return false;
		}
		if (!$user->hasRight('lemonsuperpdp', 'transmission', $right)) {
			setEventMessages($langs->trans('NotEnoughPermissions'), null, 'errors');
			$action = '';
			return false;
		}
		return true;
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

		if (!$this->isInvoiceContextAllowed($parameters, $object, array('invoicecard'))) return 0;

		$langs->load("lemonsuperpdp@lemonsuperpdp");

		$t = $this->getLastTransmission($object->id);
		$hasSuccess = ($t !== null && in_array($t->status, array(
			LemonSuperPDPTransmission::STATUS_SENT,
			LemonSuperPDPTransmission::STATUS_ACCEPTED,
			LemonSuperPDPTransmission::STATUS_PAID,
		), true));

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
		global $langs, $user;

		if (!$this->isInvoiceContextAllowed($parameters, $object, array('invoicecard'))) return 0;

		$langs->load("lemonsuperpdp@lemonsuperpdp");

		$t = $this->getLastTransmission($object->id);

		print '<tr><td>'.$langs->trans('LemonSuperPDPTransmissionLabel').'</td>';
		print '<td>';
		if ($t !== null) {
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

			// Actions : rafraîchir + envoyer un statut, en liens inline discrets.
			if ($user->hasRight('lemonsuperpdp', 'transmission', 'ecrire') && !empty($t->superpdp_id)) {
				$refreshUrl = $_SERVER['PHP_SELF'].'?action=refreshsuperpdpevents&id='.((int) $object->id).'&token='.newToken();
				$emittable = LemonSuperPDPEvent::getEmittableStatuses();
				$confirmTxt = dol_escape_js($langs->trans('LemonSuperPDPSendStatusConfirm'));
				$selectTxt = dol_escape_js($langs->trans('LemonSuperPDPSendStatusPrompt'));
				$baseUrl = $_SERVER['PHP_SELF'].'?action=sendstatussuperpdp&id='.((int) $object->id).'&token='.newToken().'&status_code=';

				print '<div class="inline-block valignmiddle" style="margin-top:6px;">';
				print '<a href="'.dol_escape_htmltag($refreshUrl).'" class="valignmiddle" title="'.dol_escape_htmltag($langs->trans('LemonSuperPDPRefreshStatus')).'">';
				print img_picto($langs->trans('LemonSuperPDPRefreshStatus'), 'refresh', 'class="paddingright"');
				print '</a>';
				print '<select id="lemonsuperpdp_status_select" class="flat minwidth150 marginleftonly valignmiddle">';
				print '<option value="">'.$langs->trans('LemonSuperPDPSendStatusPrompt').'</option>';
				foreach ($emittable as $code) {
					print '<option value="'.$code.'">'.$code.' — '.dol_escape_htmltag(LemonSuperPDPEvent::getStatusLabel($code)).'</option>';
				}
				print '</select>';
				print ' <a href="#" id="lemonsuperpdp_send_status" class="valignmiddle" title="'.dol_escape_htmltag($langs->trans('LemonSuperPDPSendStatusButton')).'">';
				print img_picto($langs->trans('LemonSuperPDPSendStatusButton'), 'paper-plane', 'class="fas"');
				print '</a>';
				print '</div>';

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
	 * Remarque : on ne passe pas par isInvoiceContextAllowed() ici parce que
	 * le contexte invoicelist transporte un objet liste (pas une facture),
	 * donc le test object->element !== 'facture' invaliderait toujours le hook.
	 */

	/**
	 * Traite l'action en masse "Envoyer via SUPER PDP" sur les factures sélectionnées.
	 */
	public function doMassActions($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user, $db;

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
			$outcome = $this->sendOneInvoice($facture, $user);
			switch ($outcome) {
				case 'ok':       $nbOk++; break;
				case 'error':    $nbKo++; break;
				default:         $nbSkipped++; break;
			}
		}

		$summary = $langs->trans('LemonSuperPDPBulkResult', $nbOk, $nbKo, $nbSkipped);
		$style = ($nbKo > 0) ? 'warnings' : 'mesgs';
		setEventMessages($summary, null, $style);

		return 0;
	}

	/**
	 * Intercepte les actions du module : dosendsuperpdp (envoi),
	 * refreshsuperpdpevents (rafraîchir events), sendstatussuperpdp
	 * (émettre un statut manuel) et resettransmissionsuperpdp (reset sandbox).
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		// Les 4 actions exigent toutes module activé + objet facture.
		if (!in_array($action, array('refreshsuperpdpevents', 'sendstatussuperpdp', 'resettransmissionsuperpdp', 'dosendsuperpdp'), true)) {
			return 0;
		}
		if (!isModEnabled('lemonsuperpdp')) return 0;
		if (!is_object($object) || !isset($object->element) || $object->element !== 'facture') return 0;
		$langs->load("lemonsuperpdp@lemonsuperpdp");

		switch ($action) {
			case 'refreshsuperpdpevents':
				return $this->handleRefreshEvents($object, $action);
			case 'sendstatussuperpdp':
				return $this->handleSendStatus($object, $action);
			// >>> SANDBOX MODE — À SUPPRIMER APRÈS LA PHASE PILOTE <<<
			case 'resettransmissionsuperpdp':
				return $this->handleResetTransmission($object, $action);
			// >>> FIN SANDBOX MODE <<<
			case 'dosendsuperpdp':
				// dosendsuperpdp exige aussi le flag global actif.
				if (!getDolGlobalInt('LEMONSUPERPDP_ENABLED')) return 0;
				return $this->handleSendInvoice($object, $action);
		}

		return 0;
	}

	/**
	 * Rafraîchit les events SUPER PDP pour la facture courante depuis la fiche.
	 */
	private function handleRefreshEvents(&$object, &$action)
	{
		global $langs, $user;

		if (!$this->checkCsrfAndRight($action, 'lire')) return 0;
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

	/**
	 * Émet manuellement un statut de cycle de vie (fr:204..fr:212).
	 */
	private function handleSendStatus(&$object, &$action)
	{
		global $langs, $user;

		if (!$this->checkCsrfAndRight($action, 'ecrire')) return 0;

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
	/**
	 * Réinitialise toutes les transmissions d'une facture (utilitaire sandbox
	 * uniquement, pas pour la prod : en prod réelle, on émet un avoir).
	 */
	private function handleResetTransmission(&$object, &$action)
	{
		global $langs;

		if (!$this->checkCsrfAndRight($action, 'ecrire')) return 0;

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

	/**
	 * Handler action 'dosendsuperpdp' : CSRF + permission, délégation à
	 * sendOneInvoice(), puis traduction de l'outcome en setEventMessages.
	 * Réutilise sendOneInvoice() pour partager la logique avec le bulk.
	 */
	private function handleSendInvoice(&$object, &$action)
	{
		global $langs, $user;

		if (!$this->checkCsrfAndRight($action, 'ecrire')) return 0;

		$result = $this->sendOneInvoice($object, $user);
		$msg = !empty($result['message']) ? $result['message'] : '';

		switch ($result['outcome']) {
			case 'ok':
				setEventMessages($msg !== '' ? $msg : $langs->trans('LemonSuperPDPSendSuccess'), null, 'mesgs');
				break;
			case 'ok-recovered':
				setEventMessages($msg, null, 'warnings');
				break;
			case 'skipped-already':
				setEventMessages($langs->trans('LemonSuperPDPAlreadySent'), null, 'warnings');
				break;
			case 'skipped-draft':
			case 'skipped-nopdf':
			case 'skipped-create':
			case 'error':
			default:
				setEventMessages($msg !== '' ? $msg : $langs->trans('LemonSuperPDPSendError'), null, 'errors');
				break;
		}

		$this->transmissionCache = array(); // le statut vient de changer, invalide le cache
		$action = '';
		return 0;
	}

	/**
	 * Envoi effectif d'une facture à SUPER PDP : crée la ligne transmission,
	 * applique le patch sandbox si activé, appelle l'API, met à jour le
	 * statut de la transmission. Ne touche JAMAIS à $action ni
	 * setEventMessages : appelé à la fois par le handler unitaire et par la
	 * boucle bulk, chaque appelant traduit l'outcome à sa sauce.
	 *
	 * @param Facture $facture  Facture Dolibarr (fetch déjà fait)
	 * @param User    $user     Utilisateur qui déclenche l'envoi
	 * @return array{outcome:string, message:string, transmissionId?:int}
	 *         outcome ∈ {ok, ok-recovered, error, skipped-draft,
	 *         skipped-already, skipped-nopdf, skipped-create}
	 */
	public function sendOneInvoice($facture, $user)
	{
		global $langs;

		if (((int) $facture->statut) < 1) {
			return array('outcome' => 'skipped-draft', 'message' => $langs->trans('LemonSuperPDPSendInvoiceDraft'));
		}

		dol_include_once('/lemonsuperpdp/class/transmission.class.php');
		$t = new LemonSuperPDPTransmission($this->db);
		if ($t->hasSuccessfulTransmission($facture->id)) {
			return array('outcome' => 'skipped-already', 'message' => $langs->trans('LemonSuperPDPAlreadySent'));
		}

		$pdfPath = $this->getInvoicePdfPath($facture);
		if (!file_exists($pdfPath)) {
			// On ne renvoie PAS le chemin absolu dans l'UI : le syslog suffit
			// côté admin, et on évite d'exposer la structure filesystem à un
			// utilisateur qui aurait seulement la permission 'ecrire'.
			dol_syslog('LemonSuperPDP: PDF introuvable pour facture '.$facture->ref.' : '.$pdfPath, LOG_WARNING);
			return array('outcome' => 'skipped-nopdf', 'message' => $langs->trans('LemonSuperPDPPdfNotFound'));
		}

		$format = getDolGlobalString('LEMONSUPERPDP_FORMAT', 'facturx');
		$formatSent = $format;
		$fileToSend = $pdfPath;

		// Ligne transmission en pending dès le début : trace l'intention
		// d'envoi même si l'API SUPER PDP plante derrière.
		$t->fk_facture = $facture->id;
		$t->format_sent = $format;
		$t->status = LemonSuperPDPTransmission::STATUS_PENDING;
		if ($t->create($user) < 0) {
			dol_syslog('LemonSuperPDP: erreur création transmission : '.$t->error, LOG_ERR);
			return array('outcome' => 'skipped-create', 'message' => $langs->trans('LemonSuperPDPCreateError'));
		}

		$sandboxMode = (bool) getDolGlobalInt('LEMONSUPERPDP_SANDBOX_MODE');
		$tmpXmlPath = null;
		if ($sandboxMode) {
			try {
				$patched = $this->applySandboxXmlPatch($facture, $pdfPath);
				$tmpXmlPath = $patched['tmpPath'];
				$fileToSend = $tmpXmlPath;
				$formatSent = 'cii';
				$t->format_sent = 'cii-sandbox';
			} catch (Exception $e) {
				dol_syslog('LemonSuperPDP [SANDBOX]: '.$e->getMessage(), LOG_ERR);
				$t->status = LemonSuperPDPTransmission::STATUS_ERROR;
				$t->error_message = 'Mode sandbox : '.$e->getMessage();
				$t->update($user);
				return array(
					'outcome' => 'error',
					'message' => $t->error_message,
					'transmissionId' => (int) $t->id,
				);
			}
		}

		dol_include_once('/lemonsuperpdp/class/superpdp_client.class.php');
		$outcome = array('outcome' => 'error', 'message' => '', 'transmissionId' => (int) $t->id);
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
			if ($sandboxMode) {
				$msg .= ' ('.$langs->trans('LemonSuperPDPSandboxModeSent').')';
			}
			$outcome = array('outcome' => 'ok', 'message' => $msg, 'transmissionId' => (int) $t->id);
		} catch (SuperPDPException $e) {
			dol_syslog('LemonSuperPDP: échec envoi facture '.$facture->ref.' : '.$e->getMessage(), LOG_ERR);
			// Cas edge : SUPER PDP nous dit que la facture est déjà présente.
			// Le message contient l'id de la facture existante (ex : "La facture
			// est déjà existante (id 39519)"). On en profite pour recoller la
			// transmission locale à la facture distante plutôt que rester en
			// état Error (évite de créer une nouvelle facture côté SUPER PDP
			// juste parce qu'on a réinitialisé en local).
			if ($e->httpCode === 400 && preg_match('/id\s+(\d+)/', $e->getMessage(), $m)) {
				$t->superpdp_id = (int) $m[1];
				$t->status = LemonSuperPDPTransmission::STATUS_SENT;
				$t->error_message = null;
				$t->payload_response = json_encode(array('recovered' => true, 'source_message' => $e->getMessage()));
				$t->update($user);
				$outcome = array(
					'outcome' => 'ok-recovered',
					'message' => $langs->trans('LemonSuperPDPRecoveredExisting', (int) $t->superpdp_id),
					'transmissionId' => (int) $t->id,
				);
			} else {
				$t->status = LemonSuperPDPTransmission::STATUS_ERROR;
				$t->error_message = $e->getMessage();
				if ($e->responseBody) {
					$t->payload_response = $e->responseBody;
				}
				$t->update($user);
				$outcome = array(
					'outcome' => 'error',
					'message' => $langs->trans('LemonSuperPDPSendError').' — '.$e->getMessage(),
					'transmissionId' => (int) $t->id,
				);
			}
		} catch (Exception $e) {
			dol_syslog('LemonSuperPDP: exception envoi : '.$e->getMessage(), LOG_ERR);
			$t->status = LemonSuperPDPTransmission::STATUS_ERROR;
			$t->error_message = $e->getMessage();
			$t->update($user);
			$outcome = array(
				'outcome' => 'error',
				'message' => $langs->trans('LemonSuperPDPSendError').' — '.$e->getMessage(),
				'transmissionId' => (int) $t->id,
			);
		}

		if ($tmpXmlPath !== null && file_exists($tmpXmlPath)) {
			@unlink($tmpXmlPath);
		}

		return $outcome;
	}

	// >>> SANDBOX MODE — À SUPPRIMER APRÈS LA PHASE PILOTE <<<
	/**
	 * Extrait le XML Factur-X du PDF, remplace SIREN et numéro TVA émetteur/
	 * destinataire par les valeurs sandbox et écrit le résultat dans un
	 * fichier temporaire. Retourne {tmpPath, realSiren, fakeSiren}.
	 *
	 * Règle BR-FR-09 : la clé TVA doit être cohérente avec le SIREN, formule
	 * FR : clé = (12 + 3 × (SIREN mod 97)) mod 97. Sans swap du numéro TVA,
	 * SUPER PDP rejette la facture pour incohérence SIREN/VAT.
	 *
	 * @throws Exception
	 */
	private function applySandboxXmlPatch($facture, $pdfPath)
	{
		global $mysoc, $langs;

		$fakeSiren = !empty($mysoc->idprof6) ? preg_replace('/[^0-9]/', '', $mysoc->idprof6) : '';
		$realSiren = !empty($mysoc->idprof2) ? substr(preg_replace('/[^0-9]/', '', $mysoc->idprof2), 0, 9) : '';
		if (empty($fakeSiren)) {
			throw new Exception($langs->trans('LemonSuperPDPSandboxModeIdProf6Missing'));
		}

		$realClientSiren = '';
		if (empty($facture->thirdparty)) {
			$facture->fetch_thirdparty();
		}
		if (!empty($facture->thirdparty)) {
			$clientSrc = !empty($facture->thirdparty->idprof1) ? $facture->thirdparty->idprof1 : $facture->thirdparty->idprof2;
			$realClientSiren = substr(preg_replace('/[^0-9]/', '', (string) $clientSrc), 0, 9);
		}
		$fakeClientSiren = '000000001';  // Tricatel sandbox SUPER PDP

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

		$fakeVendorVat = self::computeFrVatNumber($fakeSiren);
		$realVendorVat = !empty($mysoc->tva_intra) ? preg_replace('/\s+/', '', $mysoc->tva_intra) : '';
		$fakeClientVat = self::computeFrVatNumber($fakeClientSiren);
		$realClientVat = '';
		if (!empty($facture->thirdparty) && !empty($facture->thirdparty->tva_intra)) {
			$realClientVat = preg_replace('/\s+/', '', $facture->thirdparty->tva_intra);
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

		dol_syslog('LemonSuperPDP [SANDBOX]: vendeur '.$realSiren.'->'.$fakeSiren.', client '.$realClientSiren.'->'.$fakeClientSiren.', envoi en CII', LOG_WARNING);

		return array('tmpPath' => $tmpXmlPath, 'realSiren' => $realSiren, 'fakeSiren' => $fakeSiren);
	}

	/**
	 * Calcule le numéro de TVA intracommunautaire FR à partir d'un SIREN.
	 * Formule : clé = (12 + 3 × (SIREN mod 97)) mod 97.
	 */
	private static function computeFrVatNumber($siren)
	{
		$s = (int) preg_replace('/[^0-9]/', '', $siren);
		$key = ((12 + 3 * ($s % 97)) % 97);
		return 'FR'.str_pad((string) $key, 2, '0', STR_PAD_LEFT).str_pad((string) $s, 9, '0', STR_PAD_LEFT);
	}
	// >>> FIN SANDBOX MODE <<<

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

		// L'objet facture SUPER PDP contient un champ invoice_events étendu
		// depuis l'API v1.9.0. Fallback : liste globale filtrée.
		$events = array();
		if (!empty($data['invoice_events']) && is_array($data['invoice_events'])) {
			$events = $data['invoice_events'];
		}

		$result = LemonSuperPDPEvent::syncFromApiPayload($this->db, $events, $t->id, $facture->id, $user);

		// Met à jour le statut de la transmission selon le dernier event connu
		// (même si rien n'a été inséré : la plateforme peut nous redonner un
		// état qui reflète mieux la réalité qu'une transmission figée).
		if (!empty($result['lastStatusCode'])) {
			$mapped = LemonSuperPDPTransmission::mapStatusFromEventCode($result['lastStatusCode']);
			if ($mapped !== null) {
				$t->status = $mapped;
			}
			$t->status_raw = $result['lastStatusCode'];
			$t->update($user);
		}

		return $result['inserted'];
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
		if ($statusCode === LemonSuperPDPEvent::STATUS_ENCAISSEE
			|| $statusCode === LemonSuperPDPEvent::STATUS_APPROUVEE_PARTIELLE) {
			$amounts = LemonSuperPDPTransmission::buildAmountsByVatRate($facture, date('Y-m-d'));
			$details = array(array('amounts' => $amounts));
		}

		$client = new SuperPDPClient($this->db);
		$response = $client->submitEvent((int) $t->superpdp_id, $statusCode, $details);

		LemonSuperPDPEvent::createAndLog($this->db, array(
			'fk_transmission'   => $t->id,
			'superpdp_event_id' => !empty($response['id']) ? (int) $response['id'] : null,
			'status_code'       => $statusCode,
			'message'           => LemonSuperPDPEvent::getStatusLabel($statusCode),
			'direction'         => LemonSuperPDPEvent::DIRECTION_OUT,
			'event_date'        => dol_now(),
			'payload_raw'       => json_encode($response),
		), $user, $facture->id);

		if ($statusCode === LemonSuperPDPEvent::STATUS_ENCAISSEE) {
			$t->status = LemonSuperPDPTransmission::STATUS_PAID;
		}
		$t->status_raw = $statusCode;
		$t->update($user);
	}
}
