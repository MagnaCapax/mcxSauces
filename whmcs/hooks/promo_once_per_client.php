<?php
/**
 * WHMCS Hook: promotion codes that can be used once per customer.
 *
 * WHY THIS EXISTS
 * ---------------
 * WHMCS 9.0.x refuses every EXISTING client when a promotion has its own "once per client" flag
 * (onceperclient=1), even a client who never used the code, and the cause is not readable (the
 * validation sits in encoded core code). So such codes keep onceperclient=0 and this hook enforces
 * "once per customer" at checkout instead.
 *
 * WHAT THIS HOOK DOES
 * -------------------
 *   ShoppingCartValidateCheckout (before the order exists):
 *     if the cart's promotion code is listed in the config and the customer already has an order
 *     with that code (Pending or Active; an unpaid order counts), checkout is refused with a message
 *     naming the code, and an Activity Log line is written.
 *   PreCalculateCartTotals (every time the cart total is worked out, before the code is read):
 *     the same check for the logged-in client; a code they already hold is taken out of the cart,
 *     an Activity Log line is written, and the next cart page (ClientAreaPageCart) shows the same
 *     message. So the cart never shows a discount that checkout would refuse (ADR-0012).
 *   Guests (no account yet) have no orders, so they pass.
 *   Shared cap: when the cart's code belongs to a "caps" group and the group's codes have together been
 *   used "max" times or more (WHMCS's own per-code use counters, summed), checkout is refused for
 *   everyone, guests included. One campaign sold under several codes then stops at one total.
 *   pm_poc_decide() and pm_poc_cap_decide() are pure, so a CLI tool or a test can ask the same question
 *   without WHMCS.
 *
 * THE CONFIG FILE
 * ---------------
 * JSON, readable by the web user, kept OUTSIDE the web root:
 *   {"v":1,"codes":["CODE1","CODE2"],"caps":[{"codes":["CODE1","CODE2","CODE3"],"max":100}]}
 * Codes are compared case-insensitively. "caps" is optional. Empty lists turn the hook into a no-op.
 *
 * LIMITS (by design, documented rather than hidden)
 * -------------------------------------------------
 * - It counts orders per customer account, not per person; other fraud tooling covers people who
 *   open several accounts.
 * - Only the shopping-cart checkout is checked. Orders created by staff in the admin area or through
 *   the API (AddOrder) are not, so staff can make exceptions.
 * - Orders in any other status (Cancelled, Fraud) do not count: a cancelled order frees the code.
 * - Two checkouts by the same customer in the same second can both pass; the second order is visible
 *   to staff and can be cancelled. Likewise two checkouts at the last free use of a shared cap.
 * - The shared cap counts uses as WHMCS does for a code's own maximum: a cancelled order does not give
 *   its use back.
 *
 * FAILURE MODE
 * ------------
 * Missing file, bad JSON, any exception: ALLOW. A bug here must never block a paying customer's order.
 *
 * Deploy to: <whmcs_root>/includes/hooks/promo_once_per_client.php
 * Configure: define PM_PROMO_ONCE_FILE before load, or use the default below.
 *
 * Made for pulsedmedia.com
 *
 * SPDX-License-Identifier: Apache-2.0
 *
 * @copyright 2026 Magna Capax Finland Oy
 * @license   Apache-2.0
 */

declare(strict_types=1);

if (!defined('PM_PROMO_ONCE_FILE')) {
    // A "pm-private" directory two levels above the WHMCS root, i.e. outside a typical web root.
    define('PM_PROMO_ONCE_FILE', (defined('ROOTDIR') ? dirname(ROOTDIR, 2) : sys_get_temp_dir()) . '/pm-private/promo-once-per-client.json');
}

