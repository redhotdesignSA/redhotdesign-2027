<?php
/**
 * RedHot Design — Studio actions.
 * Every button on the board posts here. Each one clears a blocker and
 * hands control straight back to the automation.
 */
declare(strict_types=1);
require_once __DIR__ . '/../api/_store.php';
require_once __DIR__ . '/../api/_zoho.php';
require_once __DIR__ . '/../api/_flow.php';
require_once __DIR__ . '/_auth.php';
rh_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !rh_csrf_ok($_POST['csrf'] ?? null)) {
    header('Location: /studio/'); exit;
}

$ref = trim((string)($_POST['ref'] ?? ''));
$do  = (string)($_POST['do'] ?? '');
$deal = rh_find($ref);
if (!$deal) { header('Location: /studio/'); exit; }

switch ($do) {

    // Norman has read it. Client gets told a human is on it, deal moves to quoting.
    case 'acknowledge':
        rh_flow_acknowledge($deal);
        break;

    // Poll Zoho on demand instead of waiting for the cron.
    case 'checkzoho':
        rh_flow_sync_one($ref);
        break;

    // Deposit landed. This is the gate into delivery.
    case 'deposit_in':
        rh_flow_deposit_received($deal);
        break;

    case 'nudge_intake':
        rh_flow_send_intake($deal, true);
        break;

    case 'force_ready':
        rh_advance($ref, 'ready', 'norman', 'Marked intake complete by hand');
        break;

    case 'start_build':
        rh_advance($ref, 'building', 'norman', 'Build started');
        break;

    // Staging URL posted. Client is told, and asked to review.
    case 'staged':
        $url = filter_var((string)($_POST['stage_url'] ?? ''), FILTER_VALIDATE_URL);
        if ($url) rh_flow_staged($deal, $url);
        break;

    case 'approved':
        rh_advance($ref, 'approved', 'norman', 'Client approved the staging site');
        break;

    case 'live':
        rh_flow_live($deal);
        break;

    case 'lost':
        rh_advance($ref, 'lost', 'norman', 'Closed out');
        break;
}

header('Location: /studio/?f=' . urlencode((string)($_POST['f'] ?? 'open')) . '#' . urlencode($ref));
