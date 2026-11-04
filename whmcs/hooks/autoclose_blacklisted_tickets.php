<?php
/**
 * WHMCS Hook: Hand blacklisted tickets to the administrative notice processor.
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
 * business benefit. The ticket processor permits only a fixed administrative
 * notice, never ordinary support, and verifies closure after notice acceptance.
 *
 * WHAT THIS HOOK DOES
 * -------------------
 * On TicketOpen (new ticket from any client) and TicketUserReply
 * (reply from any client to existing ticket), checks if the client
 * is in WHMCS group 9 (BLACKLIST). A guest ticket (no client) is checked
 * through the one client whose email is its sender address (ADR-0010). If so:
 *
 *   1. Leaves WHMCS's ticket status and last-reply timestamp untouched.
 *      Closing here used to hide the ticket from the notice processor.
 *   2. Adds an internal admin note (NOT client-visible) recording
 *      that ticket disposition remains with the notice processor.
 *   3. Records the handoff via WHMCS logActivity() so the event
 *      appears in the standard WHMCS Activity Log (Utilities →
 *      Logs → Activity Log).
 *
 * Non-blacklisted clients (groupid != 9) are untouched.
 *
 * Deploy only with a ticket processor that checks the live blacklist,
 * sends its fixed ban notice once per ticket and verifies closure.
 * It must route legal/privacy requests for review instead of auto-closing.
 * This hook sends nothing itself, does not bypass processing holds and
 * never treats a handoff note as proof that a notice was sent.
 *
 * Historical rationale for the former silent-close behaviour (not a gate)
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
 * @author    Aleksi Ursin <123067457+MagnaCapax@users.noreply.github.com>
 * @copyright 2026 Magna Capax Finland Oy
 * @license   Apache-2.0
 */

use WHMCS\Database\Capsule;

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
 * Client behind a guest ticket (userid 0): the one client whose email is the
 * ticket's sender address, of any status. A closed account cannot log in, so
 * after closure its tickets arrive by email as guest tickets; without this the
 * hook never sees them (ADR-0010). Guest attribution itself is not changed.
 *
 * Zero or several matching clients, or any DB error, answer 0: no note.
 *
 * @param int $ticketid
 * @return int client id, or 0
 */
function pm_autoclose_guest_client_id($ticketid) {
    if ((int) $ticketid <= 0) {
        return 0;
    }
    try {
        $email = strtolower(trim((string) Capsule::table('tbltickets')
            ->where('id', (int) $ticketid)
            ->value('email')));
        if ($email === '') {
            return 0;
        }
        $ids = Capsule::table('tblclients')->where('email', $email)->limit(2)->pluck('id');
    } catch (\Exception $e) {
        error_log(sprintf(
            'autoclose_blacklisted_tickets: guest lookup failed tid=%d: %s',
            $ticketid, $e->getMessage()
        ));
        return 0;
    }
    $ids = is_object($ids) && method_exists($ids, 'all') ? $ids->all() : (array) $ids;
    return count($ids) === 1 ? (int) reset($ids) : 0;
}

/**
 * Client to check for an event: the ticket's own client, or for a guest
 * ticket the single client owning the sender address.
 *
 * @param array $vars hook variables
 * @return int
 */
function pm_autoclose_event_client_id(array $vars) {
    $clientid = (int) ($vars['userid'] ?? 0);
    return $clientid > 0 ? $clientid : pm_autoclose_guest_client_id((int) ($vars['ticketid'] ?? 0));
}

/**
 * Record a pending disposition without assuming whether a notice was sent.
 *
 * The notice processor owns sending and closure together. A direct Closed
 * update here would bypass it; forcing In Progress would lower the urgency
 * of a native Customer-Reply event and could change processing holds.
 *
 * Internal admin note is written to tblticketnotes (NOT client-visible).
 *
 * @param int    $ticketid
 * @param int    $clientid
 * @param string $event 'TicketOpen' or 'TicketUserReply'
 */
function blacklistedTicketDispositionPending($ticketid, $clientid, $event) {
    if (!$ticketid) {
        return;
    }
    try {
        Capsule::table('tblticketnotes')->insert([
            'ticketid' => (int) $ticketid,
            'date'     => Capsule::raw('NOW()'),
            'admin'    => 'Blacklist disposition pending',
            'message'  => sprintf(
                'BLACKLIST HANDOFF: client.groupid=%d (BLACKLIST). Event=%s. '
                . 'Status unchanged for the ticket processor: fixed notice then verified closure, '
                . 'or legal/privacy review. Prior delivery not evaluated. '
                . 'No customer-facing reply sent by this hook.',
                PM_BLACKLIST_GROUP_ID,
                $event
            ),
        ]);
    } catch (\Exception $e) {
        error_log(sprintf(
            'autoclose_blacklisted_tickets: pending-note failed tid=%d cid=%d: %s',
            $ticketid, $clientid, $e->getMessage()
        ));
        return;
    }

    // WHMCS-native activity log (Utilities → Logs → Activity Log).
    if (function_exists('logActivity')) {
        logActivity(sprintf(
            'autoclose_blacklisted_tickets: disposition pending %s tid=%d cid=%d groupid=%d',
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
if (function_exists('add_hook') && defined('ROOTDIR')) {
add_hook('TicketOpen', 1, function ($vars) {
    $clientid = pm_autoclose_event_client_id($vars);
    if (!pm_autoclose_is_blacklisted($clientid)) {
        return;
    }
    blacklistedTicketDispositionPending(
        (int) ($vars['ticketid'] ?? 0),
        $clientid,
        'TicketOpen'
    );
});

/**
 * Hook: TicketUserReply
 *
 * Fires when a client adds a reply to an existing ticket. Preserves the
 * native reopened status, including when a notice was already sent:
 * the processor must then verify closure without sending another notice.
 *
 * $vars includes ticketid, userid, message, attachments, etc.
 */
add_hook('TicketUserReply', 1, function ($vars) {
    $clientid = pm_autoclose_event_client_id($vars);
    if (!pm_autoclose_is_blacklisted($clientid)) {
        return;
    }
    blacklistedTicketDispositionPending(
        (int) ($vars['ticketid'] ?? 0),
        $clientid,
        'TicketUserReply'
    );
});
}
