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
if ($action === 'send_lifecycle_status' && $canWrite) {
    if (!isset($_SESSION['newtoken']) || GETPOST('token', 'alpha') !== $_SESSION['newtoken']) {
        setEventMessages('Erreur CSRF.', null, 'errors');
    } else {
        $status_code = GETPOST('lifecycle_status', 'alphanohtml');
        $allowed_out = lsp_allowed_outgoing(lsp_last_fournisseur_code($db, $fk));
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
                        'message'         => lsp_label($status_code),
                        'direction'       => 'out',
                        'flux'            => 'client',
                        'seen'            => 1,
                        'event_date'      => dol_now(),
                        'payload_raw'     => json_encode($response),
                    ), $user, $fk);
                    $t->status_raw = $status_code;
                    $t->update($user);
                    setEventMessages('Statut ' . dol_escape_htmltag($status_code) . ' envoyé.', null, 'mesgs');
                } catch (Exception $e) {
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
$sql = 'SELECT e.rowid, e.status_code, e.flux, e.direction, e.event_date, e.message'
     . ' FROM ' . MAIN_DB_PREFIX . 'lemonsuperpdp_event e'
     . ' INNER JOIN ' . MAIN_DB_PREFIX . 'lemonsuperpdp_transmission t ON t.rowid = e.fk_transmission'
     . ' WHERE t.fk_facture = ' . $fk
     . ' ORDER BY e.event_date ASC, e.rowid ASC';
$resq = $db->query($sql);
if ($resq) {
    while ($obj = $db->fetch_object($resq)) {
        $events_raw[] = $obj;
    }
}

// ── Injection event d'erreur de transmission ─────────────────────────────────
// Si la transmission est en erreur, on crée un événement synthétique 'ERROR'
// dans le flux PA pour qu'il apparaisse dans la timeline et la colonne PDP/PA.
if ($transmission->id > 0
    && $transmission->status === LemonSuperPDPTransmission::STATUS_ERROR
    && !empty($transmission->error_message)
) {
    $errEvt = new stdClass();
    $errEvt->status_code = 'ERROR';
    $errEvt->flux        = 'pdp';
    $errEvt->direction   = 'in';
    $errEvt->message     = $transmission->error_message;
    // event_date en format DB pour que $db->jdate() fonctionne en aval
    $errEvt->event_date  = $db->idate($transmission->tms ? $transmission->tms : dol_now());
    $events_raw[] = $errEvt;
}

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
    $msg     = isset($e->message) ? (string) $e->message : '';
    $tooltip = ($e->status_code === 'ERROR') ? $msg : '';
    $svg_events[] = array(
        'code'     => $e->status_code,
        'flux'     => $fx,
        'label'    => lsp_label($e->status_code, $msg),
        'tooltip'  => $tooltip,
        'date'     => dol_print_date($db->jdate($e->event_date), 'day'),
        'time'     => dol_print_date($db->jdate($e->event_date), 'hour'),
        'seq_from' => lsp_seq_from($e->status_code, $fx),
        'seq_to'   => lsp_seq_to($e->status_code, $fx),
        'color'    => lsp_color($e->status_code, $fx),
        'dashed'   => lsp_dashed($e->status_code),
    );
}

$allowed = lsp_allowed_outgoing($fc);

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
  </div>

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
    foreach ($actors as $fk2 => $actor):
        $evt = $last[$fk2];
    ?>
    <div style="flex:1;display:flex;flex-direction:column;gap:2px;padding:6px 10px;border-radius:6px;border:1px solid #e0ddd6;background:#fff;">
      <div style="display:flex;align-items:center;gap:5px;margin-bottom:2px;">
        <span style="width:7px;height:7px;border-radius:50%;background:<?php echo $actor['dot']; ?>;flex-shrink:0;"></span>
        <span style="font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.04em;color:<?php echo $actor['color']; ?>;"><?php echo dol_escape_htmltag($actor['label']); ?></span>
      </div>
      <?php if ($evt): ?>
      <?php $evtMsg = isset($evt->message) ? (string) $evt->message : ''; ?>
      <div style="font-size:12px;font-weight:500;color:#2c2c2a;<?php echo ($evt->status_code === 'ERROR') ? 'text-decoration:underline dotted;cursor:help;' : ''; ?>"
           <?php echo ($evt->status_code === 'ERROR' && $evtMsg) ? 'title="'.dol_escape_htmltag($evtMsg).'"' : ''; ?>>
        <?php echo dol_escape_htmltag(lsp_label($evt->status_code, $evtMsg)); ?>
      </div>
      <div style="font-size:10px;color:#888780;margin-top:2px;"><?php echo dol_print_date($db->jdate($evt->event_date), 'dayhour'); ?></div>
      <?php else: ?>
      <div style="font-size:11px;color:#b4b2a9;font-style:italic;"><?php echo $langs->trans('LemonSuperPDPNoEvent'); ?></div>
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
    $fournisseur = array('fr:200','fr:201','fr:202','fr:203','fr:204','fr:205');
    $pdp         = array('ACK','ACK-01','ACK-02','REJECT','ROUTE','ERROR');
    if (in_array($code, $fournisseur, true)) return 'fournisseur';
    if (in_array($code, $pdp, true))         return 'pdp';
    return 'client';
}

