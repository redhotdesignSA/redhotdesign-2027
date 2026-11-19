<?php
/**
 * RedHot Design — lead capture endpoint
 * PHP 8.3 · hostserv cPanel
 *
 * Config lives ONE LEVEL ABOVE public_html (same pattern as Branson):
 *   /home/<cpaneluser>/redhot-form-config.php
 *
 * Stores every lead to disk FIRST, then notifies. Storage is the source of
 * truth: if Brevo is down, the lead is still on disk and still replied to.
 */

declare(strict_types=1);
require_once __DIR__ . '/_store.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

// ── only POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'method_not_allowed']));
}

// ── same-origin guard. Accepts this host (so staging subdomains and local
//    previews work unchanged) plus the production hostnames.
$selfHost = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
$allowed  = array_filter([$selfHost, 'redhotdesign.co.za', 'www.redhotdesign.co.za']);
$originHost = parse_url($_SERVER['HTTP_ORIGIN'] ?? '', PHP_URL_HOST)
           ?: parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_HOST);
$originHost = strtolower((string)$originHost);
if ($originHost !== '' && !in_array($originHost, $allowed, true)) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'bad_origin']));
}

// ── config
$configPath = dirname(__DIR__, 2) . '/redhot-form-config.php';
$cfg = is_readable($configPath) ? (require $configPath) : [];
$cfg += [
    'brevo_api_key' => '',
    'notify_to'     => 'hello@redhotdesign.co.za',
    'notify_name'   => 'RedHot Design',
    'from_email'    => 'forms@redhotdesign.co.za',
    'from_name'     => 'RedHot Design Website',
    'data_dir'      => dirname(__DIR__, 2) . '/redhot-leads',
];

// ── read body (JSON or form-encoded)
$raw = file_get_contents('php://input') ?: '';
$in  = json_decode($raw, true);
if (!is_array($in)) { $in = $_POST; }

$clean = static function (?string $v, int $max = 300): string {
    $v = trim((string)$v);
    $v = preg_replace('/[\r\n\t]+/', ' ', $v) ?? '';
    return mb_substr($v, 0, $max);
};

// ── honeypot: bots fill hidden fields
if ($clean($in['website_url'] ?? '') !== '') {
    exit(json_encode(['ok' => true, 'ref' => 'RH-0000'])); // silently drop
}

// ── minimum viable lead
$name    = $clean($in['name'] ?? '', 120);
$company = $clean($in['company'] ?? '', 160);
$email   = $clean($in['email'] ?? '', 180);
$site    = $clean($in['url'] ?? '', 300);
$phone   = $clean($in['phone'] ?? '', 60);

$errors = [];
if ($name === '')    { $errors[] = 'name'; }
if ($company === '') { $errors[] = 'company'; }
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'email'; }
if ($errors) {
    http_response_code(422);
    exit(json_encode(['ok' => false, 'error' => 'invalid', 'fields' => $errors]));
}

// ── qualifier answers
$track   = $clean($in['track'] ?? '', 80);     // spend | rebuild | both
$spend   = $clean($in['spend'] ?? '', 80);
$problem = $clean($in['problem'] ?? '', 200);
$urgency = $clean($in['urgency'] ?? '', 80);
$agency  = $clean($in['agency'] ?? '', 120);
$notes   = $clean($in['notes'] ?? '', 1200);
$consent = !empty($in['consent']);

// ── score: decides routing and SLA
$score = 0;
$band  = 'low';
if (str_contains($spend, 'Over R250')) { $score += 50; }
elseif (str_contains($spend, 'R75'))   { $score += 35; }
elseif (str_contains($spend, 'R25'))   { $score += 18; }
if (str_contains($urgency, 'Immediately')) { $score += 25; }
elseif (str_contains($urgency, 'quarter'))  { $score += 12; }
if ($track === 'both')    { $score += 15; }
if ($track === 'rebuild') { $score += 8; }
if ($phone !== '')        { $score += 5; }
if ($score >= 60)      { $band = 'hot'; }
elseif ($score >= 35)  { $band = 'warm'; }

$ref = 'RH-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

$record = [
    'ref'       => $ref,
    'ts'        => gmdate('c'),
    'band'      => $band,
    'score'     => $score,
    'name'      => $name,
    'company'   => $company,
    'email'     => $email,
    'phone'     => $phone,
    'site'      => $site,
    'track'     => $track,
    'spend'     => $spend,
    'problem'   => $problem,
    'urgency'   => $urgency,
    'agency'    => $agency,
    'notes'     => $notes,
    'consent'   => $consent,
    'source'    => $clean($in['src'] ?? '', 200),
    'referrer'  => $clean($in['ref_url'] ?? '', 300),
    'ip_hash'   => hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|redhot'), // POPIA: no raw IP
    'ua'        => $clean($_SERVER['HTTP_USER_AGENT'] ?? '', 250),
];

