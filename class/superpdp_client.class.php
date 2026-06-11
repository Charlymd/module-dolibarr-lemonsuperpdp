<?php
/*
 * Copyright (C) 2026 SASU LEMON <https://hellolemon.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Client HTTP pour l'API SUPER PDP
 * OAuth 2.1 client_credentials, endpoints /v1.beta/*
 * Référence : https://www.superpdp.tech/documentation
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';  // dolibarr_set_const

/**
 * Exception levée lors d'un échec d'appel à l'API SUPER PDP.
 */
class SuperPDPException extends Exception
{
	public $httpCode = 0;
	public $responseBody = '';

	public function __construct($message, $httpCode = 0, $responseBody = '')
	{
		parent::__construct($message);
		$this->httpCode = (int) $httpCode;
		$this->responseBody = (string) $responseBody;
	}
}

/**
 * Client API SUPER PDP.
 *
 * Usage :
 *   $client = new SuperPDPClient($db);
 *   $info = $client->testConnection();                // GET /v1.beta/companies/me
 *   $resp = $client->submitInvoice('/path/to.pdf');   // POST /v1.beta/invoices
 */
class SuperPDPClient
{
	public $db;
	public $error = '';
	public $errors = array();

	private $endpoint;
	private $clientId;
	private $clientSecret;

	public function __construct($db)
	{
		$this->db = $db;
		$this->endpoint = rtrim(getDolGlobalString('LEMONSUPERPDP_ENDPOINT', 'https://api.superpdp.tech'), '/');
		$this->clientId = getDolGlobalString('LEMONSUPERPDP_CLIENT_ID', '');
		$this->clientSecret = getDolGlobalString('LEMONSUPERPDP_CLIENT_SECRET', '');
	}

	/**
	 * Retourne un access_token OAuth 2.1 valide, en utilisant le cache si possible.
	 *
	 * @return string
	 * @throws SuperPDPException
	 */
	public function getAccessToken()
	{
		if (empty($this->clientId) || empty($this->clientSecret)) {
			throw new SuperPDPException('Identifiants OAuth non configurés (LEMONSUPERPDP_CLIENT_ID / _SECRET)');
		}

		$cached = getDolGlobalString('LEMONSUPERPDP_ACCESS_TOKEN', '');
		if (!empty($cached)) {
			$decoded = json_decode($cached, true);
			if (is_array($decoded) && !empty($decoded['access_token']) && !empty($decoded['expires_at'])) {
				if ($decoded['expires_at'] > (time() + 30)) {
					return $decoded['access_token'];
				}
			}
		}

		return $this->refreshAccessToken();
	}

	/**
	 * Demande un nouveau access_token et le met en cache.
	 *
	 * @return string
	 * @throws SuperPDPException
	 */
	public function refreshAccessToken()
	{
		global $conf;

		$url = $this->endpoint.'/oauth2/token';
		$body = http_build_query(array(
			'grant_type' => 'client_credentials',
			'client_id' => $this->clientId,
			'client_secret' => $this->clientSecret,
		));

		dol_syslog('SuperPDPClient::refreshAccessToken POST '.$url, LOG_DEBUG);

		$call = $this->httpCall('POST', $url, $body, array(
			'Content-Type: application/x-www-form-urlencoded',
			'Accept: application/json',
		), 20);

		if ($call['error']) {
			dol_syslog('SuperPDPClient::refreshAccessToken curl error : '.$call['error'], LOG_ERR);
			throw new SuperPDPException('Erreur réseau : '.$call['error']);
		}

		if ($call['httpCode'] !== 200) {
			// On ne logue PAS la réponse brute ici : elle peut contenir un
			// access_token partiel en cas de bug côté API.
			dol_syslog('SuperPDPClient::refreshAccessToken HTTP '.$call['httpCode'], LOG_ERR);
			throw new SuperPDPException('Échec d\'authentification OAuth (HTTP '.$call['httpCode'].')', $call['httpCode'], $call['body']);
		}

		$data = json_decode($call['body'], true);
		if (!is_array($data) || empty($data['access_token'])) {
			throw new SuperPDPException('Réponse OAuth inattendue', $call['httpCode'], $call['body']);
		}

		$expiresIn = isset($data['expires_in']) ? (int) $data['expires_in'] : 3600;
		$cache = json_encode(array(
			'access_token' => $data['access_token'],
			'expires_at' => time() + $expiresIn,
		));
		dolibarr_set_const($this->db, 'LEMONSUPERPDP_ACCESS_TOKEN', $cache, 'chaine', 0, '', $conf->entity);

		return $data['access_token'];
	}