function lsp_label($code, $override = '')
{
    if ($code === 'ERROR') return 'Erreur';
    if (!empty($override)) return $override;
    $map = array(
        'fr:200'=>'Déposée','fr:201'=>'En cours de traitement','fr:202'=>'Comptabilisée',
        'fr:203'=>'En attente','fr:204'=>'Mise à disposition','fr:205'=>'Prise en charge',
        'fr:206'=>'Approuvée','fr:207'=>'Approuvée partiellement','fr:208'=>'Paiement en cours',
        'fr:209'=>'Paiement transmis','fr:210'=>'Refusée','fr:211'=>'Litige','fr:212'=>'Encaissée',
        'ACK'=>'Accusé de réception','ACK-01'=>'Accusé réception','ACK-02'=>'Validation format',
        'REJECT'=>'Rejet technique','ROUTE'=>'Routage confirmé',
    );
    return isset($map[$code]) ? $map[$code] : $code;
}

function lsp_color($code, $flux = '')
{
    if ($flux === '' || $flux === null) $flux = lsp_flux($code);
    if ($code === 'ERROR')       return '#A32D2D';
    if ($flux === 'fournisseur') return '#185FA5';
    if ($flux === 'pdp')         return '#888780';
    if ($code === 'fr:210')      return '#A32D2D';
    if ($code === 'fr:211')      return '#854F0B';
    return '#3B6D11';
}

function lsp_dashed($code)
{
    return in_array($code, array('ACK','ACK-01','ACK-02','ROUTE','ERROR','fr:210','fr:211'), true);
}

function lsp_seq_from($code, $flux = '')
{
    if ($code === 'ERROR')       return 1;   // part de la colonne PA
    if ($flux === 'fournisseur') return 0;
    if ($flux === 'client')      return 2;
    if (in_array($code, array('ACK','ACK-01','ACK-02','REJECT'), true)) return 1;
    return 1;
}

function lsp_seq_to($code, $flux = '')
{
    if ($code === 'ERROR')       return 0;   // flèche retour vers Fournisseur
    if ($flux === 'fournisseur') return 1;
    if ($flux === 'client')      return 1;
    if (in_array($code, array('ACK','ACK-01','ACK-02','REJECT'), true)) return 0;
    return 2;
}

function lsp_last_fournisseur_code($db, $fk)
{
    $sql = 'SELECT e.status_code FROM ' . MAIN_DB_PREFIX . 'lemonsuperpdp_event e'
         . ' INNER JOIN ' . MAIN_DB_PREFIX . 'lemonsuperpdp_transmission t ON t.rowid = e.fk_transmission'
         . ' WHERE t.fk_facture = ' . (int) $fk
         . " AND e.status_code IN ('fr:204','fr:205')"
         . ' ORDER BY e.event_date DESC, e.rowid DESC LIMIT 1';
    $res = $db->query($sql);
    if ($res && $db->num_rows($res) > 0) {
        return $db->fetch_object($res)->status_code;
    }
    return '';
}

function lsp_allowed_outgoing($last_fournisseur = '')
{
    // Seuls les statuts fournisseur peuvent être émis manuellement depuis cette interface.
    // fr:212 (Encaissée) est déclenché automatiquement par le trigger BILL_PAYED.
    if ($last_fournisseur === 'fr:205') return array();
    if ($last_fournisseur === 'fr:204') return array('fr:205' => 'Prise en charge');
    return array('fr:204' => 'Mise à disposition');
}

