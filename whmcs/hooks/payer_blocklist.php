<?php
/**
 * WHMCS Hook: payer blocklist — stop a documented chargeback fraudster from being
 * served again under a new account.
 *
 * WHY THIS EXISTS
 * ---------------
 * A serial chargeback abuser signs up again with a new throwaway email. The email,
 * the account and often the IP all change. What does not change is the payment
 * identity: the PayPal account (its payer ID) and the legal name PayPal verified
 * for it (KYC). A PayPal dispute reports both, so the operator side can collect
 * them from each confirmed fraud case into a private list. This hook checks new
 * orders against that list.
 *
 * FALSE POSITIVES ARE THE MAIN RISK
 * ---------------------------------
 * Common names collide: one shop already had 13 unrelated customers sharing the
 * surname of a single fraudster. So a NAME match on its own never blocks
 * anything. It is only logged ("flag") for a human to review. A block needs the
 * name AND a second identifier from the same list entry (the payer ID or the
 * email). Forcing extra friction on legitimate buyers costs sales, so a flag is
 * invisible to the customer.
 *
 * WHAT THIS HOOK DOES
 * -------------------
 *   ShoppingCartValidateCheckout (before the order exists):
 *     name + email match an entry -> order refused with a generic message
 *     name only                   -> flag (logged), order proceeds
 *   PreModuleCreate (after payment, before provisioning):
 *     the paying PayPal account's name + payer ID (or payer email) match an entry
 *       -> provisioning aborted (abortcmd), an internal client note and an
 *          Activity Log line are written so staff can return the payment and
 *          close the account
 *     name only -> flag (logged), provisioning proceeds
 *
 * THE LIST FILE
 * -------------
 * JSON, readable by the web user, kept OUTSIDE the web root:
 *   {"v":1,"salt":"<hex>","entries":[{"id":"E1","n":["<hash>",...],"e":["<hash>"],"p":["<hash>"]}]}
 * It holds salted SHA-256 hashes only, never a raw name, email or payer ID, so a
 * copy of the file does not reveal who is on it without the salt and a guess.
 * Name keys are the unordered PAIRS of a normalized name's tokens, so the PayPal
 * legal name "Ana Maria Lopez Diaz" still matches a checkout name "Ana Lopez".
 * The list is built by the operator's own tooling; this file only reads it.
 *
 * FAILURE MODE
 * ------------
 * Missing file, bad JSON, any exception: ALLOW. A bug in fraud detection must never
 * block a legitimate paying customer.
 *
 * Deploy to: <whmcs_root>/includes/hooks/payer_blocklist.php
 * Configure: define PM_PAYER_BLOCKLIST_FILE / PM_PAYER_BLOCKLIST_LOG_DIR before load,
 * or edit the defaults below.
 *
 * Made for pulsedmedia.com
 *
 * SPDX-License-Identifier: Apache-2.0
 *
 * @copyright 2026 Magna Capax Finland Oy
 * @license   Apache-2.0
 */

declare(strict_types=1);

// Default location: a "pm-private" directory two levels above the WHMCS root, i.e. outside a
// typical web root. Define the constants before this file loads to put them elsewhere.
$pmPblPrivate = (defined('ROOTDIR') ? dirname(ROOTDIR, 2) : sys_get_temp_dir()) . '/pm-private';
if (!defined('PM_PAYER_BLOCKLIST_FILE')) {
    define('PM_PAYER_BLOCKLIST_FILE', $pmPblPrivate . '/payer-blocklist.json');
}
if (!defined('PM_PAYER_BLOCKLIST_LOG_DIR')) {
    define('PM_PAYER_BLOCKLIST_LOG_DIR', $pmPblPrivate . '/payer-blocklist-log');
}
unset($pmPblPrivate);
if (!defined('PM_PAYER_BLOCKLIST_BLOCK_MESSAGE')) {
    define('PM_PAYER_BLOCKLIST_BLOCK_MESSAGE',
        'We are unable to process this order. Please contact support if you believe this is an error.');
}

