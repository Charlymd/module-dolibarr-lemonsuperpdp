<?php
/**
 * tab_lifecycle.php — Onglet Cycle de vie LemonSuperPDP
 *
 * @package   LemonSuperPDP
 * @copyright 2026 SASU Lemon <contact@hellolemon.fr>
 * @license   GPLv3
 */

// ── Chargement Dolibarr ─────────────────────────────────────────────────────
// Ce fichier est dans htdocs/custom/lemonsuperpdp/
// main.inc.php est dans htdocs/ soit deux niveaux au-dessus.
$res = 0;

// Méthode 1 : DOCUMENT_ROOT Apache (le plus fiable sur Apache standard)
if (!$res && !empty($_SERVER['DOCUMENT_ROOT'])) {
    $f = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/main.inc.php';
    if (file_exists($f)) {
        $res = @include $f;
    }
}

// Méthode 2 : CONTEXT_DOCUMENT_ROOT (mod_php avec alias)
if (!$res && !empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
    $f = rtrim(str_replace('..', '', $_SERVER['CONTEXT_DOCUMENT_ROOT']), '/') . '/main.inc.php';
    if (file_exists($f)) {
        $res = @include $f;
    }
}

// Méthode 3 : remontée depuis __FILE__
// htdocs/custom/lemonsuperpdp/tab_lifecycle.php → ../../ = htdocs/
if (!$res) {
    $f = dirname(__FILE__) . '/../../main.inc.php';
    if (file_exists($f)) {
        $res = @include $f;
    }
}

// Méthode 4 : remontée supplémentaire (si custom/ est dans un sous-dossier)
if (!$res) {
    $f = dirname(__FILE__) . '/../../../main.inc.php';
    if (file_exists($f)) {
        $res = @include $f;
    }
}

// Méthode 5 : SCRIPT_FILENAME — boucle sur les parents
if (!$res && !empty($_SERVER['SCRIPT_FILENAME'])) {
    $tmp = realpath($_SERVER['SCRIPT_FILENAME']);
    $dir = dirname($tmp);
    for ($lvl = 0; $lvl < 6; $lvl++) {
        $dir = dirname($dir);
        if (file_exists($dir . '/main.inc.php')) {
            $res = @include $dir . '/main.inc.php';
            if ($res) break;
        }
    }
}

if (!$res) {
    die('LemonSuperPDP : impossible de charger main.inc.php — vérifiez le chemin d\'installation.');
}

// ── Includes Dolibarr ───────────────────────────────────────────────────────
if (!defined('DOL_DOCUMENT_ROOT')) {
    die('LemonSuperPDP : DOL_DOCUMENT_ROOT non défini — main.inc.php non chargé.');
}

if (!class_exists('Facture')) {
    require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
}

// En Dolibarr 23+ la fonction s'appelle facture_prepare_head() (plus invoice_prepare_head).
if (!function_exists('facture_prepare_head')) {
    include_once DOL_DOCUMENT_ROOT . '/core/lib/invoice.lib.php';
}

dol_include_once('/lemonsuperpdp/class/event.class.php');
dol_include_once('/lemonsuperpdp/class/transmission.class.php');

$langs->loadLangs(array('bills', 'lemonsuperpdp@lemonsuperpdp'));

// ── Paramètres ──────────────────────────────────────────────────────────────
$id     = GETPOST('id', 'int');
$ref    = GETPOST('ref', 'alpha');
$action = GETPOST('action', 'alphanohtml');

// ── Droits ──────────────────────────────────────────────────────────────────
if (!isModEnabled('facture')) {
    accessforbidden('', 0, 0, 1);
}
if (method_exists($user, 'hasRight')) {
    $canRead  = $user->hasRight('facture', 'lire');
    $canWrite = $user->hasRight('facture', 'creer');
} else {
    $canRead  = !empty($user->rights->facture->lire);
    $canWrite = !empty($user->rights->facture->creer);
}
if (!empty($user->admin)) {
    $canRead = true;
    $canWrite = true;
}
if (!$canRead) {
    accessforbidden('', 0, 0, 1);
}

// ── Chargement facture ───────────────────────────────────────────────────────
$object = new Facture($db);
if ($id > 0) {
    $ret = $object->fetch($id);
} elseif ($ref) {
    $ret = $object->fetch(0, $ref);
} else {
    accessforbidden('', 0, 0, 1);
}
if (empty($ret) || $ret < 0) {
    accessforbidden('', 0, 0, 1);
}
$fk = (int) $object->id;

