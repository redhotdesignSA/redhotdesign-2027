<?php
/**
 * RedHot Design — the flow engine.
 *
 * Each function is one automated step. They are called either by a Studio
 * button (you clearing a blocker) or by cron.php (the automation resuming).
 *
 * The whole pipeline, and who does what:
 *
 *   enquiry        AUTO   stored, scored, you are emailed
 *   acknowledge    YOU    one click. client is told, deal enters quoting
 *   build quote    YOU    in Zoho, your figures, your control
 *   quote found    AUTO   cron sees the estimate, sends it, tells the client
 *   accepted       AUTO   cron sees acceptance, raises the deposit invoice
 *   deposit paid   AUTO   cron sees payment  (or YOU tick the box)
 *   intake         AUTO   client gets a tokenised form for content and access
 *   intake done    AUTO   you are told everything is in
 *   build          YOU    one click to start, one to post the staging link
 *   staged         AUTO   client is told to review
 *   approved       YOU    one click
 *   live           AUTO   client is told, balance reminder queued for you
 */

declare(strict_types=1);
require_once __DIR__ . '/_store.php';
require_once __DIR__ . '/_zoho.php';

function rh_first_name(array $d): string {
    return trim(explode(' ', trim((string)($d['name'] ?? '')))[0] ?: 'there');
}

/* ───────────────────────── 1. you acknowledge ───────────────────────── */
function rh_flow_acknowledge(array $d): void {
    $name = rh_first_name($d);
    rh_mail((string)$d['email'], (string)($d['name'] ?? ''),
        'Your enquiry · ' . $d['ref'],
        rh_email_shell(
            "<p>Hi {$name},</p>
             <p>I have been through your enquiry and had a look at your site. I am putting
             together what we would do first and what it would cost, and you will have it
             shortly.</p>
             <p>If anything has changed in the meantime, just reply to this message.</p>",
            'Reference ' . $d['ref']
        ),
        rh_config()['notify_to']);
    rh_advance((string)$d['ref'], 'quoting', 'norman', 'Acknowledged the client, quote in progress');
}

/* ─────────────────── 2. cron: has a quote appeared? ─────────────────── */
function rh_flow_sync_one(string $ref): string {
    $d = rh_find($ref);
    if (!$d) return 'gone';
    $stage = $d['stage'] ?? 'new';
    if (!rh_zoho_ready()) return 'zoho-not-configured';

    // a) quoting → look for an estimate carrying this reference
    if ($stage === 'quoting') {
        $est = rh_zoho_find_estimate($ref);
        if (!$est) return 'no-estimate-yet';

        $status = strtolower((string)($est['status'] ?? ''));
        rh_patch($ref, [
            'estimate_id'     => $est['estimate_id'] ?? '',
            'estimate_number' => $est['estimate_number'] ?? '',
            'quote_total'     => $est['total'] ?? 0,
        ], 'zoho', 'Found estimate ' . ($est['estimate_number'] ?? ''));

        // you saved it as a draft, so we send it and tell the client
        $emailed = $status === 'draft' ? rh_zoho_send_estimate((string)$est['estimate_id']) : true;

        $name = rh_first_name($d);
        rh_mail((string)$d['email'], (string)($d['name'] ?? ''),
            'Your quote · ' . $ref,
            rh_email_shell(
                "<p>Hi {$name},</p>
                 <p>Your quote is on its way in a separate message from our invoicing system,
                 where you can view and accept it online.</p>
                 <p>It covers what we would do first and what it costs. Your quote is
                 valid for one month from date of issue.</p>
                 <p>Let us know if anything is unclear in there, or if you need to discuss
                 any aspect. Otherwise, dropping in your commencement deposit books the work
                 into our schedule and we can get working.</p>",
                'Reference ' . $ref
            ),
            rh_config()['notify_to']);

        rh_advance($ref, 'quoted', 'zoho', $emailed
            ? 'Quote emailed to the client by Zoho'
            : 'Zoho would not email the quote. Send it by hand from Zoho.');
        return 'quoted';
    }

    // b) quoted → has the client accepted?
    if ($stage === 'quoted' && !empty($d['estimate_id'])) {
        $est = rh_zoho_find_estimate($ref);
        if (!$est) return 'estimate-missing';
        if (strtolower((string)($est['status'] ?? '')) !== 'accepted') return 'awaiting-acceptance';

        $pct = (int)rh_config()['deposit_percent'];
        $inv = rh_zoho_raise_deposit($est, $pct);
        if ($inv) {
            rh_patch($ref, [
                'deposit_invoice_id' => $inv['invoice_id'] ?? '',
                'deposit_total'      => $inv['total'] ?? 0,
            ], 'zoho', 'Quote accepted. ' . $pct . '% deposit invoice raised and sent');
            rh_advance($ref, 'deposit', 'zoho', 'Awaiting deposit');
            rh_notify_norman($d, 'Quote accepted', 'The deposit invoice has gone out automatically. The board will tick itself when it is paid.');
            return 'deposit-raised';
        }
        rh_advance($ref, 'deposit', 'zoho', 'Quote accepted, but the deposit invoice could not be raised. Raise it by hand.');
        return 'deposit-failed';
    }

    // c) deposit → has it been paid?
    if ($stage === 'deposit' && !empty($d['deposit_invoice_id'])) {
        $inv = rh_zoho_invoice((string)$d['deposit_invoice_id']);
        if ($inv && strtolower((string)($inv['status'] ?? '')) === 'paid') {
            rh_flow_deposit_received($d);
            return 'deposit-paid';
        }
        return 'awaiting-deposit';
    }

    return 'nothing-to-do';
}