function lsp_render_svg(array $events)
{
    $row_h = 55; $top = 44; $bot = 40;
    $n = count($events);
    if ($n === 0) return '';
    $h = $top + $n * $row_h + $bot;
    $cx = array(0 => 288, 1 => 450, 2 => 610);

    $o  = '<svg width="100%" viewBox="0 0 660 ' . $h . '" style="display:block;margin:8px 0;">';
    $o .= '<defs><marker id="lcA" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="5" markerHeight="5" orient="auto-start-reverse">'
        . '<path d="M2 1L8 5L2 9" fill="none" stroke="context-stroke" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>'
        . '</marker></defs>';
    $o .= '<line x1="118" y1="' . ($top-4) . '" x2="118" y2="' . ($top+$n*$row_h) . '" stroke="#d3d1c7" stroke-width="0.5"/>';

    $ac = array(
        array('label'=>'Fournisseur','x'=>240,'w'=>96,'cx'=>288,'fi'=>'#E6F1FB','st'=>'#B5D4F4','tc'=>'#0C447C','lc'=>'#185FA5'),
        array('label'=>'PDP / PA',  'x'=>407,'w'=>86,'cx'=>450,'fi'=>'#F1EFE8','st'=>'#D3D1C7','tc'=>'#444441','lc'=>'#888780'),
        array('label'=>'Client',     'x'=>567,'w'=>86,'cx'=>610,'fi'=>'#EAF3DE','st'=>'#C0DD97','tc'=>'#27500A','lc'=>'#3B6D11'),
    );
    foreach ($ac as $a) {
        $o .= '<rect x="'.$a['x'].'" y="8" width="'.$a['w'].'" height="26" rx="6" fill="'.$a['fi'].'" stroke="'.$a['st'].'" stroke-width="0.5"/>';
        $o .= '<text x="'.$a['cx'].'" y="21" text-anchor="middle" dominant-baseline="central" font-size="12" font-weight="500" fill="'.$a['tc'].'">'
            . htmlspecialchars($a['label'], ENT_QUOTES) . '</text>';
        $o .= '<line x1="'.$a['cx'].'" y1="34" x2="'.$a['cx'].'" y2="'.($top+$n*$row_h).'" stroke="'.$a['lc'].'" stroke-width="0.5" stroke-dasharray="5 4"/>';
    }

    foreach ($events as $i => $e) {
        $y = $top + $i * $row_h + (int)($row_h / 2);
        $r = ($e['flux'] === 'fournisseur' || $e['flux'] === 'client') ? 7 : 6;
        $c = $e['color'];
        $o .= '<text x="4" y="'.($y-6).'" dominant-baseline="central" font-size="11" font-weight="500" fill="#444441">'.htmlspecialchars($e['date'], ENT_QUOTES).'</text>';
        $o .= '<text x="4" y="'.($y+7).'" dominant-baseline="central" font-size="11" fill="#888780">'.htmlspecialchars($e['time'], ENT_QUOTES).'</text>';
        $o .= '<circle cx="118" cy="'.$y.'" r="'.$r.'" fill="'.$c.'"/>';
        // Label — si tooltip présent, on l'enveloppe dans un <g><title> pour le survol
        if (!empty($e['tooltip'])) {
            $o .= '<g style="cursor:help;">'
                . '<title>'.htmlspecialchars($e['tooltip'], ENT_QUOTES).'</title>'
                . '<text x="130" y="'.$y.'" dominant-baseline="central" font-size="12" font-weight="500" fill="'.$c.'" text-decoration="underline dotted">'.htmlspecialchars($e['label'], ENT_QUOTES).'</text>'
                . '</g>';
        } else {
            $o .= '<text x="130" y="'.$y.'" dominant-baseline="central" font-size="12" font-weight="500" fill="'.$c.'">'.htmlspecialchars($e['label'], ENT_QUOTES).'</text>';
        }

        $fx = $cx[$e['seq_from']]; $tx = $cx[$e['seq_to']];
        if ($fx === $tx) continue;
        $x1 = ($fx < $tx) ? $fx+10 : $fx-10;
        $x2 = ($fx < $tx) ? $tx-10 : $tx+10;
        $da = $e['dashed'] ? ' stroke-dasharray="4 3"' : '';
        $sw = ($e['flux'] === 'pdp' && $e['dashed']) ? '1' : '1.5';
        $o .= '<line x1="'.$x1.'" y1="'.$y.'" x2="'.$x2.'" y2="'.$y.'" stroke="'.$c.'" stroke-width="'.$sw.'"'.$da.' marker-end="url(#lcA)"/>';
        $lx = (int)(($x1+$x2)/2);
        $bg = ($e['flux']==='fournisseur') ? '#E6F1FB' : (($e['flux']==='client') ? '#EAF3DE' : '#F1EFE8');
        $o .= '<rect x="'.($lx-44).'" y="'.($y-12).'" width="88" height="16" rx="3" fill="'.$bg.'"/>';
        $o .= '<text x="'.$lx.'" y="'.($y-4).'" text-anchor="middle" dominant-baseline="central" font-size="11" font-weight="500" fill="#2C2C2A">'.htmlspecialchars($e['code'], ENT_QUOTES).'</text>';
    }

    $ly = $top + $n * $row_h + 20;
    $lg = array(
        array('x'=>240,'s'=>'#185FA5','l'=>'Fournisseur','t'=>'#0C447C'),
        array('x'=>356,'s'=>'#888780','l'=>'PDP / PA',   't'=>'#444441'),
        array('x'=>458,'s'=>'#3B6D11','l'=>'Client',      't'=>'#27500A'),
    );
    foreach ($lg as $l) {
        $o .= '<line x1="'.$l['x'].'" y1="'.$ly.'" x2="'.($l['x']+26).'" y2="'.$ly.'" stroke="'.$l['s'].'" stroke-width="1.5" marker-end="url(#lcA)"/>';
        $o .= '<text x="'.($l['x']+32).'" y="'.$ly.'" dominant-baseline="central" font-size="11" fill="'.$l['t'].'">'.htmlspecialchars($l['l'], ENT_QUOTES).'</text>';
    }
    $o .= '</svg>';
    return $o;
}