// ── Action POST ──────────────────────────────────────────────────────────────
if ($action === 'forcesuperpdpsend' && $canWrite) {
    if (GETPOST('token', 'alpha') !== currentToken()) {
        setEventMessages('Bad CSRF token', null, 'errors');
    } else {
        dol_include_once('/lemonsuperpdp/class/actions_lemonsuperpdp.class.php');
        $sender = new ActionsLemonSuperPDP($db);
        $result = $sender->sendOneInvoice($object, $user, true);
        $msg = !empty($result['message']) ? $result['message'] : '';
        $style = in_array($result['outcome'], array('ok', 'ok-recovered'), true) ? 'mesgs' : 'errors';
        if ($result['outcome'] === 'ok-recovered') $style = 'warnings';
        setEventMessages($msg, null, $style);
    }
    header('Location: '.dol_buildpath('/lemonsuperpdp/tab_lifecycle.php', 1).'?id='.$fk);
    exit;
}

if ($action === 'lemonfacturx_verify' && $canRead) {
    if (GETPOST('token', 'alpha') !== currentToken()) {
        setEventMessages('Bad CSRF token', null, 'errors');
    } elseif (isModEnabled('lemonfacturx') && getDolGlobalInt('LEMONFACTURX_ENABLED')) {
        dol_include_once('/lemonfacturx/class/actions_lemonfacturx.class.php');
        $lfx = new ActionsLemonFacturX($db);
        $lfx->verifyInvoicePdf($object);
    } else {
        setEventMessages('Module LemonFacturX non activé.', null, 'warnings');
    }
    header('Location: '.dol_buildpath('/lemonsuperpdp/tab_lifecycle.php', 1).'?id='.$fk);
    exit;
}

if ($action === 'send_lifecycle_status' && $canWrite) {
    if (GETPOST('token', 'alpha') !== currentToken()) {
        setEventMessages('Erreur CSRF.', null, 'errors');
    } else {
        $status_code = GETPOST('lifecycle_status', 'alphanohtml');
        $allowed_out = lsp_allowed_outgoing($fk, $db);
        if (!array_key_exists($status_code, $allowed_out)) {
            setEventMessages('Statut non autorisé.', null, 'errors');
        } else {
            dol_include_once('/lemonsuperpdp/class/superpdp_client.class.php');
            $t = new LemonSuperPDPTransmission($db);
            if ($t->fetchLastByFacture($fk) > 0 && !empty($t->superpdp_id)) {
                try {
                    $client = new SuperPDPClient($db);
                    $response = $client->submitEvent((int) $t->superpdp_id, $status_code, array());
                    LemonSuperPDPEvent::createAndLog($db, array(
                        'fk_transmission' => $t->id,
                        'status_code'     => $status_code,
                        'flux'            => 'fournisseur',
                        'message'         => lsp_label($status_code),
                        'direction'       => LemonSuperPDPEvent::DIRECTION_OUT,
                        'event_date'      => dol_now(),
                        'payload_raw'     => json_encode($response),
                    ), $user, $fk);
                    $mapped = LemonSuperPDPTransmission::mapStatusFromEventCode($status_code);
                    if ($mapped !== null) {
                        $t->status = $mapped;
                    }
                    $t->status_raw = $status_code;
                    $t->update($user);
                    setEventMessages('Statut envoyé : ' . lsp_label($status_code), null, 'mesgs');
                } catch (Exception $e) {
                    LemonSuperPDPEvent::createAndLog($db, array(
                        'fk_transmission' => $t->id,
                        'status_code'     => 'ERROR',
                        'flux'            => 'pdp',
                        'message'         => 'Erreur envoi statut '.$status_code.' : '.$e->getMessage(),
                        'direction'       => LemonSuperPDPEvent::DIRECTION_OUT,
                        'event_date'      => dol_now(),
                        'payload_raw'     => $e->getMessage(),
                    ), $user, $fk);
                    setEventMessages('Erreur : ' . dol_escape_htmltag($e->getMessage()), null, 'errors');
                }
            } else {
                setEventMessages('Aucune transmission SUPER PDP pour cette facture.', null, 'errors');
            }
        }
    }
    header('Location: ' . dol_buildpath('/lemonsuperpdp/tab_lifecycle.php', 1) . '?id=' . $fk);
    exit;
}

// ── Chargement transmission (statut + message d'erreur éventuel) ─────────────
$transmission = new LemonSuperPDPTransmission($db);
$transmission->fetchLastByFacture($fk);

// ── Chargement événements ────────────────────────────────────────────────────
$events_raw = array();
$sql = 'SELECT e.rowid, e.status_code, e.flux, e.direction, e.event_date, e.message, e.payload_raw'
     . ' FROM ' . MAIN_DB_PREFIX . 'lemonsuperpdp_event e'
     . ' LEFT JOIN ' . MAIN_DB_PREFIX . 'lemonsuperpdp_transmission t ON t.rowid = e.fk_transmission'
     . ' WHERE COALESCE(t.fk_facture, e.fk_facture) = ' . $fk
     . ' AND e.entity = ' . ((int) $conf->entity)
     . ' ORDER BY e.event_date ASC, e.rowid ASC';
$resq = $db->query($sql);
if ($resq) {
    while ($obj = $db->fetch_object($resq)) {
        $events_raw[] = $obj;
    }
    $db->free($resq);
}