// ── 1. PERSIST FIRST. nothing is lost even if mail fails.
$stored = false;
$dir = rtrim((string)$cfg['data_dir'], '/');
if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
if (is_dir($dir) && is_writable($dir)) {
    $file = $dir . '/leads-' . gmdate('Y-m') . '.ndjson';
    $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    $stored = (bool)@file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

// ── 1b. put it on the Studio board
try {
    $deal = $record + ['stage' => 'new', 'updated' => gmdate('c'),
                       'log' => [['t' => gmdate('c'), 'who' => 'website', 'what' => 'Enquiry received']]];
    rh_transact(function (array &$deals) use ($deal) { $deals[] = $deal; });
    $stored = true;
} catch (Throwable $ex) { /* ndjson above is still the backstop */ }

// ── 2. notify Norman
$rows = static fn(array $pairs): string => implode('', array_map(
    static fn($k, $v) => $v === '' || $v === null ? '' :
        '<tr><td style="padding:6px 14px 6px 0;color:#777;font:13px system-ui;vertical-align:top">'
        . htmlspecialchars((string)$k) .
        '</td><td style="padding:6px 0;font:14px system-ui;color:#111">'
        . nl2br(htmlspecialchars((string)$v)) . '</td></tr>',
    array_keys($pairs), $pairs
));

$bandColour = ['hot' => '#ff0000', 'warm' => '#c47d00', 'low' => '#666'][$band];
$trackLabel = ['spend' => 'Ad spend', 'rebuild' => 'Site rebuild', 'both' => 'Both'][$track] ?? $track;

$html = '<div style="font:15px system-ui;max-width:640px">'
  . '<p style="font:700 13px system-ui;letter-spacing:.12em;text-transform:uppercase;color:' . $bandColour . ';margin:0 0 4px">'
  . htmlspecialchars(strtoupper($band)) . ' LEAD · score ' . $score . '</p>'
  . '<h2 style="margin:0 0 2px;font:800 22px system-ui">' . htmlspecialchars($company) . '</h2>'
  . '<p style="margin:0 0 18px;color:#666">' . htmlspecialchars($name) . ' · ' . htmlspecialchars($ref) . '</p>'
  . '<table style="border-collapse:collapse">' . $rows([
        'Track'      => $trackLabel,
        'Spend'      => $spend,
        'Problem'    => $problem,
        'Urgency'    => $urgency,
        'Agency'     => $agency,
        'Email'      => $email,
        'Phone'      => $phone,
        'Website'    => $site,
        'Notes'      => $notes,
        'Came from'  => $record['source'],
    ]) . '</table>'
  . '<p style="margin:22px 0 0"><a href="mailto:' . htmlspecialchars($email)
  . '?subject=' . rawurlencode('RedHot Design · ' . $company)
  . '" style="background:#ff0000;color:#fff;padding:11px 20px;text-decoration:none;font:700 14px system-ui">Reply to '
  . htmlspecialchars(explode(' ', $name)[0]) . '</a></p>'
  . '<p style="margin:14px 0 0"><a href="' . htmlspecialchars(rtrim((string)$cfg['site_url'], '/')) . '/studio/#' . htmlspecialchars($ref)
  . '" style="color:#555;font:13px system-ui">Open on the board &rarr;</a></p>'
  . ($stored ? '' : '<p style="color:#b00;font:13px system-ui">⚠ Disk write failed. This email is the only copy.</p>')
  . '</div>';

$sent = false;
if ($cfg['brevo_api_key'] !== '') {
    $payload = [
        'sender'      => ['name' => $cfg['from_name'], 'email' => $cfg['from_email']],
        'to'          => [['email' => $cfg['notify_to'], 'name' => $cfg['notify_name']]],
        'replyTo'     => ['email' => $email, 'name' => $name],
        'subject'     => sprintf('[%s] %s · %s', strtoupper($band), $company, $trackLabel ?: 'Enquiry'),
        'htmlContent' => $html,
    ];
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => [
            'accept: application/json',
            'content-type: application/json',
            'api-key: ' . $cfg['brevo_api_key'],
        ],
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $sent = $code >= 200 && $code < 300;

    // ── 3. acknowledge the prospect (only with consent)
    if ($sent && $consent) {
        $ack = '<div style="font:15px/1.6 system-ui;max-width:560px;color:#222">'
          . '<p>Hi ' . htmlspecialchars(explode(' ', $name)[0]) . ',</p>'
          . '<p>Thanks, we have your details. Your reference is <strong>' . htmlspecialchars($ref) . '</strong>.</p>'
          . '<p>Before we reply we look at your site and what the ad platforms are doing, so the first conversation is not a discovery call. '
          . 'You will hear from us within one business day with what we would look at first, in writing.</p>'
          . '<p>If it is not a fit, we will say so and point you somewhere better.</p>'
          . '<p style="margin-top:22px">Norman Visser<br><span style="color:#777">RedHot Design · Gqeberha · since 2008</span></p>'
          . '</div>';
        $ch2 = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_POSTFIELDS     => json_encode([
                'sender'      => ['name' => 'RedHot Design', 'email' => $cfg['from_email']],
                'to'          => [['email' => $email, 'name' => $name]],
                'replyTo'     => ['email' => $cfg['notify_to'], 'name' => 'RedHot Design'],
                'subject'     => 'We have your enquiry · ' . $ref,
                'htmlContent' => $ack,
            ], JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => [
                'accept: application/json',
                'content-type: application/json',
                'api-key: ' . $cfg['brevo_api_key'],
            ],
        ]);
        curl_exec($ch2);
        curl_close($ch2);
    }
}

// ── never tell the browser it failed if we have the lead on disk
if (!$stored && !$sent) {
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => 'not_captured']));
}

echo json_encode(['ok' => true, 'ref' => $ref, 'band' => $band]);