	/**
	 * Teste la connexion en appelant GET /v1.beta/companies/me.
	 *
	 * @return array Réponse JSON décodée (company info)
	 * @throws SuperPDPException
	 */
	public function testConnection()
	{
		return $this->request('GET', '/v1.beta/companies/me');
	}

	/**
	 * Envoie une facture à l'API SUPER PDP.
	 *
	 * @param string $filePath Chemin absolu vers le fichier (PDF Factur-X ou XML UBL/CII)
	 * @param string $format   'facturx' (PDF), 'ubl' ou 'cii' (XML)
	 * @return array Réponse JSON décodée (contient l'id SUPER PDP)
	 * @throws SuperPDPException
	 */
	public function submitInvoice($filePath, $format = 'facturx')
	{
		if (!file_exists($filePath) || !is_readable($filePath)) {
			throw new SuperPDPException('Fichier introuvable ou non lisible : '.$filePath);
		}

		$content = file_get_contents($filePath);
		if ($content === false) {
			throw new SuperPDPException('Impossible de lire le fichier : '.$filePath);
		}

		$contentType = ($format === 'facturx') ? 'application/pdf' : 'application/xml';

		return $this->request('POST', '/v1.beta/invoices', $content, $contentType);
	}

	/**
	 * Récupère une facture par son id SUPER PDP.
	 *
	 * @param int $superpdpId
	 * @return array
	 * @throws SuperPDPException
	 */
	public function getInvoice($superpdpId)
	{
		return $this->request('GET', '/v1.beta/invoices/'.((int) $superpdpId));
	}

	/**
	 * Liste les factures reçues (direction=in) postérieures à un id donné,
	 * avec le modèle EN16931 structuré (en_invoice) déjà parsé par la PA.
	 *
	 * @param int $startingAfterId  Curseur de pagination (0 = depuis le début)
	 * @return array                Réponse brute contenant data[], count et has_after
	 * @throws SuperPDPException
	 */
	public function listIncomingInvoices($startingAfterId = 0)
	{
		$expand = array(
			'en_invoice',
			'en_invoice.seller',
			'en_invoice.buyer',
			'en_invoice.lines',
			'en_invoice.vat_break_down',
			'en_invoice.document_level_allowances',
			'en_invoice.document_level_charges',
			'en_invoice.payment_instructions',
		);
		$qs = 'direction=in&order=asc&starting_after_id='.((int) $startingAfterId);
		foreach ($expand as $e) {
			$qs .= '&expand[]='.urlencode($e);
		}
		return $this->request('GET', '/v1.beta/invoices?'.$qs);
	}

