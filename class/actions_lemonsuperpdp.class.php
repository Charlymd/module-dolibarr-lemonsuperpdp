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
		return 1;
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

				// Substitutions ciblées : ">9 chiffres<" ou '"9 chiffres"'.
				// Le pattern ne matche pas le SIRET (14 chiffres), donc SIRET et
				// numéro TVA intracommunautaire restent intacts.
				$search = array('>'.$realSiren.'<', '"'.$realSiren.'"');
				$replace = array('>'.$fakeSiren.'<', '"'.$fakeSiren.'"');
				if (strlen($realClientSiren) === 9 && $realClientSiren !== $realSiren) {
					$search[] = '>'.$realClientSiren.'<';
					$search[] = '"'.$realClientSiren.'"';
					$replace[] = '>'.$fakeClientSiren.'<';
					$replace[] = '"'.$fakeClientSiren.'"';
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

		// >>> SANDBOX MODE <<<
		if ($tmpXmlPath !== null && file_exists($tmpXmlPath)) {
			@unlink($tmpXmlPath);
		}
		// >>> FIN SANDBOX MODE <<<

		$action = '';
		return 0;
	}
}