// ── Injection event d'erreur de transmission ─────────────────────────────────
// Si la transmission est en erreur, on crée un événement synthétique 'ERROR'
// dans le flux PA pour qu'il apparaisse dans la timeline et la colonne PDP/PA.
// Les erreurs de transmission sont désormais enregistrées comme événements REJECT
// dans llx_lemonsuperpdp_event — plus besoin d'injecter un event synthétique ERROR.

// ── Dernier event par flux ───────────────────────────────────────────────────
$last = array('fournisseur' => null, 'pdp' => null, 'client' => null);
foreach (array_reverse($events_raw) as $evt) {
    $flux = lsp_flux($evt->status_code, isset($evt->flux) ? (string) $evt->flux : '');
    if ($flux && $last[$flux] === null) {
        $last[$flux] = $evt;
    }
}

// ── Progression ──────────────────────────────────────────────────────────────
$fc   = $last['fournisseur'] ? $last['fournisseur']->status_code : '';
$cc   = $last['client']      ? $last['client']->status_code      : '';
$step = 0;
if ($fc === 'fr:204') $step = 1;
if ($fc === 'fr:205') $step = 2;
if (in_array($cc, array('fr:206', 'fr:207', 'fr:210', 'fr:211'), true)) $step = 3;
if (in_array($cc, array('fr:208', 'fr:209'), true)) $step = 4;
if ($cc === 'fr:212') $step = 5;

// ── Données SVG ──────────────────────────────────────────────────────────────
$svg_events = array();
foreach ($events_raw as $e) {
    $fx      = lsp_flux($e->status_code, isset($e->flux) ? (string) $e->flux : '');
    $msg     = isset($e->message) ? lsp_plain((string) $e->message) : '';
    $payload = isset($e->payload_raw) ? (string) $e->payload_raw : '';
    if ($e->status_code === 'ERROR') {
        $tooltip = $msg;
    } elseif ($payload !== '' && (
        $e->status_code === 'facturx:generated'
        || ($e->status_code === 'facturx:error')
        || (isset($e->direction) && $e->direction === 'out' && $e->status_code === 'api:uploaded')
    )) {
        $tooltip = $payload;
    } else {
        $tooltip = '';
    }
    // Sévérité et compte d'avertissements pour les événements Factur-X
    $warnings_count = 0;
    if ($e->status_code === 'facturx:error') {
        $severity = 'error';
    } elseif ($e->status_code === 'facturx:generated' && $payload !== '') {
        $severity = 'warning';
        $lines = explode("\n", $payload);
        $warnings_count = max(0, count(array_filter(array_slice($lines, 1), 'strlen')));
    } elseif ($e->status_code === 'facturx:generated') {
        $severity = 'ok';
    } else {
        $severity = '';
    }
    // Label tronqué à 36 caractères — le label complet et le payload vont dans le tooltip
    $fullLabel  = lsp_label($e->status_code, $msg);
    $shortLabel = mb_strimwidth($fullLabel, 0, 36, '…');
    // Tooltip : payload_raw prioritaire, sinon label complet + code si tronqué
    if ($tooltip) {
        $svgTooltip = $tooltip;
    } elseif ($shortLabel !== $fullLabel) {
        $svgTooltip = $e->status_code . ' — ' . $fullLabel . ($msg && $msg !== $fullLabel ? ' — ' . $msg : '');
    } else {
        $svgTooltip = '';
    }
    // L'accusé de réception entrant (api:uploaded direction=in) vient de PDP/PA → Fournisseur
    $direction = isset($e->direction) ? (string) $e->direction : '';
    if ($e->status_code === 'api:uploaded' && $direction === 'in') {
        $fx = 'pdp';
    }
    $svg_events[] = array(
        'code'     => $e->status_code,
        'flux'     => $fx,
        'label'    => $shortLabel,
        'tooltip'  => $svgTooltip,
        'severity'       => $severity,
        'warnings_count' => $warnings_count,
        'date'     => dol_print_date($db->jdate($e->event_date), 'day'),
        'time'     => dol_print_date($db->jdate($e->event_date), '%H:%M:%S'),
        'seq_from' => ($e->status_code === 'api:uploaded' && $direction === 'in') ? 1 : lsp_seq_from($e->status_code, $fx),
        'seq_to'   => ($e->status_code === 'api:uploaded' && $direction === 'in') ? 0 : lsp_seq_to($e->status_code, $fx),
        'color'    => lsp_color($e->status_code, $fx),
        'dashed'   => lsp_dashed($e->status_code) || ($direction === 'in'),
        'group'    => 0, // rempli ci-dessous
    );
}

// Calcul des groupes : events ayant le même date+heure = même requête PHP
$_dtGroups = array();
foreach ($svg_events as $i => $e) {
    $key = $e['date'] . '|' . $e['time'];
    $_dtGroups[$key][] = $i;
}
$_gid = 0;
foreach ($_dtGroups as $indices) {
    foreach ($indices as $i) {
        $svg_events[$i]['group'] = $_gid;
    }
    $_gid++;
}
unset($_dtGroups, $_gid);