// ---------------------------------------------------------------------------
// Pure functions (no WHMCS dependency; the operator tooling reuses them)
// ---------------------------------------------------------------------------

/**
 * Lowercase, strip accents, keep letters only; return up to 8 unique tokens of 2+ letters.
 * The name comes from the checkout form, so input and token count are capped: pair keys grow
 * with the square of the token count (an unbounded 18 KB name made 1.7M hashes).
 */
function pm_pbl_name_tokens(string $name): array
{
    $s = mb_substr($name, 0, 256, 'UTF-8');
    if (class_exists('Normalizer')) {
        $n = \Normalizer::normalize($s, \Normalizer::FORM_D);
        if (is_string($n)) {
            $s = preg_replace('/\p{Mn}+/u', '', $n) ?? $s;
        }
    }
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/[^\p{L}]+/u', ' ', $s) ?? '';
    $out = [];
    foreach (preg_split('/\s+/', trim($s)) ?: [] as $t) {
        if (mb_strlen($t, 'UTF-8') >= 2) {
            $out[$t] = true;
        }
    }
    return array_slice(array_keys($out), 0, 8);
}

/** Salted hashes of every unordered token pair. A one-token name yields no keys (too common to match on). */
function pm_pbl_name_keys(string $name, string $salt): array
{
    $tokens = pm_pbl_name_tokens($name);
    sort($tokens, SORT_STRING);
    $keys = [];
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        for ($j = $i + 1; $j < $count; $j++) {
            $keys[] = hash('sha256', $salt . '|n|' . $tokens[$i] . '|' . $tokens[$j]);
        }
    }
    return $keys;
}

function pm_pbl_email_key(string $email, string $salt): string
{
    $e = strtolower(trim($email));
    return $e === '' ? '' : hash('sha256', $salt . '|e|' . $e);
}

function pm_pbl_payer_key(string $payerId, string $salt): string
{
    $p = strtoupper(trim($payerId));
    return $p === '' ? '' : hash('sha256', $salt . '|p|' . $p);
}

/** Load and validate the list; null when absent or malformed (callers then ALLOW). */
function pm_pbl_load(string $file = PM_PAYER_BLOCKLIST_FILE): ?array
{
    if (!is_readable($file)) {
        return null;
    }
    $data = json_decode((string) @file_get_contents($file), true);
    if (!is_array($data) || ($data['v'] ?? null) !== 1 || !is_string($data['salt'] ?? null)
        || strlen($data['salt']) < 16 || !is_array($data['entries'] ?? null)) {
        return null;
    }
    return $data;
}

/**
 * Compare identifiers against the list. Returns the strongest hit:
 *   ['decision' => 'block'|'flag'|'none', 'entry' => id|null, 'name' => bool, 'email' => bool, 'payer' => bool]
 * block = name AND (payer OR email) on the SAME entry; flag = any single identifier.
 */
function pm_pbl_match(array $list, string $name, string $email, string $payerId): array
{
    $salt = $list['salt'];
    $nameKeys = pm_pbl_name_keys($name, $salt);
    $emailKey = pm_pbl_email_key($email, $salt);
    $payerKey = pm_pbl_payer_key($payerId, $salt);
    $best = ['decision' => 'none', 'entry' => null, 'name' => false, 'email' => false, 'payer' => false];
    foreach ($list['entries'] as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $hitName = (bool) array_intersect($nameKeys, (array) ($entry['n'] ?? []));
        $hitEmail = $emailKey !== '' && in_array($emailKey, (array) ($entry['e'] ?? []), true);
        $hitPayer = $payerKey !== '' && in_array($payerKey, (array) ($entry['p'] ?? []), true);
        if (!$hitName && !$hitEmail && !$hitPayer) {
            continue;
        }
        $decision = ($hitName && ($hitEmail || $hitPayer)) ? 'block' : 'flag';
        if ($best['decision'] !== 'block') {
            $best = ['decision' => $decision, 'entry' => (string) ($entry['id'] ?? '?'),
                'name' => $hitName, 'email' => $hitEmail, 'payer' => $hitPayer];
        }
    }
    return $best;
}

