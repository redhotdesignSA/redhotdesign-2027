<?php
declare(strict_types=1);
require_once __DIR__ . '/_auth.php';
rh_session();
$cfg = rh_config();
$err = '';

// crude but effective lockout: 5 tries per 10 minutes per session
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['tries'] = ($_SESSION['tries'] ?? 0) + 1;
    $_SESSION['first'] = $_SESSION['first'] ?? time();
    if (time() - $_SESSION['first'] > 600) { $_SESSION['tries'] = 1; $_SESSION['first'] = time(); }

    if ($_SESSION['tries'] > 5) {
        $err = 'Too many attempts. Wait ten minutes.';
    } elseif ($cfg['studio_pass_hash'] === '') {
        $err = 'No passphrase is set in redhot-form-config.php yet.';
    } elseif (password_verify((string)($_POST['pass'] ?? ''), $cfg['studio_pass_hash'])) {
        session_regenerate_id(true);
        $_SESSION['ok'] = true; unset($_SESSION['tries'], $_SESSION['first']);
        header('Location: /studio/'); exit;
    } else {
        $err = 'Not that one.';
        usleep(600000);
    }
}
?><!DOCTYPE html><html lang="en-ZA"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>Studio</title><link rel="icon" href="/assets/mark.svg" type="image/svg+xml">
<link rel="stylesheet" href="/studio/studio.css"></head>
<body class="mid"><form method="post" class="card">
<h1>Studio</h1>
<?php if ($err): ?><p class="err"><?= htmlspecialchars($err) ?></p><?php endif; ?>
<input type="password" name="pass" placeholder="Passphrase" autofocus autocomplete="current-password" required>
<button class="go">Open</button>
</form></body></html>
