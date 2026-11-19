<?php
/**
 * Client intake. Reached by a tokenised link, no login.
 * Deliberately asks for NO passwords. We ask who to contact for access
 * and arrange it properly, because a plaintext FTP password sitting in a
 * JSON file is a breach waiting to happen.
 */
declare(strict_types=1);
require_once __DIR__ . '/../api/_store.php';
require_once __DIR__ . '/../api/_flow.php';

$token = trim((string)($_GET['t'] ?? ''));
$deal = null;
foreach (rh_deals() as $d) {
    if (!empty($d['intake_token']) && hash_equals((string)$d['intake_token'], $token)) { $deal = $d; break; }
}

$done = false; $err = '';
if ($deal && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (trim((string)($_POST['company_url'] ?? '')) !== '') { $done = true; }   // honeypot
    else {
        $keep = ['host','control_panel','access_contact','domain_registrar','analytics',
                 'content_outstanding','brand_assets','key_pages','must_keep','deadline','anything_else'];
        $answers = ['ts' => gmdate('c')];
        foreach ($keep as $k) {
            $v = trim((string)($_POST[$k] ?? ''));
            if ($v !== '') $answers[$k] = mb_substr(preg_replace('/\r\n?/', "\n", $v), 0, 4000);
        }
        if (count($answers) < 2) { $err = 'Please fill in at least a few of these.'; }
        else { rh_flow_intake_received($deal, $answers); $done = true; }
    }
}
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
$fields = [
  ['host','Where is the site hosted now?','Company name is enough, e.g. Afrihost, xneelo, or "not sure"','text'],
  ['control_panel','Control panel or hosting login URL','If you know it. No password please.','text'],
  ['access_contact','Who do we ask for access?','Name and email of whoever manages your hosting or IT','text'],
  ['domain_registrar','Who holds the domain?','The company you renew it with','text'],
  ['analytics','Existing analytics or ad accounts','Google Analytics, Google Ads, Meta. Just say which exist.','text'],
  ['key_pages','Which pages matter most commercially?','The ones that actually bring enquiries or sales','area'],
  ['must_keep','Anything that must not change','Accreditations, legal wording, product names, a page people bookmark','area'],
  ['content_outstanding','Content you still owe us','New copy, product sheets, team photos, price lists','area'],
  ['brand_assets','Where are your logo and brand files?','A link is perfect, or say if we should recreate them','area'],
  ['deadline','Any date you are working to?','An event, a campaign, a trade show','text'],
  ['anything_else','Anything else we should know','','area'],
];
?><!DOCTYPE html><html lang="en-ZA"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>Getting started · RedHot Design</title><link rel="icon" href="/assets/mark.svg" type="image/svg+xml">
<link rel="preload" href="/assets/fonts/archivo-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/assets/site.css?v=f1c4332ddd"><link rel="stylesheet" href="/studio/studio.css?v=c6501578c6"></head>
<body class="dark"><main class="sec dark" style="padding-block:clamp(4rem,10vw,7rem)"><div class="wrap narrow">
<?php if (!$deal): ?>
  <p class="label">Link not recognised</p>
  <h2 style="margin-block-start:1.4rem">That link has expired <em>or was mistyped.</em></h2>
  <p style="margin-block-start:1.4rem;color:var(--mu)">Email <a href="mailto:hello@redhotdesign.co.za" style="color:#fff">hello@redhotdesign.co.za</a> and we will send a fresh one.</p>
<?php elseif ($done): ?>
  <p class="label">Received</p>
  <h2 style="margin-block-start:1.4rem">Thanks, <?= $e(rh_first_name($deal)) ?>. <em>That is everything.</em></h2>
  <p style="margin-block-start:1.4rem;color:var(--mu)">Nothing is blocking us now. We will be in touch as soon as there is something to look at.</p>
<?php else: ?>
  <p class="label">Reference <?= $e($deal['ref']) ?></p>
  <h2 style="margin-block-start:1.4rem">A few things <em>and then we start.</em></h2>
  <p style="margin-block-start:1.4rem;color:var(--mu);max-inline-size:60ch">Answer what you can. Anything you are unsure of, leave blank and we will work it out together. <strong style="color:#fff">Please do not enter passwords anywhere on this form.</strong> We ask who to contact for access and arrange it properly.</p>
  <?php if ($err): ?><p class="err" style="margin-block-start:1.5rem"><?= $e($err) ?></p><?php endif; ?>
  <form method="post" class="intake-form">
    <?php foreach ($fields as [$k,$label,$hint,$type]): ?>
      <label><span><?= $e($label) ?></span>
        <?php if ($hint): ?><small><?= $e($hint) ?></small><?php endif; ?>
        <?php if ($type==='area'): ?><textarea name="<?= $e($k) ?>" rows="3"></textarea>
        <?php else: ?><input type="text" name="<?= $e($k) ?>"><?php endif; ?>
      </label>
    <?php endforeach; ?>
    <input type="text" name="company_url" tabindex="-1" autocomplete="off" aria-hidden="true"
           style="position:absolute;left:-9999px">
    <button class="btn btn-pri" type="submit"><span>Send it through</span><span class="arw">&rarr;</span></button>
  </form>
<?php endif; ?>
</div></main></body></html>