/** Append one audit row; never throws. Rows carry entry ids and flags only, no identifiers. */
function pm_pbl_log(string $event, array $row): void
{
    $dir = PM_PAYER_BLOCKLIST_LOG_DIR;
    if ($dir === '') {
        return;
    }
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $file = $dir . '/' . gmdate('Ymd') . '.jsonl';
    // A listed name can be typed at checkout by anyone, so cap the day's file.
    if (@filesize($file) > 10 * 1024 * 1024) {
        return;
    }
    $line = json_encode(['ts' => gmdate('c'), 'event' => $event] + $row, JSON_UNESCAPED_SLASHES);
    if ($line !== false) {
        @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    }
}

// ---------------------------------------------------------------------------
// WHMCS-side lookups
// ---------------------------------------------------------------------------

/** Parse the "key => value" lines WHMCS stores for a PayPal IPN in tblgatewaylog.data. */
function pm_pbl_parse_ipn(string $data): array
{
    $out = [];
    foreach (explode("\n", $data) as $line) {
        $pos = strpos($line, ' => ');
        if ($pos !== false) {
            $out[substr($line, 0, $pos)] = trim(substr($line, $pos + 4));
        }
    }
    return $out;
}

/** Read-only authenticated GET against the PayPal REST API, using the paypalcheckout gateway's own credentials. */
function pm_pbl_paypal_get(string $path): ?array
{
    // All calls in one request share a 6-second budget: this runs inside payment processing.
    static $token = null, $base = null, $deadline = null;
    if ($deadline === null) {
        $deadline = microtime(true) + 6.0;
    }
    $left = (int) floor($deadline - microtime(true));
    if ($left < 1) {
        return null;
    }
    if ($token === null) {
        if (!function_exists('getGatewayVariables')) {
            require_once ROOTDIR . '/includes/gatewayfunctions.php';
        }
        $gw = getGatewayVariables('paypalcheckout');
        $sandbox = !empty($gw['sandbox']);
        $id = (string) ($sandbox ? ($gw['sandboxClientId'] ?? '') : ($gw['clientId'] ?? ''));
        $secret = (string) ($sandbox ? ($gw['sandboxClientSecret'] ?? '') : ($gw['clientSecret'] ?? ''));
        $token = '';
        if ($id === '' || $secret === '') {
            return null;
        }
        $base = $sandbox ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
        $ch = curl_init($base . '/v1/oauth2/token');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
            CURLOPT_USERPWD => $id . ':' . $secret, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(3, $left), CURLOPT_TIMEOUT => $left]);
        $j = json_decode((string) curl_exec($ch), true);
        curl_close($ch);
        $token = is_array($j) ? (string) ($j['access_token'] ?? '') : '';
    }
    $left = (int) floor($deadline - microtime(true));
    if ($token === '' || $left < 1 || strpos($path, '/') !== 0) {
        return null;
    }
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => min(3, $left), CURLOPT_TIMEOUT => $left]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode((string) $body, true);
    return ($code === 200 && is_array($j)) ? $j : null;
}

/**
 * Identity of the PayPal account that paid for a service, or null when unknown.
 * Legacy PayPal (IPN) logs the payer before the payment is applied; paypalcheckout keeps no payer
 * data, so for a subscription-backed service the subscriber is read from PayPal.
 */
