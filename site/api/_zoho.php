<?php
/**
 * RedHot Design — Zoho Invoice helpers
 *
 * Deliberately read-mostly. Norman builds every quote by hand in Zoho and
 * keeps full control of the figures. This file only ever:
 *   - reads estimates to see when one appears, is sent, or is accepted
 *   - converts an ACCEPTED estimate into a deposit invoice
 *
 * The join key is the estimate's Reference# field, which carries the deal ref
 * (RH-XXXXXX). That one field is the entire integration contract.
 */

declare(strict_types=1);
require_once __DIR__ . '/_store.php';

function rh_zoho_ready(): bool {
    $c = rh_config();
    return $c['zoho_client_id'] !== '' && $c['zoho_client_secret'] !== ''
        && $c['zoho_refresh_token'] !== '' && $c['zoho_org_id'] !== '';
}

/** Access tokens last an hour. Cache on disk so we are not minting one per call. */
function rh_zoho_token(): ?string {
    $cfg = rh_config();
    if (!rh_zoho_ready()) return null;
    $cacheFile = rtrim($cfg['data_dir'], '/') . '/.zoho-token.json';

    if (is_readable($cacheFile)) {
        $c = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($c) && ($c['expires'] ?? 0) > time() + 120) return $c['token'];
    }

    $ch = curl_init($cfg['zoho_accounts'] . '/oauth/v2/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type'    => 'refresh_token',
            'client_id'     => $cfg['zoho_client_id'],
            'client_secret' => $cfg['zoho_client_secret'],
            'refresh_token' => $cfg['zoho_refresh_token'],
        ]),
    ]);
    $res = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    if (!is_array($res) || empty($res['access_token'])) return null;

    @file_put_contents($cacheFile, json_encode([
        'token'   => $res['access_token'],
        'expires' => time() + (int)($res['expires_in'] ?? 3600),
    ]), LOCK_EX);
    @chmod($cacheFile, 0600);
    return $res['access_token'];
}

function rh_zoho(string $method, string $path, array $body = null, array $query = []): ?array {
    $tok = rh_zoho_token();
    if ($tok === null) return null;
    $cfg = rh_config();
    $url = $cfg['zoho_api'] . $path . ($query ? '?' . http_build_query($query) : '');
    $ch = curl_init($url);
    $headers = [
        'Authorization: Zoho-oauthtoken ' . $tok,
        'X-com-zoho-invoice-organizationid: ' . $cfg['zoho_org_id'],
        'Accept: application/json',
    ];
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25,
             CURLOPT_CUSTOMREQUEST => strtoupper($method)];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES);
        $headers[] = 'Content-Type: application/json';
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $out = json_decode((string)$raw, true);
    if (!is_array($out)) return null;
    $out['_http'] = $code;
    return $out;
}

/** Find the estimate whose Reference# is this deal ref. */
function rh_zoho_find_estimate(string $ref): ?array {
    $r = rh_zoho('GET', '/estimates', null, ['reference_number' => $ref, 'per_page' => 10]);
    if (!$r || empty($r['estimates'])) return null;
    foreach ($r['estimates'] as $e) {
        if (strcasecmp((string)($e['reference_number'] ?? ''), $ref) === 0) return $e;
    }
    return null;
}

/** Mark a draft estimate as sent (Zoho emails it using your own template). */
function rh_zoho_send_estimate(string $estimateId): bool {
    // /status/sent ONLY flips the status flag. It does not email anything.
    // /email is what actually posts the estimate to the customer, using Zoho's
    // own template and the contact person on the estimate.
    $id = rawurlencode($estimateId);
    $r  = rh_zoho('POST', '/estimates/' . $id . '/email');
    $ok = $r !== null && ($r['_http'] ?? 0) >= 200 && ($r['_http'] ?? 0) < 300;
    if (!$ok) {
        // could not email it, so at least do not leave it sitting in draft
        rh_zoho('POST', '/estimates/' . $id . '/status/sent');
    }
    return $ok;
}

/** Raise a deposit invoice for a percentage of an accepted estimate. */
function rh_zoho_raise_deposit(array $estimate, int $percent): ?array {
    $cust = $estimate['customer_id'] ?? null;
    $total = (float)($estimate['total'] ?? 0);
    if (!$cust || $total <= 0) return null;

    $amount = round($total * $percent / 100, 2);
    $body = [
        'customer_id'      => $cust,
        'reference_number' => (string)($estimate['reference_number'] ?? ''),
        'date'             => date('Y-m-d'),
        'line_items'       => [[
            'name'        => $percent . '% deposit',
            'description' => 'Deposit against estimate ' . ($estimate['estimate_number'] ?? ''),
            'rate'        => $amount,
            'quantity'    => 1,
        ]],
        'notes' => 'Deposit to begin work. The balance is invoiced on completion.',
    ];
    $r = rh_zoho('POST', '/invoices', $body);
    if (!$r || empty($r['invoice'])) return null;

    // send it straight away so the client has the payment link
    $id = $r['invoice']['invoice_id'] ?? null;
    if ($id) rh_zoho('POST', '/invoices/' . rawurlencode((string)$id) . '/status/sent');
    return $r['invoice'];
}

function rh_zoho_invoice(string $invoiceId): ?array {
    $r = rh_zoho('GET', '/invoices/' . rawurlencode($invoiceId));
    return $r['invoice'] ?? null;
}