/* ───────────────── 3. deposit in → client intake opens ───────────────── */
function rh_flow_deposit_received(array $d): void {
    $ref = (string)$d['ref'];
    rh_patch($ref, ['deposit_paid_at' => gmdate('c')], 'norman', 'Deposit received');
    $d = rh_find($ref) ?? $d;
    rh_flow_send_intake($d, false);
}

function rh_flow_send_intake(array $d, bool $resend): void {
    $ref   = (string)$d['ref'];
    $token = $d['intake_token'] ?? rh_token();
    if (empty($d['intake_token'])) rh_patch($ref, ['intake_token' => $token], 'system', '');

    $cfg  = rh_config();
    $link = rtrim($cfg['site_url'], '/') . '/intake/?t=' . urlencode($token);
    $name = rh_first_name($d);

    rh_mail((string)$d['email'], (string)($d['name'] ?? ''),
        ($resend ? 'Reminder: ' : '') . 'What we need to start · ' . $ref,
        rh_email_shell(
            "<p>Hi {$name},</p>
             <p>Thanks, the deposit is in and we are booked to start.</p>
             <p>There is one short form to fill in. It tells us where the site is hosted,
             who to speak to about access, and what content you still owe us. It takes a
             few minutes and it is the only thing standing between us and starting.</p>
             <p style='margin:22px 0'>
               <a href='{$link}' style='background:#ff0000;color:#fff;padding:13px 24px;
                  text-decoration:none;font-weight:700;display:inline-block'>Open the form</a>
             </p>
             <p style='color:#777;font-size:13px'>Please do not put passwords in the form.
             It asks who to contact for access instead, and we will arrange it properly.</p>",
            'Reference ' . $ref
        ),
        $cfg['notify_to']);

    rh_advance($ref, 'intake', 'system', $resend ? 'Intake form resent' : 'Intake form sent to the client');
}

/* ─────────────── 4. client submits intake → you are told ─────────────── */
function rh_flow_intake_received(array $d, array $answers): void {
    $ref = (string)$d['ref'];
    rh_patch($ref, ['intake' => $answers], 'client', 'Client completed the intake form');
    rh_advance($ref, 'ready', 'client', 'Everything is in. Ready to build.');
    rh_notify_norman(rh_find($ref) ?? $d, 'Intake complete',
        'The client has sent through their content and access details. Nothing is blocking the build.');
}

/* ───────────────────── 5. staging link to client ───────────────────── */
function rh_flow_staged(array $d, string $url): void {
    $ref  = (string)$d['ref'];
    $name = rh_first_name($d);
    rh_patch($ref, ['stage_url' => $url], 'norman', 'Staging site published');
    rh_mail((string)$d['email'], (string)($d['name'] ?? ''),
        'Your new site is ready to look at · ' . $ref,
        rh_email_shell(
            "<p>Hi {$name},</p>
             <p>Your new site is up on a staging address for you to go through.</p>
             <p style='margin:22px 0'>
               <a href='" . htmlspecialchars($url, ENT_QUOTES) . "' style='background:#ff0000;color:#fff;
                  padding:13px 24px;text-decoration:none;font-weight:700;display:inline-block'>View your site</a>
             </p>
             <p>Go through it at your own pace and send me everything you want changed in one
             reply rather than piece by piece. That is faster for both of us.</p>
             <p>It is not public and search engines cannot see it.</p>",
            'Reference ' . $ref
        ),
        rh_config()['notify_to']);
    rh_advance($ref, 'staged', 'norman', 'Client asked to review');
}

/* ─────────────────────────── 6. go live ─────────────────────────── */
function rh_flow_live(array $d): void {
    $ref  = (string)$d['ref'];
    $name = rh_first_name($d);
    rh_mail((string)$d['email'], (string)($d['name'] ?? ''),
        'You are live · ' . $ref,
        rh_email_shell(
            "<p>Hi {$name},</p>
             <p>Your new site is live.</p>
             <p>The final invoice follows separately. Everything we built sits in accounts you
             own, and you keep every file and every report.</p>
             <p>If anything needs changing, reply here. We do not disappear after launch.</p>",
            'Reference ' . $ref
        ),
        rh_config()['notify_to']);
    rh_advance($ref, 'live', 'norman', 'Site live. Raise the balance invoice in Zoho.');
    rh_notify_norman(rh_find($ref) ?? $d, 'Live', 'Last job: raise the balance invoice in Zoho.');
}

/* ───────────────────────── notifications ───────────────────────── */
function rh_notify_norman(array $d, string $headline, string $line): void {
    $cfg = rh_config();
    rh_mail($cfg['notify_to'], $cfg['notify_name'],
        '[' . $headline . '] ' . ($d['company'] ?? $d['ref']),
        rh_email_shell(
            "<p style='font:700 13px system-ui;letter-spacing:.1em;text-transform:uppercase;color:#ff0000;margin:0 0 6px'>"
            . htmlspecialchars($headline) . "</p>
             <h2 style='margin:0 0 10px;font-size:21px'>" . htmlspecialchars((string)($d['company'] ?? '')) . "</h2>
             <p>" . htmlspecialchars($line) . "</p>
             <p style='margin-top:20px'><a href='" . rtrim($cfg['site_url'], '/') . "/studio/#"
             . htmlspecialchars((string)$d['ref']) . "' style='background:#111;color:#fff;padding:11px 20px;
             text-decoration:none;font-weight:700'>Open the board</a></p>"
        ));
}
