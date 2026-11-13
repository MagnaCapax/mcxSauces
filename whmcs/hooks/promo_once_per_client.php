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
 *   Guests (no account yet) have no orders, so they pass.
 *   pm_poc_decide() is pure, so a CLI tool or a test can ask the same question without WHMCS.
 *
 * THE CONFIG FILE
 * ---------------
 * JSON, readable by the web user, kept OUTSIDE the web root:
 *   {"v":1,"codes":["CODE1","CODE2"]}
 * Codes are compared case-insensitively. An empty list turns the hook into a no-op.
 *
 * LIMITS (by design, documented rather than hidden)
 * -------------------------------------------------
 * - It counts orders per customer account, not per person; other fraud tooling covers people who
 *   open several accounts.
 * - Only the shopping-cart checkout is checked. Orders created by staff in the admin area or through
 *   the API (AddOrder) are not, so staff can make exceptions.
 * - Orders in any other status (Cancelled, Fraud) do not count: a cancelled order frees the code.
 * - Two checkouts by the same customer in the same second can both pass; the second order is visible
 *   to staff and can be cancelled.
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

    /** Once-per-customer codes from the config file, normalized; [] when missing, unreadable or malformed. */
    function pm_poc_codes(string $file = PM_PROMO_ONCE_FILE): array
    {
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) @file_get_contents($file), true);
        if (!is_array($data) || ($data['v'] ?? null) !== 1 || !is_array($data['codes'] ?? null)) {
            return [];
        }
        $codes = [];
        foreach ($data['codes'] as $code) {
            $norm = pm_poc_normalize($code);
            if ($norm !== '') {
                $codes[$norm] = $norm;
            }
        }
        return array_values($codes);
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

    if (function_exists('add_hook') && defined('ROOTDIR')) {
        add_hook('ShoppingCartValidateCheckout', 1, function ($vars) {
            try {
                $codes = pm_poc_codes();
                if ($codes === []) {
                    return '';
                }
                $cart = class_exists('\WHMCS\Session') ? \WHMCS\Session::get('cart') : null;
                if (!is_array($cart)) {
                    $cart = $_SESSION['cart'] ?? [];
                }
                $clientId = (int) ($vars['clientId'] ?? $vars['userid'] ?? 0);
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
