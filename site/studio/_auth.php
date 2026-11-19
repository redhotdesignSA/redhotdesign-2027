<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/_store.php';

function rh_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_set_cookie_params(['lifetime'=>0,'path'=>'/studio','httponly'=>true,'samesite'=>'Lax',
        'secure'=>(($_SERVER['HTTPS'] ?? '') !== '' || ($_SERVER['SERVER_PORT'] ?? '') === '443')]);
    session_name('rhstudio');
    session_start();
}
function rh_csrf(): string {
    rh_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function rh_csrf_ok(?string $t): bool {
    rh_session();
    return is_string($t) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}
function rh_require_login(): void {
    rh_session();
    if (isset($_GET['logout'])) { $_SESSION = []; session_destroy(); header('Location: /studio/'); exit; }
    if (!empty($_SESSION['ok'])) return;
    header('Location: /studio/login.php'); exit;
}