	/**
	 * Télécharge le fichier brut d'une facture (PDF Factur-X ou XML).
	 * Le format se détecte sur le contenu retourné (magic bytes %PDF).
	 *
	 * @param int $superpdpId
	 * @return string  Contenu binaire du fichier
	 * @throws SuperPDPException
	 */
	public function downloadInvoice($superpdpId)
	{
		$token = $this->getAccessToken();
		$url = $this->endpoint.'/v1.beta/invoices/'.((int) $superpdpId).'/download';

		dol_syslog('SuperPDPClient::downloadInvoice GET '.$url, LOG_DEBUG);

		$call = $this->httpCall('GET', $url, null, array(
			'Authorization: Bearer '.$token,
			'Accept: application/pdf, application/xml',
		), 60);

		if ($call['error']) {
			throw new SuperPDPException('Erreur réseau : '.$call['error']);
		}
		if ($call['httpCode'] < 200 || $call['httpCode'] >= 300) {
			dol_syslog('SuperPDPClient::downloadInvoice HTTP '.$call['httpCode'], LOG_ERR);
			throw new SuperPDPException('Erreur API SUPER PDP au téléchargement (HTTP '.$call['httpCode'].')', $call['httpCode'], $call['body']);
		}
		if ($call['body'] === '') {
			throw new SuperPDPException('Fichier vide reçu de l\'API', $call['httpCode']);
		}

		return $call['body'];
	}

	/**
	 * Liste les invoice_events postérieurs à un id donné (pour la synchronisation).
	 *
	 * @param int $startingAfterId
	 * @return array Réponse brute contenant data[] et has_after
	 * @throws SuperPDPException
	 */
	public function listInvoiceEvents($startingAfterId = 0)
	{
		return $this->request('GET', '/v1.beta/invoice_events?starting_after_id='.((int) $startingAfterId));
	}

	/**
	 * Envoie un invoice_event pour une facture donnée (statut cycle de vie).
	 *
	 * @param int    $superpdpInvoiceId  ID SUPER PDP de la facture (stocké en transmission)
	 * @param string $statusCode         Code AFNOR fr:204..fr:212
	 * @param array  $details            Détails (ex: amounts par taux TVA pour fr:212)
	 * @return array Réponse JSON décodée (contient l'id du nouvel event)
	 * @throws SuperPDPException
	 */
	public function submitEvent($superpdpInvoiceId, $statusCode, $details = array())
	{
		$body = array(
			'invoice_id' => (int) $superpdpInvoiceId,
			'status_code' => (string) $statusCode,
		);
		if (!empty($details)) {
			$body['details'] = $details;
		}
		return $this->request('POST', '/v1.beta/invoice_events', json_encode($body), 'application/json');
	}

	/**
	 * Liste les entrées d'annuaire (adresses électroniques routables) d'une
	 * entreprise par son SIREN. Sert au pre-check avant envoi : une entreprise
	 * sans entrée active n'est pas joignable sur le réseau des PA.
	 *
	 * @param string $siren  SIREN à 9 chiffres
	 * @return array         Réponse brute (data[] de french_directory_entry)
	 * @throws SuperPDPException
	 */
	public function listDirectoryEntries($siren)
	{
		return $this->request('GET', '/v1.beta/french_directory/entries?number='.urlencode($siren));
	}

	/**
	 * Convertit une facture d'un format vers un autre via la PA.
	 * Usage principal : factur-x/cii/ubl → en16931 (JSON structuré identique
	 * au en_invoice du polling), pour l'import manuel sans parsing local.
	 *
	 * @param string $content     Contenu brut du fichier (PDF ou XML)
	 * @param string $fromFormat  'factur-x', 'cii' ou 'ubl'
	 * @param string $toFormat    'en16931' (défaut), 'cii', 'ubl'
	 * @return array              en_invoice JSON décodé
	 * @throws SuperPDPException
	 */
	public function convertInvoice($content, $fromFormat, $toFormat = 'en16931')
	{
		$contentType = ($fromFormat === 'factur-x') ? 'application/pdf' : 'application/xml';
		$path = '/v1.beta/invoices/convert?from='.urlencode($fromFormat).'&to='.urlencode($toFormat);
		return $this->request('POST', $path, $content, $contentType);
	}