$allowed = lsp_allowed_outgoing($fk, $db);

// ── Rendu page ───────────────────────────────────────────────────────────────
if (!function_exists('facture_prepare_head')) {
    include_once DOL_DOCUMENT_ROOT . '/core/lib/invoice.lib.php';
}
$object->fetch_thirdparty();
$head = facture_prepare_head($object);
llxHeader('', $langs->trans('Invoice') . ' — ' . $langs->trans('LemonSuperPDPLifecycleTab'));
print dol_get_fiche_head($head, 'lifecycle', $langs->trans('Invoice'), -1, 'bill');
$linkback = '<a href="' . DOL_URL_ROOT . '/compta/facture/list.php?restore_lastsearch_values=1">'
          . $langs->trans('BackToList') . '</a>';
dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', '');
print '<div class="fichecenter">';
?>

<div style="font-size:13px;font-family:inherit;padding:4px 0;">

  <!-- Progression -->
  <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;">
    <strong><?php echo $langs->trans('LemonSuperPDPLifecycleTitle'); ?> — <?php echo dol_escape_htmltag($object->ref); ?></strong>
    <div style="display:flex;align-items:center;gap:5px;">
      <span style="font-size:11px;color:#888780;"><?php echo $langs->trans('LemonSuperPDPProgression'); ?></span>
      <?php for ($i = 1; $i <= 5; $i++): ?>
      <div style="width:20px;height:4px;border-radius:2px;background:<?php
          echo ($i <= $step) ? ($step === 5 ? '#3B6D11' : '#185FA5') : '#d3d1c7'; ?>;"></div>
      <?php endfor; ?>
      <span style="font-size:11px;color:#5f5e5a;"><?php echo $step; ?>/5</span>
    </div>
  </div><!-- fin .progression -->

  <!-- Dernier message -->
  <div style="display:flex;align-items:stretch;padding:8px 0;gap:8px;border-top:1px solid #e0ddd6;border-bottom:1px solid #e0ddd6;margin-bottom:4px;">
    <div style="font-size:11px;font-weight:500;color:#888780;white-space:nowrap;display:flex;align-items:center;padding-right:6px;line-height:1.5;">
      <?php echo $langs->trans('LemonSuperPDPLastMessage'); ?><br>
      <?php echo $langs->trans('LemonSuperPDPLastMessageLine2'); ?>
    </div>
    <?php
    $actors = array(
        'fournisseur' => array('label' => 'Fournisseur', 'color' => '#185FA5', 'dot' => '#185FA5'),
        'pdp'         => array('label' => 'PDP / PA',    'color' => '#5F5E5A', 'dot' => '#888780'),
        'client'      => array('label' => 'Client',      'color' => '#3B6D11', 'dot' => '#3B6D11'),
    );
    foreach ($actors as $flux_key => $actor):
        $evt = $last[$flux_key];
    ?>
    <div style="flex:1;display:flex;flex-direction:column;gap:2px;padding:6px 10px;border-radius:6px;border:1px solid #e0ddd6;background:#fff;">
      <div style="display:flex;align-items:center;gap:5px;margin-bottom:2px;">
        <span style="width:7px;height:7px;border-radius:50%;background:<?php echo $actor['dot']; ?>;flex-shrink:0;"></span>
        <span style="font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.04em;color:<?php echo $actor['color']; ?>;"><?php echo dol_escape_htmltag($actor['label']); ?></span>
      </div>
      <?php if ($evt): ?>
      <?php
        $evtMsg       = isset($evt->message) ? (string) $evt->message : '';
        $evtFull      = lsp_label($evt->status_code, $evtMsg);
        $evtShort     = mb_strimwidth($evtFull, 0, 36, '…');
        $evtTitle     = ($evtFull !== $evtShort) ? $evtFull : $evtMsg;
        $evtNeedTitle = ($evtFull !== $evtShort) || ($evt->status_code === 'ERROR' && $evtMsg);
      ?>
      <div style="font-size:12px;font-weight:500;color:#2c2c2a;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;<?php echo $evtNeedTitle ? 'text-decoration:underline dotted;cursor:help;' : ''; ?>"
           <?php echo $evtNeedTitle ? 'title="'.dol_escape_htmltag($evtTitle).'"' : ''; ?>>
        <?php echo dol_escape_htmltag($evtShort); ?>
      </div>
      <div style="font-size:10px;color:#888780;margin-top:2px;"><?php echo dol_print_date($db->jdate($evt->event_date), 'dayhour'); ?></div>
      <?php else: ?>
      <div style="font-size:11px;color:#b4b2a9;font-style:italic;"><?php echo $langs->trans('LemonSuperPDPNoEvent'); ?></div>
      <?php endif; ?>
      <?php if ($flux_key === 'pdp' && $transmission->id > 0 && !empty($transmission->superpdp_id)): ?>
      <div style="font-size:10px;color:#888780;margin-top:4px;border-top:1px solid #e0ddd6;padding-top:3px;">
        ID SUPER PDP : <code style="font-size:10px;"><?php echo (int) $transmission->superpdp_id; ?></code>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- SVG timeline + séquence -->
  <?php
  if (!empty($svg_events)) {
      echo lsp_render_svg($svg_events);
  } else {
      echo '<div style="padding:24px;text-align:center;color:#888780;font-style:italic;">';
      echo $langs->trans('LemonSuperPDPNoEvent');
      echo '</div>';
  }
  ?>

  <!-- Barre d'action -->
  <?php if ($canRead || $canWrite): ?>
  <div style="display:flex;align-items:center;gap:8px;padding:8px 0 0;border-top:1px solid #e0ddd6;margin-top:4px;flex-wrap:wrap;">
    <?php if ($canRead && isModEnabled('lemonfacturx') && getDolGlobalInt('LEMONFACTURX_ENABLED')): ?>
    <a class="butAction" href="<?php echo dol_escape_htmltag(dol_buildpath('/lemonsuperpdp/tab_lifecycle.php', 1).'?id='.$fk.'&action=lemonfacturx_verify&token='.newToken()); ?>">
      Vérifier la Factur-X
    </a>
    <?php endif; ?>
    <?php if ($canWrite && getDolGlobalInt('LEMONSUPERPDP_ENABLED')): ?>
    <a class="butAction" href="<?php echo dol_escape_htmltag(dol_buildpath('/lemonsuperpdp/tab_lifecycle.php', 1).'?id='.$fk.'&action=forcesuperpdpsend&token='.newToken()); ?>"
       onclick="return confirm('<?php echo dol_escape_js($langs->trans('LemonSuperPDPForceResendConfirm')); ?>');">
      &#x21BA; <?php echo $langs->trans('LemonSuperPDPForceResend'); ?>
    </a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <?php if ($canWrite && !empty($allowed)): ?>
  <div style="display:flex;align-items:center;gap:8px;padding:10px 0;border-top:1px solid #e0ddd6;margin-top:4px;">
    <form method="post" action="<?php echo dol_buildpath('/lemonsuperpdp/tab_lifecycle.php', 1); ?>?id=<?php echo $fk; ?>"
          style="display:flex;align-items:center;gap:8px;flex:1;flex-wrap:wrap;">
      <input type="hidden" name="action" value="send_lifecycle_status">
      <input type="hidden" name="token" value="<?php echo newToken(); ?>">
      <label style="font-size:12px;color:#5f5e5a;white-space:nowrap;">&#x2709; Émettre vers la PA :</label>
      <select name="lifecycle_status" style="font-size:12px;padding:4px 8px;border-radius:6px;border:1px solid #d3d1c7;flex:1;min-width:260px;max-width:380px;">
        <option value="">— Choisir un statut —</option>
        <?php foreach ($allowed as $code => $lbl): ?>
        <option value="<?php echo dol_escape_htmltag($code); ?>"><?php echo dol_escape_htmltag($code . ' — ' . $lbl); ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" style="font-size:12px;padding:5px 12px;border-radius:6px;border:1px solid #c0dd97;background:#eaf3de;color:#27500a;cursor:pointer;">&#10003; Envoyer</button>
    </form>
  </div>
  <?php endif; ?>

