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
		} else {
			print '<span class="opacitymedium">'.$langs->trans('LemonSuperPDPNotTransmitted').'</span>';
		}
		print '</td></tr>';

		return 0;
	}

	/**
	 * Intercepte l'action dosendsuperpdp : lit le PDF, envoie via l'API, stocke la transmission.
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user, $conf;

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

		dol_include_once('/lemonsuperpdp/class/superpdp_client.class.php');
		try {
			$client = new SuperPDPClient($this->db);
			$response = $client->submitInvoice($pdfPath, $format);

			$t->superpdp_id = !empty($response['id']) ? (int) $response['id'] : null;
			$t->status = LemonSuperPDPTransmission::STATUS_SENT;
			$t->payload_response = json_encode($response);
			$t->update($user);

			$msg = $langs->trans('LemonSuperPDPSendSuccess');
			if (!empty($t->superpdp_id)) {
				$msg .= ' — ID SUPER PDP : '.((int) $t->superpdp_id);
			}
			setEventMessages($msg, null, 'mesgs');
		} catch (SuperPDPException $e) {
			dol_syslog('LemonSuperPDP: échec envoi facture '.$object->ref.' : '.$e->getMessage(), LOG_ERR);
			$t->status = LemonSuperPDPTransmission::STATUS_ERROR;
			$t->error_message = $e->getMessage();
			if ($e->responseBody) {
				$t->payload_response = $e->responseBody;
			}
			$t->update($user);
			setEventMessages($langs->trans('LemonSuperPDPSendError').' — '.$e->getMessage(), null, 'errors');
		} catch (Exception $e) {
			dol_syslog('LemonSuperPDP: exception envoi : '.$e->getMessage(), LOG_ERR);
			$t->status = LemonSuperPDPTransmission::STATUS_ERROR;
			$t->error_message = $e->getMessage();
			$t->update($user);
			setEventMessages($langs->trans('LemonSuperPDPSendError').' — '.$e->getMessage(), null, 'errors');
		}

		$action = '';
		return 0;
	}
}