	/**
	 * Déclare des transactions B2C (e-reporting). La PA stocke, agrège et
	 * transmet au PPF selon le régime de TVA configuré côté SUPER PDP.
	 *
	 * @param array $transactions  Liste d'objets b2c_transaction
	 * @return array               Réponse brute (data[] avec les ids créés)
	 * @throws SuperPDPException
	 */
	public function createB2CTransactions(array $transactions)
	{
		return $this->request('POST', '/v1.beta/b2c_transactions', json_encode(array('data' => array_values($transactions))), 'application/json');
	}

	/**
	 * Déclare des paiements B2C (e-reporting, données de paiement).
	 *
	 * @param array $payments  Liste d'objets b2c_payment
	 * @return array           Réponse brute (data[] avec les ids créés)
	 * @throws SuperPDPException
	 */
	public function createB2CPayments(array $payments)
	{
		return $this->request('POST', '/v1.beta/b2c_payments', json_encode(array('data' => array_values($payments))), 'application/json');
	}

	/**
	 * Appel HTTP générique avec authentification Bearer.
	 *
	 * @param string $method      GET, POST, ...
	 * @param string $path        Chemin relatif (commence par /)
	 * @param mixed  $body        Corps de requête (string ou null)
	 * @param string $contentType Content-Type du body
	 * @return array              Réponse JSON décodée
	 * @throws SuperPDPException
	 */
	private function request($method, $path, $body = null, $contentType = 'application/json')
	{
		$token = $this->getAccessToken();
		$url = $this->endpoint.$path;

		dol_syslog('SuperPDPClient::request '.$method.' '.$url, LOG_DEBUG);

		$headers = array(
			'Authorization: Bearer '.$token,
			'Accept: application/json',
		);
		if ($body !== null) {
			$headers[] = 'Content-Type: '.$contentType;
		}

		$call = $this->httpCall($method, $url, $body, $headers, 60);

		if ($call['error']) {
			dol_syslog('SuperPDPClient::request curl error : '.$call['error'], LOG_ERR);
			throw new SuperPDPException('Erreur réseau : '.$call['error']);
		}

		if ($call['httpCode'] < 200 || $call['httpCode'] >= 300) {
			// Tronque à 500 chars pour éviter de pourrir les logs avec des
			// réponses massives et pour limiter le risque de fuite de tokens
			// inclus dans un payload d'erreur inhabituel.
			dol_syslog('SuperPDPClient::request HTTP '.$call['httpCode'].' : '.dol_trunc((string) $call['body'], 500), LOG_ERR);
			$msg = 'Erreur API SUPER PDP (HTTP '.$call['httpCode'].')';
			$decoded = json_decode($call['body'], true);
			if (is_array($decoded) && !empty($decoded['message'])) {
				$msg .= ' : '.$decoded['message'];
			}
			throw new SuperPDPException($msg, $call['httpCode'], $call['body']);
		}

		$decoded = json_decode($call['body'], true);
		if (!is_array($decoded)) {
			throw new SuperPDPException('Réponse non-JSON reçue', $call['httpCode'], $call['body']);
		}

		return $decoded;
	}

	/**
	 * Exécute un appel HTTP et retourne un résultat normalisé.
	 *
	 * On reste sur curl plutôt que getURLContent() pour pouvoir contrôler
	 * finement le timeout (20s auth, 60s upload de PDF) et envoyer des
	 * bodies binaires (PDF Factur-X en POST brut).
	 *
	 * @param string $method   Verbe HTTP (GET, POST, PUT, ...)
	 * @param string $url      URL absolue
	 * @param mixed  $body     Corps brut (string) ou null
	 * @param array  $headers  Headers au format ['Header: valeur', ...]
	 * @param int    $timeout  Timeout en secondes
	 * @return array{body:string,httpCode:int,error:string}
	 */
	private function httpCall($method, $url, $body, array $headers, $timeout)
	{
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, (int) $timeout);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		if ($body !== null) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		}

		$response = curl_exec($ch);
		$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlError = curl_error($ch);
		curl_close($ch);

		return array(
			'body' => (string) $response,
			'httpCode' => $httpCode,
			'error' => (string) $curlError,
		);
	}
}
