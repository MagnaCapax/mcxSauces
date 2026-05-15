<?php
/**
 * WHMCS Hook: Silent auto-close tickets from blacklisted clients (groupid=9).
 *
 * WHY THIS EXISTS
 * ---------------
 * Clients in the BLACKLIST group (groupid=9) have been permanently
 * banned — AUP violation (Copy Fail / Dirty Frag exploit attempts,
 * fraud, or similar). The relationship is terminated. Every support
 * ticket they open or reply to consumes support cycles producing
 * identical "decision is final" responses.
 *
 * Background: a kernel-LPE PoC publication wave in 2026-05 produced a
 * cluster of customers attempting public exploit code against their
 * own hosts. After blacklisting, several continued to open or reply
 * to support tickets — demanding service restoration, data retrieval,
 * refunds, or appealing the closure. Manual handling of these tickets
 * is high-volume low-value work: the answer is always the same (the
 * decision is final) and engagement with banned actors carries no
 * business benefit. This hook eliminates the engagement entirely.
 *
 * WHAT THIS HOOK DOES
 * -------------------
 * On TicketOpen (new ticket from any client) and TicketUserReply
 * (reply from any client to existing ticket), checks if the client
 * is in WHMCS group 9 (BLACKLIST). If so:
 *
 *   1. Sets ticket status to Closed via direct Capsule update.
 *      No AddTicketReply call — no customer-facing message is sent,
 *      no customer email notification is triggered.
 *   2. Adds an internal admin note (NOT client-visible) recording
 *      the auto-close action for audit trail.
 *   3. Records the action via WHMCS logActivity() so the auto-close
 *      appears in the standard WHMCS Activity Log (Utilities →
 *      Logs → Activity Log).
 *
 * Non-blacklisted clients (groupid != 9) are untouched.
 *
 * No customer-facing message is sent. The client sees their ticket
 * transition to Closed status in the panel. No support engagement
 * occurs.
 *
 * GDPR / Finnish accounting law context
 * -------------------------------------
 * GDPR Art. 17 erasure cannot force deletion of the blacklist record:
 *   - Finnish Accounting Act 1336/1997 § 10 mandates 6-10y retention
 *     for transactional records → GDPR Art. 17(3)(b) legal-obligation
 *     exemption applies.
 *   - The blacklist record itself rides on GDPR Art. 6(1)(f)
 *     legitimate interest (fraud prevention, explicitly named in
 *     Recital 47) → Art. 17(3)(e) defence-of-legal-claims reinforces.
 *
 * The support ticket system is not a legally required channel for
 * Art. 15/17 requests. Auto-closing support tickets from banned users
 * does not extinguish data subject rights — those are exercised via
 * formal written request to the company contact, not via the support
 * tunnel. Auto-closing without engagement is GDPR-safe under Art. 22
 * (closing a post-termination ticket produces no "legal effects or
 * similarly significant effects" within Art. 22(1) scope).
 *
 * Deploy to: <whmcs_root>/includes/hooks/autoclose_blacklisted_tickets.php
 *
 * Made for pulsedmedia.com
 *
 * SPDX-License-Identifier: Apache-2.0
 *
 * @author    Aleksi Ursin <aleksi@magnacapax.fi>
 * @copyright 2026 Magna Capax Finland Oy
 * @license   Apache-2.0
 */

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * The BLACKLIST client group ID in WHMCS.
 * Verify against this installation's client-group configuration
 * (Clients → Client Groups). Override here if a different group ID
 * is in use for permanently banned clients.
 */
if (!defined('PM_BLACKLIST_GROUP_ID')) {
    define('PM_BLACKLIST_GROUP_ID', 9);
}

/**
 * Check if a client is in the BLACKLIST group.
 *
 * Fails closed (returns false) on DB error — never auto-close a ticket
 * based on a failed query result. False negatives are acceptable;
 * false positives (closing a non-blacklisted client's ticket) are not.
 *
 * @param int $clientid
 * @return bool
 */
function pm_autoclose_is_blacklisted($clientid) {
    if (!$clientid) {
        return false;
    }
    try {
        $client = Capsule::table('tblclients')
            ->where('id', (int) $clientid)
            ->first(['id', 'groupid']);
    } catch (\Exception $e) {
        error_log(sprintf(
            'autoclose_blacklisted_tickets: groupid lookup failed cid=%d: %s',
            $clientid, $e->getMessage()
        ));
        return false;
    }
    if (!$client) {
        return false;
    }
    return ((int) $client->groupid) === PM_BLACKLIST_GROUP_ID;
}

/**
 * Silently close a ticket and record an audit trail.
 *
 * Direct Capsule UPDATE on tbltickets — bypasses AddTicketReply,
 * so no customer-visible reply is posted and no customer email is
 * triggered by WHMCS.
 *
 * Internal admin note is written to tblticketnotes (NOT client-visible).
 *
 * @param int    $ticketid
 * @param int    $clientid
 * @param string $event 'TicketOpen' or 'TicketUserReply'
 */
function pm_autoclose_close_ticket($ticketid, $clientid, $event) {
    if (!$ticketid) {
        return;
    }
    try {
        Capsule::table('tbltickets')
            ->where('id', (int) $ticketid)
            ->update([
                'status'    => 'Closed',
                'lastreply' => Capsule::raw('NOW()'),
            ]);

        Capsule::table('tblticketnotes')->insert([
            'ticketid' => (int) $ticketid,
            'date'     => Capsule::raw('NOW()'),
            'admin'    => 'Auto-close (groupid=9)',
            'message'  => sprintf(
                'AUTOCLOSE: client.groupid=%d (BLACKLIST). Event=%s. '
                . 'Ticket closed silently per blacklisted-user policy. '
                . 'No customer-facing reply sent.',
                PM_BLACKLIST_GROUP_ID,
                $event
            ),
        ]);
    } catch (\Exception $e) {
        error_log(sprintf(
            'autoclose_blacklisted_tickets: close failed tid=%d cid=%d: %s',
            $ticketid, $clientid, $e->getMessage()
        ));
        return;
    }

    // WHMCS-native activity log (Utilities → Logs → Activity Log).
    if (function_exists('logActivity')) {
        logActivity(sprintf(
            'autoclose_blacklisted_tickets: %s tid=%d cid=%d groupid=%d',
            $event, (int) $ticketid, (int) $clientid, PM_BLACKLIST_GROUP_ID
        ));
    }
}

/**
 * Hook: TicketOpen
 *
 * Fires after a new ticket is created (any source: panel, email, API).
 * $vars includes ticketid, userid, deptid, subject, message, priority,
 * status, etc.
 */
add_hook('TicketOpen', 1, function ($vars) {
    $clientid = (int) ($vars['userid'] ?? 0);
    if (!pm_autoclose_is_blacklisted($clientid)) {
        return;
    }
    pm_autoclose_close_ticket(
        (int) ($vars['ticketid'] ?? 0),
        $clientid,
        'TicketOpen'
    );
});

/**
 * Hook: TicketUserReply
 *
 * Fires when a client adds a reply to an existing ticket. Re-closes
 * the ticket if a blacklisted client replies to a previously-closed
 * ticket they own (WHMCS reopens tickets on user reply by default).
 *
 * $vars includes ticketid, userid, message, attachments, etc.
 */
add_hook('TicketUserReply', 1, function ($vars) {
    $clientid = (int) ($vars['userid'] ?? 0);
    if (!pm_autoclose_is_blacklisted($clientid)) {
        return;
    }
    pm_autoclose_close_ticket(
        (int) ($vars['ticketid'] ?? 0),
        $clientid,
        'TicketUserReply'
    );
});