if (!function_exists('pm_poc_codes')) {

    /** Promotion code in comparison form: trimmed and upper-cased. */
    function pm_poc_normalize($code): string
    {
        return is_string($code) ? strtoupper(trim($code)) : '';
    }

    /** The decoded config, or null when missing, unreadable, malformed or of another version. */
    function pm_poc_config(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($file), true);
        return is_array($data) && ($data['v'] ?? null) === 1 ? $data : null;
    }

    /** A list of codes, normalized and deduplicated; junk entries dropped. */
    function pm_poc_code_list(array $list): array
    {
        $codes = [];
        foreach ($list as $code) {
            $norm = pm_poc_normalize($code);
            if ($norm !== '') {
                $codes[$norm] = $norm;
            }
        }
        return array_values($codes);
    }

    /** Once-per-customer codes from the config file, normalized; [] when missing, unreadable or malformed. */
    function pm_poc_codes(string $file = PM_PROMO_ONCE_FILE): array
    {
        $data = pm_poc_config($file);
        return $data !== null && is_array($data['codes'] ?? null) ? pm_poc_code_list($data['codes']) : [];
    }

    /** Shared caps from the config file: list of ['codes' => normalized codes, 'max' => int >= 0]; malformed groups dropped. */
    function pm_poc_caps(string $file = PM_PROMO_ONCE_FILE): array
    {
        $data = pm_poc_config($file);
        if ($data === null || !is_array($data['caps'] ?? null)) {
            return [];
        }
        $caps = [];
        foreach ($data['caps'] as $cap) {
            if (!is_array($cap) || !is_array($cap['codes'] ?? null) || !is_int($cap['max'] ?? null) || $cap['max'] < 0) {
                continue;
            }
            $codes = pm_poc_code_list($cap['codes']);
            if ($codes !== []) {
                $caps[] = ['codes' => $codes, 'max' => $cap['max']];
            }
        }
        return $caps;
    }

    /**
     * Pure decision for the shared caps. $used(codes) = the summed use count of those codes.
     * Returns ['code' => normalized cart code, 'used' => n, 'max' => cap] when the cart code's group is used up, else null.
     */
    function pm_poc_cap_decide(array $caps, $cartCode, callable $used): ?array
    {
        $code = pm_poc_normalize($cartCode);
        if ($code === '') {
            return null;
        }
        foreach ($caps as $cap) {
            if (in_array($code, $cap['codes'], true)) {
                $n = (int) $used($cap['codes']);
                if ($n >= $cap['max']) {
                    return ['code' => $code, 'used' => $n, 'max' => $cap['max']];
                }
            }
        }
        return null;
    }

    /** WHMCS's own use counters of these promotion codes, summed. Needs WHMCS. */
    function pm_poc_used(array $codes): int
    {
        if ($codes === []) {
            return 0;
        }
        $marks = implode(',', array_fill(0, count($codes), '?'));
        return (int) \WHMCS\Database\Capsule::table('tblpromotions')->whereRaw("UPPER(code) IN ($marks)", $codes)->sum('uses');
    }

    /** Customer-facing message for a used-up shared cap; the code is HTML-escaped. */
    function pm_poc_cap_message(array $row): string
    {
        $code = htmlspecialchars((string) $row['code'], ENT_QUOTES, 'UTF-8');
        return sprintf('The promotion code %s is no longer available: this offer was limited to %d orders and all of them have been taken.',
            $code, (int) $row['max']);
    }

    /**
     * Pure decision. $held(code) = Pending/Active orders the client has with that code.
     * Returns ['code' => normalized cart code, 'held' => count] when checkout must be refused, else null.
     * Unmapped codes, an empty cart code and guests (client id 0 or below) return null.
     */
    function pm_poc_decide(array $codes, $cartCode, int $clientId, callable $held): ?array
    {
        $code = pm_poc_normalize($cartCode);
        if ($code === '' || $clientId <= 0 || !in_array($code, $codes, true)) {
            return null;
        }
        $n = (int) $held($code);
        return $n > 0 ? ['code' => $code, 'held' => $n] : null;
    }

    /** Orders of the client with this promotion code that count (Pending, Active). Needs WHMCS. */
    function pm_poc_held(int $clientId, string $code): int
    {
        if ($clientId <= 0 || $code === '') {
            return 0;
        }
        // Command-line checks only (never a web request): a fixture of held counts, so a live check can run the
        // cart and checkout paths as a customer holding the code without writing an order.
        if (PHP_SAPI === 'cli' && is_array($GLOBALS['pm_poc_held_fixture'] ?? null)) {
            return (int) ($GLOBALS['pm_poc_held_fixture'][$clientId][pm_poc_normalize($code)] ?? 0);
        }
        return (int) \WHMCS\Database\Capsule::table('tblorders')->where('userid', $clientId)
            ->whereRaw('UPPER(promocode) = ?', [pm_poc_normalize($code)])
            ->whereIn('status', ['Pending', 'Active'])->count();
    }

    /** Customer-facing message for one refusal row; the code is HTML-escaped. */
    function pm_poc_message(array $row): string
    {
        $code = htmlspecialchars((string) $row['code'], ENT_QUOTES, 'UTF-8');
        return sprintf('The promotion code %s can only be used once per customer. '
            . 'Your account already has an order with this code (an unpaid order counts too).', $code);
    }

    /**
     * Pure decision for the cart. When the cart's code must be refused for this client, returns
     * ['cart' => the cart without its promotion code, 'row' => the pm_poc_decide row]; else null.
     */
    function pm_poc_cart_strip(array $codes, array $cart, int $clientId, callable $held): ?array
    {
        $row = pm_poc_decide($codes, $cart['promo'] ?? '', $clientId, $held);
        if ($row === null) {
            return null;
        }
        unset($cart['promo']);
        return ['cart' => $cart, 'row' => $row];
    }

    /** The logged-in client's id from a session getter; 0 for a guest or anything that is not a positive number. */
    function pm_poc_session_client(callable $get): int
    {
        $uid = $get('uid');
        return is_numeric($uid) && (int) $uid > 0 ? (int) $uid : 0;
    }

    /**
     * True for the checkout form submission (a=checkout with submit or checkout set). WHMCS can work out the cart
     * total there before it runs the checkout hooks; taking the code out at that point would place the order at
     * full price with no message, so the checkout refusal handles that request instead.
     */
    function pm_poc_is_checkout_submit(array $request): bool
    {
        return ($request['a'] ?? '') === 'checkout' && (isset($request['submit']) || isset($request['checkout']));
    }

    /** Session key that carries one refused code to the next cart page view. */
    if (!defined('PM_POC_NOTICE_KEY')) {
        define('PM_POC_NOTICE_KEY', 'pm_poc_cart_notice');
    }

    if (function_exists('add_hook') && defined('ROOTDIR')) {
        // Cart totals: a code this client already holds is taken out of the cart before WHMCS reads it,
        // so the cart never shows a discount that checkout would refuse.
        add_hook('PreCalculateCartTotals', 1, function ($vars) {
            try {
                $codes = pm_poc_codes();
                if ($codes === [] || pm_poc_is_checkout_submit($_REQUEST)) {
                    return;
                }
                $hasSession = class_exists('\WHMCS\Session');
                $get = function (string $key) use ($hasSession) {
                    return $hasSession ? \WHMCS\Session::get($key) : ($_SESSION[$key] ?? null);
                };
                $clientId = pm_poc_session_client($get);
                $cart = $get('cart');
                if ($clientId <= 0 || !is_array($cart)) {
                    return;
                }
                $out = pm_poc_cart_strip($codes, $cart, $clientId, function (string $code) use ($clientId): int {
                    return pm_poc_held($clientId, $code);
                });
                if ($out === null) {
                    return;
                }
                if ($hasSession) {
                    \WHMCS\Session::set('cart', $out['cart']);
                    \WHMCS\Session::set(PM_POC_NOTICE_KEY, $out['row']);
                }
                unset($_SESSION['cart']['promo']);
                $_SESSION[PM_POC_NOTICE_KEY] = $out['row'];
                logActivity(sprintf('PROMO-ONCE cart removed: code %s held %d client_id=%d', $out['row']['code'], $out['row']['held'], $clientId));
            } catch (\Throwable $e) {
                return;
            }
        });

        // Cart page: say why the code was taken out, once.
        add_hook('ClientAreaPageCart', 1, function ($vars) {
            try {
                $row = $_SESSION[PM_POC_NOTICE_KEY] ?? null;
                if (class_exists('\WHMCS\Session')) {
                    $row = \WHMCS\Session::get(PM_POC_NOTICE_KEY) ?: $row;
                    \WHMCS\Session::set(PM_POC_NOTICE_KEY, null);
                }
                unset($_SESSION[PM_POC_NOTICE_KEY]);
                if (!is_array($row) || !isset($row['code'])) {
                    return [];
                }
                $msg = pm_poc_message($row);
                return ['promoerrormessage' => $msg, 'errormessage' => '<li>' . $msg];
            } catch (\Throwable $e) {
                return [];
            }
        });

        add_hook('ShoppingCartValidateCheckout', 1, function ($vars) {
            try {
                $codes = pm_poc_codes();
                $caps = pm_poc_caps();
                if ($codes === [] && $caps === []) {
                    return '';
                }
                $cart = class_exists('\WHMCS\Session') ? \WHMCS\Session::get('cart') : null;
                if (!is_array($cart)) {
                    $cart = $_SESSION['cart'] ?? [];
                }
                $clientId = (int) ($vars['clientId'] ?? $vars['userid'] ?? 0);
                $capRow = pm_poc_cap_decide($caps, $cart['promo'] ?? '', 'pm_poc_used');
                if ($capRow !== null) {
                    logActivity(sprintf('PROMO-CAP checkout refused: code %s group used %d of %d client_id=%d', $capRow['code'], $capRow['used'], $capRow['max'], $clientId));
                    return [pm_poc_cap_message($capRow)];
                }
                $row = pm_poc_decide($codes, $cart['promo'] ?? '', $clientId, function (string $code) use ($clientId): int {
                    return pm_poc_held($clientId, $code);
                });
                if ($row === null) {
                    return '';
                }
                logActivity(sprintf('PROMO-ONCE checkout refused: code %s held %d client_id=%d', $row['code'], $row['held'], $clientId));
                return [pm_poc_message($row)];
            } catch (\Throwable $e) {
                return '';
            }
        });
    }
}