function pm_pbl_payer_for_service(int $serviceId): ?array
{
    $db = '\WHMCS\Database\Capsule';
    $svc = $db::table('tblhosting')->where('id', $serviceId)->first(['subscriptionid']);
    $inv = $db::table('tblinvoiceitems')
        ->join('tblinvoices', 'tblinvoices.id', '=', 'tblinvoiceitems.invoiceid')
        ->where('tblinvoiceitems.type', 'Hosting')->where('tblinvoiceitems.relid', $serviceId)
        ->where('tblinvoices.status', 'Paid')->orderBy('tblinvoices.datepaid', 'desc')
        ->first(['tblinvoices.id', 'tblinvoices.paymentmethod']);
    $transid = $inv ? (string) $db::table('tblaccounts')->where('invoiceid', $inv->id)->orderBy('id', 'desc')->value('transid') : '';
    $method = $inv ? (string) $inv->paymentmethod : '';
    $sub = $svc ? (string) $svc->subscriptionid : '';

    if ($method === 'paypal' && preg_match('/^[A-Z0-9]{8,24}$/', $transid)) {
        // Newest-first finds a fresh payment at once; the id floor bounds the scan when nothing matches.
        $floor = (int) $db::table('tblgatewaylog')->max('id') - 50000;
        $data = (string) $db::table('tblgatewaylog')->where('id', '>', $floor)->where('result', 'Successful')
            ->where('data', 'like', '%txn_id => ' . $transid . "\n%")->orderBy('id', 'desc')->value('data');
        $ipn = pm_pbl_parse_ipn($data);
        if (($ipn['txn_id'] ?? '') !== $transid) {
            return null;
        }
        return ['source' => 'ipn', 'payer_id' => (string) ($ipn['payer_id'] ?? ''), 'email' => (string) ($ipn['payer_email'] ?? ''),
            'name' => trim(($ipn['first_name'] ?? '') . ' ' . ($ipn['last_name'] ?? ''))];
    }
    if ($method !== 'paypalcheckout' && strncmp($sub, 'I-', 2) !== 0) {
        return null;
    }
    // A one-off paypalcheckout payment exposes no payer afterwards (no order link, no related ids),
    // so only subscription-backed services are covered; the account name/email still apply to the rest.
    if (!preg_match('/^I-[A-Z0-9]{6,30}$/', $sub)) {
        return null;
    }
    $s = pm_pbl_paypal_get('/v1/billing/subscriptions/' . $sub);
    $payer = is_array($s['subscriber'] ?? null) ? $s['subscriber'] : null;
    if ($payer === null) {
        return null;
    }
    return ['source' => 'subscription', 'payer_id' => (string) ($payer['payer_id'] ?? ''),
        'email' => (string) ($payer['email_address'] ?? ''),
        'name' => trim(($payer['name']['given_name'] ?? '') . ' ' . ($payer['name']['surname'] ?? ''))];
}

/** Strongest match over every name we know for this buyer (PayPal's verified name first, then the account name). */
function pm_pbl_match_any(array $list, array $names, string $email, string $payerId): array
{
    $best = ['decision' => 'none', 'entry' => null, 'name' => false, 'email' => false, 'payer' => false];
    foreach ($names as $n) {
        $r = pm_pbl_match($list, (string) $n, $email, $payerId);
        if ($r['decision'] === 'block') {
            return $r;
        }
        if ($r['decision'] === 'flag' && $best['decision'] === 'none') {
            $best = $r;
        }
    }
    return $best;
}

// ---------------------------------------------------------------------------
// Hooks (not registered when this file is loaded outside WHMCS, e.g. by the list tooling)
// ---------------------------------------------------------------------------

