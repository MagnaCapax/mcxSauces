<?php
/**
 * WHMCS Hook: Detect "skeleton" client records (empty contact email) at creation.
 *
 * WHY THIS EXISTS
 * ---------------
 * WHMCS 8.x separates users (login identity) from clients (billing accounts):
 * one user can own several client accounts. Some creation flows — notably an
 * existing user starting a new order/account — produce a "skeleton" client:
 * the row exists and owns an active, paid service, but its contact fields are
 * empty, most damagingly email = ''.
 *
 * Why that is dangerous, silently and late:
 *
 *   - Broken communications: client-targeted mail (credential delivery,
 *     invoices, account notices) is sent to the CLIENT email. An empty client
 *     email means every one of those messages fails to send, with no
 *     customer-visible error. The account looks fine — active, paid, and the
 *     user can still log in via their user-record email — so the gap is
 *     usually found weeks later via an "I never got my login" ticket.
 *
 *   - Recurrence: each instance gets hand-fixed downstream instead of caught
 *     at the source, so it keeps happening.
 *
 * WHAT THIS HOOK DOES
 * -------------------
 * Fires on ClientAdd (client creation). Inspects the new client's required
 * contact fields and, if the email is empty/invalid (or first and last name
 * are both empty, or country is empty), writes a SKELETON CLIENT line to the
 * WHMCS Activity Log and optionally calls a configurable alert callback. It
 * turns a weeks-late discovery into a creation-time signal.
 *
 * IMPORTANT — what this hook does NOT do:
 *
 *   - It does NOT modify the email or any client field. An account's email is
 *     a security boundary; silently rewriting it is an account-takeover
 *     vector. Resolution is human/customer-driven (e.g. the customer registers
 *     a clean account), not an automated overwrite.
 *
 *   - It does NOT block the signup. ClientAdd fires AFTER the row is inserted,
 *     so this hook detects and alerts; it cannot reject. To REJECT empty-field
 *     input before creation you need the pre-creation validation surface,
 *     which is install-dependent.
 *
 *   - It logs only the client/user IDs and WHICH fields are missing — never
 *     the email value or any contact content.
 *
 * NOTES
 * -----
 *   - ClientAdd payload keys used: client_id, user_id, email, firstname,
 *     lastname, country (WHMCS 8.x).
 *   - Uses only logActivity() and filter_var() — no DB access, no external
 *     calls. Wrapped in try/catch so a hook error can never break signup.
 *   - Because the try/catch hides errors as silent no-ops, a post-deploy
 *     functional test (create a client with an empty email, then confirm the
 *     Activity Log line) is recommended.
 *
 * CONFIG: define PM_SKELETON_ALERT as a callable to receive
 * {client_id, user_id, missing[]} for real-time alerting (Telegram, email,
 * etc). Optional; without it the hook logs to the Activity Log only.
 *
 * License: GPL v3 (see repository LICENSE).
 */

if (!defined('WHMCS')) {
    die('Access Denied');
}

add_hook('ClientAdd', 1, function ($vars) {
    try {
        $clientId = (int) ($vars['client_id'] ?? $vars['userid'] ?? 0);
        $userId   = (int) ($vars['user_id'] ?? 0);
        $email    = trim((string) ($vars['email'] ?? ''));
        $first    = trim((string) ($vars['firstname'] ?? ''));
        $last     = trim((string) ($vars['lastname'] ?? ''));
        $country  = trim((string) ($vars['country'] ?? ''));

        $missing = [];
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $missing[] = ($email === '') ? 'email(EMPTY)' : 'email(INVALID)';
        }
        if ($first === '' && $last === '') {
            $missing[] = 'name(EMPTY)';
        }
        if ($country === '') {
            $missing[] = 'country(EMPTY)';
        }

        if (!$missing) {
            return; // complete profile — nothing to do
        }

        if (function_exists('logActivity')) {
            logActivity(sprintf(
                'SKELETON CLIENT detected at creation: client_id=%d user_id=%d missing=[%s] — '
                . 'client-targeted mail will fail until resolved. The email field is a security '
                . 'boundary; resolve via the customer, do not silently overwrite.',
                $clientId,
                $userId,
                implode(',', $missing)
            ), $clientId);
        }

        if (defined('PM_SKELETON_ALERT') && is_callable(PM_SKELETON_ALERT)) {
            call_user_func(PM_SKELETON_ALERT, [
                'client_id' => $clientId,
                'user_id'   => $userId,
                'missing'   => $missing,
            ]);
        }
    } catch (\Throwable $e) {
        // Never break signup. NOTE: this catch hides bugs as silent no-ops — run the
        // post-deploy functional test described in the docblock.
        if (function_exists('logActivity')) {
            logActivity('clientadd_skeleton_detect hook error: ' . $e->getMessage());
        }
    }
});