</div>

<?php
print '</div>';
print dol_get_fiche_end();
llxFooter();
$db->close();

// ═══════════════════════════════════════════════════════════════════════════
// FONCTIONS — déclarées APRÈS l'include main.inc.php
// ═══════════════════════════════════════════════════════════════════════════

function lsp_flux($code, $flux_db = '')
{
    if (!empty($flux_db) && in_array($flux_db, array('fournisseur', 'pdp', 'client'), true)) {
        return $flux_db;
    }
    $fournisseur = array('fr:200','fr:201','fr:202','fr:203','fr:204','fr:205','api:uploaded','api:recovered','facturx:generated','facturx:error');
    $pdp         = array('ACK','ACK-01','ACK-02','REJECT','ROUTE','ERROR');
    if (in_array($code, $fournisseur, true)) return 'fournisseur';
    if (in_array($code, $pdp, true))         return 'pdp';
    return 'client';
}

function lsp_label($code, $override = '')
{
    // Cas spéciaux non couverts par LemonSuperPDPEvent::getStatusLabel()
    if ($code === 'ERROR')         return 'Erreur envoi statut';
    if ($code === 'api:recovered') return !empty($override) ? lsp_plain($override) : 'Déjà présente sur SUPER PDP';
    if ($code === 'REJECT') return !empty($override) ? lsp_plain($override) : 'Rejet SUPER PDP';
    if ($code === 'api:uploaded')        return !empty($override) ? lsp_plain($override) : 'Accusé réception téléversement';
    if ($code === 'facturx:generated') {
        // Le message peut contenir " — fichier.pdf" (ajouté par createAndLog depuis last_main_doc).
        // Présence d'un nom de fichier = deuxième génération (validée).
        // Absence = première génération automatique (provisoire).
        if (!empty($override) && preg_match('/\s—\s\S.+\.\w+$/', $override)) {
            return 'Factur-X généré — validée';
        }
        return 'Factur-X généré — provisoire';
    }
    if ($code === 'facturx:error')     return !empty($override) ? lsp_plain($override) : 'Erreur génération Factur-X';
    if (!empty($override)) return lsp_plain($override);
    // Délègue à la source de vérité pour les codes AFNOR et ACK/REJECT/ROUTE
    return LemonSuperPDPEvent::getStatusLabel($code);
}

