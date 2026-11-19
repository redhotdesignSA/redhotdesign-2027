<?php
/**
 * RedHot Design — deal store + state machine
 *
 * One JSON file, flock'd. No database dependency, readable in File Manager,
 * and correct for the volume this pipeline will ever see.
 *
 * Every deal moves through STAGES. Stages marked `hold` wait for Norman.
 * Everything else advances by itself.
 */

declare(strict_types=1);

// The server clock is UTC. Everything Norman reads should be his local time.
date_default_timezone_set('Africa/Johannesburg');

const RH_STAGES = [
    'new'          => ['label' => 'New enquiry',        'hold' => true,  'next' => 'quoting',
                       'you'   => 'Read it, then start the quote'],
    'quoting'      => ['label' => 'Quote being built',  'hold' => true,  'next' => 'quoted',
                       'you'   => 'Build the quote in Zoho with this reference'],
    'quoted'       => ['label' => 'Quote sent',         'hold' => false, 'next' => 'accepted',
                       'you'   => 'Waiting on the client'],
    'accepted'     => ['label' => 'Quote accepted',     'hold' => false, 'next' => 'deposit',
                       'you'   => 'Deposit invoice raised automatically'],
    'deposit'      => ['label' => 'Awaiting deposit',   'hold' => true,  'next' => 'intake',
                       'you'   => 'Tick when the deposit lands'],
    'intake'       => ['label' => 'Client intake sent', 'hold' => false, 'next' => 'ready',
                       'you'   => 'Waiting on content and access'],
    'ready'        => ['label' => 'Ready to build',     'hold' => true,  'next' => 'building',
                       'you'   => 'Everything is in. Start the build'],
    'building'     => ['label' => 'In build',           'hold' => true,  'next' => 'staged',
                       'you'   => 'Paste the staging URL when it is up'],
    'staged'       => ['label' => 'Staged for review',  'hold' => false, 'next' => 'approved',
                       'you'   => 'Waiting on client sign-off'],
    'approved'     => ['label' => 'Approved',           'hold' => true,  'next' => 'live',
                       'you'   => 'Deploy it'],
    'live'         => ['label' => 'Live',               'hold' => false, 'next' => null,
                       'you'   => 'Done'],
    'lost'         => ['label' => 'Closed out',         'hold' => false, 'next' => null,
                       'you'   => ''],
];

function rh_config(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    // Walk up from here until we find the config. This keeps working whether
    // the site is served from the web root or from a staging subdirectory.
    $path = ''; $home = dirname(__DIR__);
    for ($i = 0, $d = __DIR__; $i < 6; $i++) {
        $d = dirname($d);
        if ($d === '/' || $d === '.') break;
        if (is_readable($d . '/redhot-form-config.php')) { $path = $d . '/redhot-form-config.php'; $home = $d; break; }
    }
    $cfg = $path !== '' ? (array)(require $path) : [];
    $cfg += [
        'brevo_api_key' => '', 'notify_to' => 'hello@redhotdesign.co.za',
        'notify_name' => 'RedHot Design', 'from_email' => 'forms@redhotdesign.co.za',
        'from_name' => 'RedHot Design', 'data_dir' => $home . '/redhot-leads',
        'studio_pass_hash' => '', 'site_url' => 'https://redhotdesign.co.za',
        'zoho_client_id' => '', 'zoho_client_secret' => '', 'zoho_refresh_token' => '',
        'zoho_org_id' => '', 'zoho_accounts' => 'https://accounts.zoho.com',
        'zoho_api' => 'https://www.zohoapis.com/invoice/v3',
        'deposit_percent' => 50,
    ];
    return $cfg;
}

function rh_store_path(): string {
    $dir = rtrim(rh_config()['data_dir'], '/');
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    return $dir . '/deals.json';
}

/** Read-modify-write under an exclusive lock. $fn gets &$deals. */
function rh_transact(callable $fn) {
    $path = rh_store_path();
    $fh = fopen($path, 'c+');
    if (!$fh) throw new RuntimeException('deal store unavailable');
    flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $deals = $raw === '' ? [] : (json_decode($raw, true) ?: []);
    $result = $fn($deals);
    $out = json_encode(array_values($deals), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    ftruncate($fh, 0); rewind($fh); fwrite($fh, $out); fflush($fh);
    flock($fh, LOCK_UN); fclose($fh);
    return $result;
}

function rh_deals(): array {
    $path = rh_store_path();
    if (!is_readable($path)) return [];
    return json_decode((string)file_get_contents($path), true) ?: [];
}

function rh_find(string $ref): ?array {
    foreach (rh_deals() as $d) if (($d['ref'] ?? '') === $ref) return $d;
    return null;
}

function rh_log(array &$deal, string $who, string $what): void {
    $deal['log'][] = ['t' => gmdate('c'), 'who' => $who, 'what' => $what];
    $deal['updated'] = gmdate('c');
}

/** Move a deal to a stage. Returns the updated deal or null. */
function rh_advance(string $ref, string $stage, string $who = 'system', string $note = ''): ?array {
    if (!isset(RH_STAGES[$stage])) return null;
    return rh_transact(function (array &$deals) use ($ref, $stage, $who, $note) {
        foreach ($deals as &$d) {
            if (($d['ref'] ?? '') !== $ref) continue;
            if (($d['stage'] ?? '') === $stage) return $d;
            $from = $d['stage'] ?? 'new';
            $d['stage'] = $stage;
            rh_log($d, $who, ($note !== '' ? $note : 'Moved from ' . $from . ' to ' . $stage));
            return $d;
        }
        return null;
    });
}

function rh_patch(string $ref, array $fields, string $who = 'system', string $note = ''): ?array {
    return rh_transact(function (array &$deals) use ($ref, $fields, $who, $note) {
        foreach ($deals as &$d) {
            if (($d['ref'] ?? '') !== $ref) continue;
            foreach ($fields as $k => $v) $d[$k] = $v;
            if ($note !== '') rh_log($d, $who, $note);
            else $d['updated'] = gmdate('c');
            return $d;
        }
        return null;
    });
}

function rh_token(): string { return rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '='); }

/** Send HTML mail through Brevo. Returns true on success. */
function rh_mail(string $toEmail, string $toName, string $subject, string $html, ?string $replyTo = null): bool {
    $cfg = rh_config();
    if ($cfg['brevo_api_key'] === '') return false;
    $body = [
        'sender'      => ['name' => $cfg['from_name'], 'email' => $cfg['from_email']],
        'to'          => [['email' => $toEmail, 'name' => $toName ?: $toEmail]],
        'subject'     => $subject,
        'htmlContent' => $html,
    ];
    if ($replyTo) $body['replyTo'] = ['email' => $replyTo];
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => ['accept: application/json', 'content-type: application/json',
                               'api-key: ' . $cfg['brevo_api_key']],
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

/** Shared email shell so every message looks like it came from one place. */
function rh_email_shell(string $inner, string $footNote = ''): string {
    return '<div style="font:15px/1.65 -apple-system,Segoe UI,Roboto,sans-serif;color:#222;max-width:560px">'
        . $inner
        . '<p style="margin-top:26px;padding-top:18px;border-top:1px solid #e5e5e5;color:#777;font-size:13px">'
        . 'Norman Visser · RedHot Design · Gqeberha · since 2008'
        . ($footNote ? '<br>' . $footNote : '')
        . '</p></div>';
}
