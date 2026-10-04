<?php
/**
 * WHMCS Hook: per-customer product cap — a product can be limited to N services per
 * customer (e.g. a special offer that is "one per customer").
 *
 * WHAT THIS HOOK DOES
 * -------------------
 *   ShoppingCartValidateCheckout (before the order exists):
 *     for every capped product in the cart, services the customer already holds
 *     (Pending, Active or Suspended — an unpaid order counts) plus the quantity in
 *     the cart must not exceed the cap. Otherwise checkout is refused with a message
 *     naming the product and the limit, and an Activity Log line is written.
 *   Guests (no account yet) hold nothing, so only the cart quantity counts.
 *
 * THE CONFIG FILE
 * ---------------
 * JSON, readable by the web user, kept OUTSIDE the web root:
 *   {"v":1,"caps":{"<product id>":<max services per customer>}}
 * An empty "caps" object turns the hook into a no-op. Caps below 1 are ignored.
 *
 * LIMITS (by design, documented rather than hidden)
 * -------------------------------------------------
 * - Only the shopping-cart checkout is checked. Orders created by staff in the admin
 *   area or through the API (AddOrder) are not, so staff can make exceptions.
 * - Two checkouts by the same customer in the same second can both pass; the second
 *   order is visible to staff and can be cancelled.
 * - It counts services per customer account, not per person; the operator's other
 *   fraud tooling covers people who open several accounts.
 *
 * FAILURE MODE
 * ------------
 * Missing file, bad JSON, any exception: ALLOW. A bug here must never block a paying
 * customer's order.
 *
 * Deploy to: <whmcs_root>/includes/hooks/product_per_client_cap.php
 * Configure: define PM_PRODUCT_CAP_FILE before load, or use the default below.
 *
 * Made for pulsedmedia.com
 *
 * SPDX-License-Identifier: Apache-2.0
 *
 * @copyright 2026 Magna Capax Finland Oy
 * @license   Apache-2.0
 */

declare(strict_types=1);

if (!defined('PM_PRODUCT_CAP_FILE')) {
    // A "pm-private" directory two levels above the WHMCS root, i.e. outside a typical web root.
    define('PM_PRODUCT_CAP_FILE', (defined('ROOTDIR') ? dirname(ROOTDIR, 2) : sys_get_temp_dir()) . '/pm-private/product-per-client-cap.json');
}

if (!function_exists('pm_ppc_caps')) {

    /** Caps from the config file as [pid => max]; [] when missing, unreadable or malformed. */
    function pm_ppc_caps(string $file = PM_PRODUCT_CAP_FILE): array
    {
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) @file_get_contents($file), true);
        if (!is_array($data) || ($data['v'] ?? null) !== 1 || !is_array($data['caps'] ?? null)) {
            return [];
        }
        $caps = [];
        foreach ($data['caps'] as $pid => $max) {
            if (ctype_digit((string) $pid) && (int) $pid > 0 && is_int($max) && $max >= 1) {
                $caps[(int) $pid] = $max;
            }
        }
        return $caps;
    }

    /** Quantity per product id in the cart's product lines (a line without qty is 1). */
    function pm_ppc_cart_quantities(array $products): array
    {
        $qty = [];
        foreach ($products as $line) {
            $pid = (int) (is_array($line) ? ($line['pid'] ?? 0) : 0);
            if ($pid <= 0) {
                continue;
            }
            $q = (int) ($line['qty'] ?? 1);
            $qty[$pid] = ($qty[$pid] ?? 0) + max(1, $q);
        }
        return $qty;
    }

    /**
     * Pure decision. $held(pid) = services the customer already holds; $name(pid) = product name.
     * Returns one row per product over its cap: [pid, cap, held, in_cart, name].
     */
    function pm_ppc_over_cap(array $caps, array $cartQty, callable $held, callable $name): array
    {
        $over = [];
        foreach ($cartQty as $pid => $inCart) {
            if (!isset($caps[$pid])) {
                continue;
            }
            $h = (int) $held($pid);
            if ($h + $inCart > $caps[$pid]) {
                $over[] = ['pid' => $pid, 'cap' => $caps[$pid], 'held' => $h, 'in_cart' => $inCart, 'name' => (string) $name($pid)];
            }
        }
        return $over;
    }

    /** Customer-facing message for one over-cap row; the product name is HTML-escaped. */
    function pm_ppc_message(array $row): string
    {
        $name = htmlspecialchars($row['name'] !== '' ? $row['name'] : 'This product', ENT_QUOTES, 'UTF-8');
        $msg = sprintf('%s is limited to %d per customer.', $name, $row['cap']);
        if ($row['held'] > 0) {
            $msg .= sprintf(' Your account already has %d (an unpaid order counts too).', $row['held']);
        } else {
            $msg .= sprintf(' Please reduce the quantity in your cart to %d.', $row['cap']);
        }
        return $msg;
    }

    if (function_exists('add_hook') && defined('ROOTDIR')) {
        add_hook('ShoppingCartValidateCheckout', 1, function ($vars) {
            try {
                $caps = pm_ppc_caps();
                if ($caps === []) {
                    return '';
                }
                $cart = class_exists('\WHMCS\Session') ? \WHMCS\Session::get('cart') : null;
                if (!is_array($cart)) {
                    $cart = $_SESSION['cart'] ?? [];
                }
                $cartQty = pm_ppc_cart_quantities(is_array($cart['products'] ?? null) ? $cart['products'] : []);
                $clientId = (int) ($vars['clientId'] ?? $vars['userid'] ?? 0);
                $held = function (int $pid) use ($clientId): int {
                    if ($clientId <= 0) {
                        return 0;
                    }
                    return (int) \WHMCS\Database\Capsule::table('tblhosting')->where('userid', $clientId)
                        ->where('packageid', $pid)->whereIn('domainstatus', ['Pending', 'Active', 'Suspended'])->count();
                };
                $name = function (int $pid): string {
                    return (string) \WHMCS\Database\Capsule::table('tblproducts')->where('id', $pid)->value('name');
                };
                $over = pm_ppc_over_cap($caps, $cartQty, $held, $name);
                if ($over === []) {
                    return '';
                }
                $errors = [];
                foreach ($over as $row) {
                    logActivity(sprintf('PRODUCT-CAP checkout refused: product %d cap %d held %d in cart %d, client_id=%d',
                        $row['pid'], $row['cap'], $row['held'], $row['in_cart'], $clientId));
                    $errors[] = pm_ppc_message($row);
                }
                return $errors;
            } catch (\Throwable $e) {
                return '';
            }
        });
    }
}