// $langs->trans() retourne parfois des entités HTML (&eacute; etc.).
// On décode en UTF-8 pur pour que htmlspecialchars() du SVG ne double-encode pas.
function lsp_plain($str)
{
    return html_entity_decode((string) $str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function lsp_color($code, $flux = '')
{
    if ($flux === '' || $flux === null) $flux = lsp_flux($code);
    if ($code === 'ERROR' || $code === 'REJECT') return '#A32D2D';
    if ($code === 'api:recovered') return '#CC9900'; // ambre — avertissement recovery
    if ($code === 'api:uploaded')  return '#3B6D11'; // vert — envoi API réussi
    if ($flux === 'fournisseur') return '#185FA5';
    if ($flux === 'pdp')         return '#888780';
    if ($code === 'fr:210')      return '#A32D2D';
    if ($code === 'fr:211')      return '#854F0B';
    return '#3B6D11';
}

function lsp_dashed($code)
{
    return in_array($code, array('ACK','ACK-01','ACK-02','ROUTE','ERROR','REJECT','api:recovered','fr:210','fr:211'), true);
}

function lsp_seq_from($code, $flux = '')
{
    if ($code === 'facturx:generated' || $code === 'facturx:error') return 0; // pas de flèche
    if ($code === 'api:recovered') return 1;  // réponse PA → Fournisseur
    if ($code === 'ERROR')         return 1;
    if ($flux === 'fournisseur')   return 0;
    if ($flux === 'client')        return 2;
    if (in_array($code, array('ACK','ACK-01','ACK-02','REJECT'), true)) return 1;
    return 1;
}

function lsp_seq_to($code, $flux = '')
{
    if ($code === 'facturx:generated' || $code === 'facturx:error') return 0; // pas de flèche
    if ($code === 'api:recovered') return 0;  // réponse PA → Fournisseur
    if ($code === 'ERROR')         return 0;
    if ($flux === 'fournisseur')   return 1;
    if ($flux === 'client')        return 1;
    if ($flux === 'pdp')           return 0;
    if (in_array($code, array('ACK','ACK-01','ACK-02','REJECT'), true)) return 0;
    return 2;
}


function lsp_allowed_outgoing($fk, $db)
{
    // fr:212 (Encaissée) est le seul statut que le vendeur peut émettre manuellement.
    // Il est normalement auto-déclenché par le trigger BILL_PAYED ; ce menu sert de secours.
    // Masqué si fr:212 a déjà été enregistré pour cette facture.
    global $conf;
    $sql = 'SELECT e.rowid FROM ' . MAIN_DB_PREFIX . 'lemonsuperpdp_event e'
         . ' INNER JOIN ' . MAIN_DB_PREFIX . 'lemonsuperpdp_transmission t ON t.rowid = e.fk_transmission'
         . " WHERE t.fk_facture = " . (int) $fk
         . " AND t.entity = " . ((int) $conf->entity)
         . " AND e.status_code = 'fr:212' LIMIT 1";
    $res = $db->query($sql);
    if ($res && $db->num_rows($res) > 0) {
        return array();
    }
    return array('fr:212' => 'Encaissée');
}

function lsp_render_svg(array $events)
{
    $row_h = 44;
    $top   = 44;
    $bot   = 40;
    $n     = count($events);
    if ($n === 0) return '';

    $h  = $top + $n * $row_h + $bot;
    $cx = array(0 => 288, 1 => 450, 2 => 610);

    // Couleur de pastille selon sévérité Factur-X
    $severity_colors = array(
        'ok'      => '#3B6D11',   // vert
        'warning' => '#D97706',   // orange
        'error'   => '#A32D2D',   // rouge
    );

    $o  = '<svg width="100%" viewBox="-10 0 670 '.$h.'" style="display:block;margin:8px 0;max-width:670px;">';
    $o .= '<defs><marker id="lcA" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="5" markerHeight="5" orient="auto-start-reverse">'
        . '<path d="M2 1L8 5L2 9" fill="none" stroke="context-stroke" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>'
        . '</marker></defs>';
    $o .= '<line x1="65" y1="'.($top-4).'" x2="65" y2="'.($top+$n*$row_h).'" stroke="#d3d1c7" stroke-width="0.5"/>';

    $ac = array(
        array('label'=>'Fournisseur','x'=>240,'w'=>96,'cx'=>288,'fi'=>'#E6F1FB','st'=>'#B5D4F4','tc'=>'#0C447C','lc'=>'#185FA5'),
        array('label'=>'PDP / PA',  'x'=>407,'w'=>86,'cx'=>450,'fi'=>'#F1EFE8','st'=>'#D3D1C7','tc'=>'#444441','lc'=>'#888780'),
        array('label'=>'Client',    'x'=>567,'w'=>86,'cx'=>610,'fi'=>'#EAF3DE','st'=>'#C0DD97','tc'=>'#27500A','lc'=>'#3B6D11'),
    );
    foreach ($ac as $a) {
        $o .= '<rect x="'.$a['x'].'" y="8" width="'.$a['w'].'" height="26" rx="6" fill="'.$a['fi'].'" stroke="'.$a['st'].'" stroke-width="0.5"/>';
        $o .= '<text x="'.$a['cx'].'" y="21" text-anchor="middle" dominant-baseline="central" font-size="11" font-weight="500" fill="'.$a['tc'].'">'
            . htmlspecialchars($a['label'], ENT_QUOTES).'</text>';
        $o .= '<line x1="'.$a['cx'].'" y1="34" x2="'.$a['cx'].'" y2="'.($top+$n*$row_h).'" stroke="'.$a['lc'].'" stroke-width="0.5" stroke-dasharray="5 4"/>';
    }

    foreach ($events as $i => $e) {
        $y  = $top + $i * $row_h + (int)($row_h / 2);
        $c  = $e['color'];

        // Pastille : couleur sévérité pour les événements Factur-X
        $dot_color = isset($severity_colors[$e['severity']]) ? $severity_colors[$e['severity']] : $c;
        // Couleur du texte du label suit la pastille pour les events facturx
        $label_color = $dot_color !== $c ? $dot_color : $c;

        // Date / heure
        $o .= '<text x="4" y="'.($y-6).'" dominant-baseline="central" font-size="10" font-weight="500" fill="#444441">'.htmlspecialchars($e['date'], ENT_QUOTES).'</text>';
        $o .= '<text x="4" y="'.($y+7).'" dominant-baseline="central" font-size="10" fill="#888780">'.htmlspecialchars($e['time'], ENT_QUOTES).'</text>';

        // Cercle (couleur sévérité pour facturx, couleur flux pour les autres)
        $r = ($e['flux'] === 'fournisseur' || $e['flux'] === 'client') ? 7 : 6;
        $o .= '<circle cx="65" cy="'.$y.'" r="'.$r.'" fill="'.$dot_color.'"/>';

        // Label déjà tronqué dans $svg_events — tooltip si présent
        $label_text = htmlspecialchars($e['label'], ENT_QUOTES);
        if (!empty($e['tooltip'])) {
            $o .= '<g style="cursor:help;">'
                . '<title>'.htmlspecialchars($e['tooltip'], ENT_QUOTES).'</title>'
                . '<text x="77" y="'.$y.'" dominant-baseline="central" font-size="10" font-weight="500" fill="'.$label_color.'" text-decoration="underline dotted">'.$label_text.'</text>'
                . '</g>';
        } else {
            $o .= '<text x="77" y="'.$y.'" dominant-baseline="central" font-size="10" font-weight="500" fill="'.$label_color.'">'.$label_text.'</text>';
        }

        // Icône circulaire pour les événements Factur-X internes (pas de flèche)
        if ($e['code'] === 'facturx:generated' || $e['code'] === 'facturx:error') {
            $ix = $cx[0]; $iy = $y; $ir = 8;
            $sx = $ix + $ir * cos(deg2rad(-60)); $sy = $iy + $ir * sin(deg2rad(-60));
            $ex = $ix + $ir * cos(deg2rad(220)); $ey = $iy + $ir * sin(deg2rad(220));
            $o .= sprintf(
                '<path d="M%.1f,%.1f A%d,%d 0 1,1 %.1f,%.1f" fill="none" stroke="%s" stroke-width="1.5" stroke-linecap="round"/>',
                $sx, $sy, $ir, $ir, $ex, $ey, $dot_color
            );
            $ax = $ex; $ay = $ey;
            $o .= sprintf(
                '<polygon points="%.1f,%.1f %.1f,%.1f %.1f,%.1f" fill="%s"/>',
                $ax, $ay, $ax - 4, $ay - 3, $ax - 2, $ay + 4, $dot_color
            );
            // Icône ⚠ + nombre d'avertissements décalés légèrement à gauche du centre
            $nb = (int) $e['warnings_count'];
            if ($nb > 0) {
                // ⚠ à gauche, nombre à droite, ensemble décalé ~4px à gauche du centre de l'arc
                $o .= sprintf(
                    '<text x="%.1f" y="%.1f" text-anchor="middle" dominant-baseline="central" font-size="6" fill="%s">&#x26A0;</text>',
                    $ix - 4.5, $iy + 0.5, $dot_color
                );
                $o .= sprintf(
                    '<text x="%.1f" y="%.1f" text-anchor="middle" dominant-baseline="central" font-size="8" font-weight="600" fill="%s">%d</text>',
                    $ix + 2.5, $iy + 0.5, $dot_color, $nb
                );
            }
        }

        // Flèche entre acteurs
        $fx = $cx[$e['seq_from']]; $tx = $cx[$e['seq_to']];
        if ($fx !== $tx) {
            $x1 = ($fx < $tx) ? $fx+10 : $fx-10;
            $x2 = ($fx < $tx) ? $tx-10 : $tx+10;
            $da = $e['dashed'] ? ' stroke-dasharray="4 3"' : '';
            $sw = ($e['flux'] === 'pdp' && $e['dashed']) ? '1' : '1.5';
            $o .= '<line x1="'.$x1.'" y1="'.$y.'" x2="'.$x2.'" y2="'.$y.'" stroke="'.$c.'" stroke-width="'.$sw.'"'.$da.' marker-end="url(#lcA)"/>';
            $lx = (int)(($x1+$x2)/2);
            $bg = ($e['flux']==='fournisseur') ? '#E6F1FB' : (($e['flux']==='client') ? '#EAF3DE' : '#F1EFE8');
            $o .= '<rect x="'.($lx-44).'" y="'.($y-12).'" width="88" height="16" rx="3" fill="'.$bg.'"/>';
            $o .= '<text x="'.$lx.'" y="'.($y-4).'" text-anchor="middle" dominant-baseline="central" font-size="10" font-weight="500" fill="#2C2C2A">'.htmlspecialchars($e['code'], ENT_QUOTES).'</text>';
        }
    }

    // ── Indicateurs de regroupement ───────────────────────────────────────────
    // Regroupe les indices par group ID, dessine un crochet arrondi sur la gauche
    // pour chaque groupe de 2+ événements issus de la même requête.
    $groupMap = array();
    foreach ($events as $i => $e) {
        if (!isset($e['group'])) continue;
        $groupMap[$e['group']][] = $i;
    }
    foreach ($groupMap as $indices) {
        if (count($indices) < 2) continue;
        sort($indices);
        $iFirst = $indices[0];
        $iLast  = $indices[count($indices) - 1];
        $yTop = $top + $iFirst * $row_h + 6;
        $yBot = $top + ($iLast  + 1) * $row_h - 6;
        $xBar = -8; $w = 4; $rx = 2;
        $o .= '<rect x="'.$xBar.'" y="'.$yTop.'" width="'.$w.'" height="'.($yBot - $yTop).'"'
            . ' rx="'.$rx.'" fill="#e8e6e0" stroke="#888780" stroke-width="0.75"/>';
    }

    $ly = $top + $n * $row_h + 20;
    $lg = array(
        array('x'=>240,'s'=>'#185FA5','l'=>'Fournisseur','t'=>'#0C447C'),
        array('x'=>356,'s'=>'#888780','l'=>'PDP / PA',   't'=>'#444441'),
        array('x'=>458,'s'=>'#3B6D11','l'=>'Client',      't'=>'#27500A'),
    );
    foreach ($lg as $l) {
        $o .= '<line x1="'.$l['x'].'" y1="'.$ly.'" x2="'.($l['x']+26).'" y2="'.$ly.'" stroke="'.$l['s'].'" stroke-width="1.5" marker-end="url(#lcA)"/>';
        $o .= '<text x="'.($l['x']+32).'" y="'.$ly.'" dominant-baseline="central" font-size="10" fill="'.$l['t'].'">'.htmlspecialchars($l['l'], ENT_QUOTES).'</text>';
    }

    // Icône circulaire — action locale (Factur-X)
    $lic = '#888780'; $licx = 545; $lir = 5;
    $lsx = $licx + $lir * cos(deg2rad(-60)); $lsy = $ly + $lir * sin(deg2rad(-60));
    $lex = $licx + $lir * cos(deg2rad(220)); $ley = $ly  + $lir * sin(deg2rad(220));
    $o .= sprintf('<path d="M%.1f,%.1f A%d,%d 0 1,1 %.1f,%.1f" fill="none" stroke="%s" stroke-width="1.2" stroke-linecap="round"/>',
        $lsx, $lsy, $lir, $lir, $lex, $ley, $lic);
    $o .= sprintf('<polygon points="%.1f,%.1f %.1f,%.1f %.1f,%.1f" fill="%s"/>',
        $lex, $ley, $lex - 2.5, $ley - 2, $lex - 1, $ley + 2.5, $lic);
    $o .= sprintf('<text x="%.1f" y="%d" dominant-baseline="central" font-size="10" fill="%s">Action locale</text>',
        $licx + $lir + 5, $ly, $lic);

    $o .= '</svg>';
    return $o;
}
