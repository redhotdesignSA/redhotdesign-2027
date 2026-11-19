<?php
/**
 * RedHot Design — Studio. The workflow board.
 * One screen. Every deal, what stage it is at, and the one action that moves it.
 */
declare(strict_types=1);
require_once __DIR__ . '/../api/_store.php';
require_once __DIR__ . '/_auth.php';
rh_require_login();

$cfg   = rh_config();
$deals = rh_deals();
usort($deals, fn($a, $b) => strcmp($b['updated'] ?? $b['ts'] ?? '', $a['updated'] ?? $a['ts'] ?? ''));

$filter = $_GET['f'] ?? 'open';
$view = array_values(array_filter($deals, function ($d) use ($filter) {
    $s = $d['stage'] ?? 'new';
    return match ($filter) {
        'you'  => (RH_STAGES[$s]['hold'] ?? false) === true,
        'open' => !in_array($s, ['live', 'lost'], true),
        'done' => in_array($s, ['live', 'lost'], true),
        default => true,
    };
}));

$needYou = count(array_filter($deals, fn($d) => (RH_STAGES[$d['stage'] ?? 'new']['hold'] ?? false) === true
                                              && !in_array($d['stage'] ?? '', ['live','lost'], true)));
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
$money = fn($n) => 'R' . number_format((float)$n, 0, '.', ' ');
?><!DOCTYPE html>
<html lang="en-ZA"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Studio · RedHot Design</title>
<link rel="icon" href="/assets/mark.svg" type="image/svg+xml">
<link rel="stylesheet" href="/studio/studio.css">
</head><body>

<header class="top">
  <div class="in">
    <strong>Studio</strong>
    <nav>
      <a href="?f=you"  class="<?= $filter==='you'?'on':'' ?>">Needs you <?= $needYou ? '<i>'.$needYou.'</i>' : '' ?></a>
      <a href="?f=open" class="<?= $filter==='open'?'on':'' ?>">Open</a>
      <a href="?f=done" class="<?= $filter==='done'?'on':'' ?>">Closed</a>
      <a href="?f=all"  class="<?= $filter==='all'?'on':'' ?>">All</a>
    </nav>
    <a class="out" href="/studio/?logout=1">Sign out</a>
  </div>
</header>

<main class="wrap">

<?php if (!$view): ?>
  <p class="empty">Nothing here yet. Enquiries from the website land on this board automatically.</p>
<?php endif; ?>

<?php foreach ($view as $d):
  $s    = $d['stage'] ?? 'new';
  $meta = RH_STAGES[$s] ?? RH_STAGES['new'];
  $hold = ($meta['hold'] ?? false) && !in_array($s, ['live','lost'], true);