if (function_exists('add_hook') && defined('ROOTDIR')) {

    add_hook('ShoppingCartValidateCheckout', 1, function ($vars) {
        try {
            $list = pm_pbl_load();
            if ($list === null) {
                return '';
            }
            $clientId = (int) ($vars['clientId'] ?? $vars['userid'] ?? 0);
            $name = trim(($vars['firstname'] ?? '') . ' ' . ($vars['lastname'] ?? ''));
            $email = (string) ($vars['email'] ?? '');
            if ($clientId > 0) {
                $c = \WHMCS\Database\Capsule::table('tblclients')->where('id', $clientId)->first(['firstname', 'lastname', 'email']);
                if ($c) {
                    $name = trim($c->firstname . ' ' . $c->lastname);
                    $email = (string) $c->email;
                }
            }
            $r = pm_pbl_match_any($list, [$name], $email, '');
            if ($r['decision'] === 'none') {
                return '';
            }
            pm_pbl_log('match', ['stage' => 'checkout', 'decision' => $r['decision'], 'entry' => $r['entry'],
                'client_id' => $clientId, 'name' => $r['name'], 'email' => $r['email']]);
            logActivity(sprintf('PAYER-BLOCKLIST %s at checkout: entry %s, client_id=%d, matched name=%s email=%s',
                strtoupper($r['decision']), $r['entry'], $clientId, $r['name'] ? 'yes' : 'no', $r['email'] ? 'yes' : 'no'));
            return $r['decision'] === 'block' ? PM_PAYER_BLOCKLIST_BLOCK_MESSAGE : '';
        } catch (\Throwable $e) {
            return '';
        }
    });

    add_hook('PreModuleCreate', 1, function ($vars) {
        try {
            $p = (array) ($vars['params'] ?? []);
            $serviceId = (int) ($p['serviceid'] ?? 0);
            if ($serviceId <= 0 || !empty($p['addonId'])) {
                return [];
            }
            $list = pm_pbl_load();
            if ($list === null) {
                return [];
            }
            $client = (array) ($p['clientsdetails'] ?? []);
            $accountName = trim(($client['firstname'] ?? '') . ' ' . ($client['lastname'] ?? ''));
            $payer = pm_pbl_payer_for_service($serviceId);
            $names = $payer ? [$payer['name'], $accountName] : [$accountName];
            $email = $payer ? $payer['email'] : (string) ($client['email'] ?? '');
            $r = pm_pbl_match_any($list, $names, $email, $payer ? $payer['payer_id'] : '');
            if ($r['decision'] === 'none') {
                return [];
            }
            $clientId = (int) ($p['userid'] ?? 0);
            pm_pbl_log('match', ['stage' => 'provision', 'decision' => $r['decision'], 'entry' => $r['entry'],
                'client_id' => $clientId, 'service_id' => $serviceId, 'payer_source' => $payer['source'] ?? 'none',
                'name' => $r['name'], 'email' => $r['email'], 'payer' => $r['payer']]);
            $line = sprintf('PAYER-BLOCKLIST %s at provisioning: service %d, entry %s, matched name=%s email=%s payer_id=%s',
                strtoupper($r['decision']), $serviceId, $r['entry'], $r['name'] ? 'yes' : 'no', $r['email'] ? 'yes' : 'no',
                $r['payer'] ? 'yes' : 'no');
            logActivity($line, $clientId);
            if ($r['decision'] !== 'block') {
                return [];
            }
            // Internal note (never shown to the client) so staff see why the service is not provisioned.
            // Appended in one statement so a note written concurrently by staff is never lost, and only
            // once per service: WHMCS re-queues the failed create and retries it every day.
            $db = \WHMCS\Database\Capsule::connection();
            $add = $db->getPdo()->quote("\n" . $line . '. Provisioning aborted; return the payment and close the account.'
                . '  -payer_blocklist ' . gmdate('d/m/Y'));
            $marker = sprintf('PAYER-BLOCKLIST BLOCK at provisioning: service %d,', $serviceId);
            \WHMCS\Database\Capsule::table('tblclients')->where('id', $clientId)
                ->where(function ($q) use ($marker) {
                    $q->whereNull('notes')->orWhere('notes', 'not like', '%' . $marker . '%');
                })
                ->update(['notes' => $db->raw("CONCAT(COALESCE(notes, ''), $add)")]);
            return ['abortcmd' => true];
        } catch (\Throwable $e) {
            return [];
        }
    });
}
