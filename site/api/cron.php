<?php
/**
 * RedHot Design — the automation tick.
 * Runs every 15 minutes from cPanel. Moves every deal it can, and
 * nudges the ones that have gone quiet.
 *
 * Protected by a secret in the URL so it cannot be triggered by anyone.
 *   https://redhotdesign.co.za/api/cron.php?k=YOUR_CRON_KEY
 */
declare(strict_types=1);
require_once __DIR__ . '/_store.php';
require_once __DIR__ . '/_zoho.php';
require_once __DIR__ . '/_flow.php';

$cfg = rh_config();
$key = (string)($cfg['cron_key'] ?? '');
$cli = PHP_SAPI === 'cli';
if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
    if ($key === '' || !hash_equals($key, (string)($_GET['k'] ?? ''))) {
        http_response_code(403); exit("no\n");
    }
}

$now = time();
$out = [];

foreach (rh_deals() as $d) {
    $ref   = (string)($d['ref'] ?? '');
    $stage = (string)($d['stage'] ?? 'new');
    if ($ref === '' || in_array($stage, ['live', 'lost'], true)) continue;

    // 1. pull Zoho forward wherever it can move
    if (in_array($stage, ['quoting', 'quoted', 'deposit'], true)) {
        $out[] = $ref . ' ' . $stage . ' → ' . rh_flow_sync_one($ref);
    }

    // 2. chase the quiet ones, politely, and only so often
    $since = $now - strtotime((string)($d['updated'] ?? $d['ts'] ?? 'now'));
    $days  = (int)floor($since / 86400);
    $sent  = (array)($d['nudges'] ?? []);

    $plan = match ($stage) {
        'quoted' => [3, 8, 16],   // waiting on a decision
        'deposit'=> [3, 7],       // waiting on money
        'intake' => [2, 5, 10],   // waiting on content
        'staged' => [4, 9],       // waiting on sign-off
        default  => [],
    };

    foreach ($plan as $d_day) {
        $tag = $stage . ':' . $d_day;
        if ($days < $d_day || in_array($tag, $sent, true)) continue;

        $name = rh_first_name($d);
        [$subject, $body] = match ($stage) {
            'quoted'  => ['Still thinking it over? · ' . $ref,
                "<p>Hi {$name},</p><p>Just checking the quote reached you and made sense.
                 If the scope or the number is not right, say so and I will redo it.
                 If the timing is wrong, tell me when to come back.</p>"],
            'deposit' => ['Ready when you are · ' . $ref,
                "<p>Hi {$name},</p><p>The deposit invoice is still open. Once it is settled
                 we start, and I will send you the short intake form the same day.</p>"],
            'intake'  => ['One form standing in the way · ' . $ref,
                "<p>Hi {$name},</p><p>We are booked and paid, and waiting on the intake form
                 before we can start. It takes a few minutes.</p>
                 <p style='margin:20px 0'><a href='" . rtrim($cfg['site_url'],'/')
                 . '/intake/?t=' . urlencode((string)($d['intake_token'] ?? ''))
                 . "' style='background:#ff0000;color:#fff;padding:12px 22px;text-decoration:none;
                 font-weight:700;display:inline-block'>Open the form</a></p>"],
            'staged'  => ['Any thoughts on the staging site? · ' . $ref,
                "<p>Hi {$name},</p><p>Your site is still sitting on staging waiting for a look.
                 Send me everything you want changed in one reply and I will work through it.</p>
                 <p style='margin:20px 0'><a href='" . htmlspecialchars((string)($d['stage_url'] ?? ''), ENT_QUOTES)
                 . "' style='background:#ff0000;color:#fff;padding:12px 22px;text-decoration:none;
                 font-weight:700;display:inline-block'>View your site</a></p>"],
            default   => ['', ''],
        };

        if ($subject !== '' && !empty($d['email'])) {
            rh_mail((string)$d['email'], (string)($d['name'] ?? ''), $subject,
                rh_email_shell($body, 'Reference ' . $ref), $cfg['notify_to']);
            $sent[] = $tag;
            rh_patch($ref, ['nudges' => $sent], 'system', 'Follow-up sent (day ' . $d_day . ')');
            $out[] = $ref . ' nudged ' . $tag;
        }
        break; // one nudge per tick, never a pile-up
    }
}

@file_put_contents(rtrim($cfg['data_dir'], '/') . '/.heartbeat',
    date('c') . ' ' . (PHP_SAPI === 'cli' ? 'cron' : 'web') . ' ' . count($out) . " action(s)\n");

echo ($out ? implode("\n", $out) : 'nothing to do') . "\n";
