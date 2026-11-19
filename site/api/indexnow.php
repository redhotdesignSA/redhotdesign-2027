<?php
declare(strict_types=1);
/**
 * IndexNow submitter for redhotdesign.co.za
 *
 * Pings the IndexNow API when a page's HTML has actually changed, which is what
 * the protocol asks for. Submitting unchanged URLs over and over is the one
 * thing every implementation guide warns against.
 *
 * One submission reaches every participating engine (Bing, Yandex, Naver,
 * Seznam, Yep, Amazon). Google does not take part.
 *
 * Run:   /api/indexnow.php?key=CRON_KEY          only pages whose HTML changed
 *        /api/indexnow.php?key=CRON_KEY&force=1  every public page
 *        /api/indexnow.php?key=CRON_KEY&dry=1    show what would be sent
 *        php /home/redhotde/public_html/2027/api/indexnow.php   (CLI, no key needed)
 */

require_once __DIR__ . '/_store.php';

header('Content-Type: text/plain; charset=utf-8');

$cli = (PHP_SAPI === 'cli');
$cfg = rh_config();

if (!$cli) {
    $given = (string)($_GET['key'] ?? '');
    $want  = (string)($cfg['cron_key'] ?? '');
    if ($want === '' || !hash_equals($want, $given)) {
        http_response_code(403);
        echo "no\n";
        exit;
    }
}

$args  = $cli ? (array)($_SERVER['argv'] ?? []) : [];
$force = $cli ? in_array('--force', $args, true) : isset($_GET['force']);
$dry   = $cli ? in_array('--dry', $args, true)   : isset($_GET['dry']);

$ROOT = dirname(__DIR__);
$BASE = rtrim((string)($cfg['site_url'] ?? 'https://redhotdesign.co.za'), '/');
$HOST = parse_url($BASE, PHP_URL_HOST);

// ---- find the key file, so the key lives in exactly one place ----
$key = '';
$keyFile = '';
foreach (glob($ROOT . '/*.txt') ?: [] as $f) {
    $name = basename($f, '.txt');
    if (!preg_match('~^[a-zA-Z0-9-]{8,128}$~', $name)) continue;
    if (trim((string)file_get_contents($f)) !== $name) continue;
    $key = $name;
    $keyFile = $BASE . '/' . basename($f);
    break;
}
if ($key === '') {
    echo "No IndexNow key file found in the site root.\n";
    echo "Expected a file named <key>.txt whose contents are that same key.\n";
    exit(1);
}

// ---- the public pages, discovered rather than hardcoded ----
$skip = ['api', 'assets', 'studio', 'intake'];
$pages = [];
if (is_file($ROOT . '/index.html')) $pages[''] = $ROOT . '/index.html';
foreach (glob($ROOT . '/*', GLOB_ONLYDIR) ?: [] as $d) {
    $name = basename($d);
    if (in_array($name, $skip, true)) continue;
    if (is_file($d . '/index.html')) $pages[$name . '/'] = $d . '/index.html';
}
if (!$pages) { echo "No public pages found.\n"; exit(1); }

// ---- compare against what we sent last time ----
$stateFile = rtrim((string)$cfg['data_dir'], '/') . '/.indexnow.json';
$state = [];
if (is_readable($stateFile)) {
    $decoded = json_decode((string)file_get_contents($stateFile), true);
    if (is_array($decoded)) $state = $decoded;
}

$send = [];
$now  = [];
foreach ($pages as $path => $file) {
    $url = $BASE . '/' . $path;
    $sha = sha1_file($file);
    $now[$url] = $sha;
    if ($force || !isset($state[$url]) || $state[$url] !== $sha) $send[] = $url;
}

echo "key        ", substr($key, 0, 6), "... (", $keyFile, ")\n";
echo "pages      ", count($pages), "\n";
echo "changed    ", count($send), ($force ? " (forced)" : ""), "\n";

if (!$send) { echo "\nNothing changed, nothing submitted.\n"; exit(0); }
foreach ($send as $u) echo "  -> ", $u, "\n";

if ($dry) { echo "\nDry run, nothing sent.\n"; exit(0); }

// ---- submit ----
$payload = json_encode([
    'host'        => $HOST,
    'key'         => $key,
    'keyLocation' => $keyFile,
    'urlList'     => array_values($send),
], JSON_UNESCAPED_SLASHES);

$ch = curl_init('https://api.indexnow.org/indexnow');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=utf-8'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
]);
$body = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

$meaning = [
    200 => 'OK, URLs received',
    202 => 'Accepted, key validation pending',
    400 => 'Bad request',
    403 => 'Forbidden, the key did not validate',
    422 => 'Unprocessable, URLs do not match the host or the key',
    429 => 'Too many requests',
];

echo "\nHTTP ", ($code ?: 'no response'), " ", ($meaning[$code] ?? ''), "\n";
if ($err !== '') echo "curl: ", $err, "\n";
if (trim((string)$body) !== '') echo "body: ", substr(trim((string)$body), 0, 300), "\n";

// only remember what was actually accepted
if ($code === 200 || $code === 202) {
    foreach ($send as $u) $state[$u] = $now[$u];
    @file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    @chmod($stateFile, 0600);
    echo "State updated, these URLs will not be resubmitted until they change.\n";
} else {
    echo "State NOT updated, so the next run will try again.\n";
}
