<?php
/* Copyright (C) 2026 SASU LEMON <https://hellolemon.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 *  \file       htdocs/lemonsuperpdp/core/lib/lemonsuperpdp.lib.php
 *  \ingroup    lemonsuperpdp
 *  \brief      Fonctions utilitaires du module LemonSuperPDP.
 */

/**
 *  Vérifie si une version plus récente du module existe sur GitHub.
 *
 *  Appel de l'API publique GitHub releases/latest, mise en cache 24h dans une
 *  constante Dolibarr pour ne pas marteler l'API à chaque ouverture de la page
 *  admin. Retourne silencieusement null si l'API est inaccessible.
 *
 *  @param  DoliDB  $db              Handle BDD Dolibarr
 *  @param  string  $currentVersion  Version actuelle du module (ex: "0.2.0")
 *  @return array|null               ['version' => 'x.y.z', 'url' => 'https://...']
 *                                   si upgrade dispo, null sinon
 */
function lemonsuperpdp_check_latest_release($db, $currentVersion)
{
	$now = time();
	$cacheRaw = getDolGlobalString('LEMONSUPERPDP_UPDATE_CHECK_CACHE', '');
	$cache = !empty($cacheRaw) ? json_decode($cacheRaw, true) : null;

	$latest = null;
	$htmlUrl = '';
	if (is_array($cache) && isset($cache['ts']) && ($now - (int) $cache['ts']) < 86400) {
		$latest  = $cache['version'] ?? null;
		$htmlUrl = $cache['url']     ?? '';
	} else {
		$url = 'https://api.github.com/repos/hello-lemon/module-dolibarr-lemonsuperpdp/releases/latest';
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_USERAGENT, 'LemonSuperPDP-UpdateCheck');
		curl_setopt($ch, CURLOPT_TIMEOUT, 5);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		$json = @curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($httpCode !== 200 || empty($json)) {
			return null;
		}
		$data = json_decode($json, true);
		if (!is_array($data) || empty($data['tag_name'])) {
			return null;
		}
		$latest  = ltrim($data['tag_name'], 'v');
		$htmlUrl = $data['html_url'] ?? '';
		// Validation défensive : on n'accepte qu'une URL github.com officielle du repo
		if (!preg_match('#^https://github\.com/hello-lemon/module-dolibarr-lemonsuperpdp/#', $htmlUrl)) {
			$htmlUrl = 'https://github.com/hello-lemon/module-dolibarr-lemonsuperpdp/releases';
		}

		dolibarr_set_const($db, 'LEMONSUPERPDP_UPDATE_CHECK_CACHE', json_encode([
			'ts'      => $now,
			'version' => $latest,
			'url'     => $htmlUrl,
		]), 'chaine', 0, '', 0);
	}

	if (!empty($latest) && version_compare($latest, $currentVersion, '>')) {
		return ['version' => $latest, 'url' => $htmlUrl];
	}
	return null;
}

/**
 * Vrai si le tiers est hors champ de l'e-invoicing B2B (réforme 2026-2027) :
 * particulier (typent 8) OU non assujetti à la TVA (champ « Assujetti à la
 * TVA » de la fiche tiers). Ces tiers relèvent de l'e-reporting, pas de la
 * facturation électronique entre assujettis.
 *
 * Le typent seul ne suffit pas (une association non assujettie n'est pas un
 * « Particulier ») et le tva_assuj seul non plus (souvent laissé par défaut) :
 * on combine les deux.
 *
 * @param Societe|null $thirdparty  Tiers Dolibarr (fetch déjà fait)
 * @return bool
 */
function lemonsuperpdp_is_non_assujetti($thirdparty)
{
	if (empty($thirdparty)) {
		return false;
	}
	if ((int) ($thirdparty->typent_id ?? 0) === 8) {
		return true;
	}
	return isset($thirdparty->tva_assuj) && (int) $thirdparty->tva_assuj === 0;
}