?>
<article class="deal <?= $hold ? 'hold' : '' ?>" id="<?= $e($d['ref']) ?>">
  <div class="hd">
    <div>
      <h2><?= $e($d['company'] ?? '—') ?></h2>
      <p class="sub"><?= $e($d['name'] ?? '') ?> ·
        <a href="mailto:<?= $e($d['email'] ?? '') ?>"><?= $e($d['email'] ?? '') ?></a>
        <?= !empty($d['phone']) ? ' · ' . $e($d['phone']) : '' ?>
        <?php if (!empty($d['site'])): ?> · <a href="<?= $e($d['site']) ?>" target="_blank" rel="noopener">site ↗</a><?php endif; ?>
      </p>
    </div>
    <div class="tags">
      <span class="band b-<?= $e($d['band'] ?? 'low') ?>"><?= $e(strtoupper($d['band'] ?? 'low')) ?></span>
      <span class="stage"><?= $e($meta['label']) ?></span>
      <code class="ref"><?= $e($d['ref']) ?></code>
    </div>
  </div>

  <dl class="facts">
    <?php foreach ([
      'Track'   => ['spend'=>'Ad spend','rebuild'=>'Site rebuild','both'=>'Both','other'=>'Other'][$d['track'] ?? ''] ?? ($d['track'] ?? ''),
      'Spend'   => $d['spend'] ?? '',
      'Runs it' => $d['agency'] ?? '',
      'Urgency' => $d['urgency'] ?? '',
    ] as $k => $v): if ($v === '') continue; ?>
      <div><dt><?= $e($k) ?></dt><dd><?= $e($v) ?></dd></div>
    <?php endforeach; ?>
    <?php if (!empty($d['quote_total'])): ?>
      <div><dt>Quote</dt><dd><?= $money($d['quote_total']) ?><?= !empty($d['estimate_number']) ? ' · '.$e($d['estimate_number']) : '' ?></dd></div>
    <?php endif; ?>
    <?php if (!empty($d['deposit_total'])): ?>
      <div><dt>Deposit</dt><dd><?= $money($d['deposit_total']) ?></dd></div>
    <?php endif; ?>
  </dl>

  <?php if (!empty($d['notes'])): ?><p class="note"><?= nl2br($e($d['notes'])) ?></p><?php endif; ?>

  <?php if (!empty($d['intake'])): ?>
    <details class="intake"><summary>Client intake received</summary>
      <dl class="facts open">
        <?php foreach ($d['intake'] as $k => $v): if ($v === '' || $k === 'ts') continue; ?>
          <div><dt><?= $e(ucfirst(str_replace('_',' ',$k))) ?></dt><dd><?= nl2br($e($v)) ?></dd></div>
        <?php endforeach; ?>
      </dl>
    </details>
  <?php endif; ?>

  <!-- the single next action -->
  <div class="act">
    <p class="you"><?= $e($meta['you']) ?></p>
    <form method="post" action="/studio/action.php" class="row">
      <input type="hidden" name="csrf" value="<?= $e(rh_csrf()) ?>">
      <input type="hidden" name="ref" value="<?= $e($d['ref']) ?>">

      <?php if ($s === 'new'): ?>
        <button name="do" value="acknowledge" class="go">Acknowledge &amp; start quote</button>

      <?php elseif ($s === 'quoting'): ?>
        <a class="ghost" href="https://invoice.zoho.com/app#/estimates/new" target="_blank" rel="noopener">Open Zoho ↗</a>
        <span class="hint">Put <code><?= $e($d['ref']) ?></code> in the estimate&rsquo;s Reference# field. We detect it from there.</span>
        <button name="do" value="checkzoho" class="go">Check Zoho now</button>

      <?php elseif ($s === 'deposit'): ?>
        <button name="do" value="deposit_in" class="go">Deposit received</button>

      <?php elseif ($s === 'ready'): ?>
        <button name="do" value="start_build" class="go">Start the build</button>

      <?php elseif ($s === 'building'): ?>
        <input type="url" name="stage_url" placeholder="https://staging.example.co.za" required>
        <button name="do" value="staged" class="go">Send staging link</button>

      <?php elseif ($s === 'approved'): ?>
        <button name="do" value="live" class="go">Mark live</button>

      <?php elseif ($s === 'intake'): ?>
        <span class="hint">Intake link sent. <a href="<?= $e($cfg['site_url']) ?>/intake/?t=<?= $e($d['intake_token'] ?? '') ?>" target="_blank" rel="noopener">Open it ↗</a></span>
        <button name="do" value="nudge_intake" class="ghost">Resend</button>
        <button name="do" value="force_ready" class="go">Got everything, skip ahead</button>

      <?php elseif ($s === 'quoted'): ?>
        <span class="hint">Quote sent. Waiting on the client.</span>
        <button name="do" value="checkzoho" class="ghost">Check Zoho now</button>

      <?php elseif ($s === 'staged'): ?>
        <span class="hint"><a href="<?= $e($d['stage_url'] ?? '#') ?>" target="_blank" rel="noopener">View staging ↗</a></span>
        <button name="do" value="approved" class="go">Client approved</button>

      <?php else: ?>
        <span class="hint">No action needed.</span>
      <?php endif; ?>

      <?php if (!in_array($s, ['live','lost'], true)): ?>
        <button name="do" value="lost" class="quiet" formnovalidate
                onclick="return confirm('Close this one out?')">Close out</button>
      <?php endif; ?>
    </form>
  </div>

  <details class="hist"><summary><?= count($d['log'] ?? []) ?> events</summary>
    <ol>
      <?php foreach (array_reverse($d['log'] ?? []) as $l): ?>
        <li><time><?= $e(substr((string)$l['t'], 0, 16)) ?></time>
            <b><?= $e($l['who']) ?></b> <?= $e($l['what']) ?></li>
      <?php endforeach; ?>
    </ol>
  </details>
</article>
<?php endforeach; ?>

</main>
</body></html>
