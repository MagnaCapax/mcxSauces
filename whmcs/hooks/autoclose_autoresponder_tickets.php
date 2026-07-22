<?php
/**
 * WHMCS Hook: Auto-close RFC-3834 auto-reply / autoresponder tickets at ingress.
 *
 * WHY THIS EXISTS
 * ---------------
 * WHMCS ticket import creates a ticket from EVERY inbound email, including
 * machine-generated auto-replies (vacation/OOO, "no longer monitored"
 * unmonitored-mailbox notices, delivery bounces). WHMCS then sends its own
 * ticket auto-acknowledgment to the sender — which, when the sender is an
 * autoresponder, produces ANOTHER auto-reply back into support@ / sales@,
 * which WHMCS imports as a NEW ticket, which acks again: a mail loop.
 *
 * Documented instances of this class:
 *   - mailer-daemon@ / postmaster@ bounce loop: 74 recipients -> 171,000
 *     bounce entries (memory/lessons/whmcs-api/20260318-* ; 20260421-*).
 *   - 2026-07-21 Trustpilot: ONE flagged review -> the unmonitored
 *     support@trustpilot.com autoresponder -> ~80 "Auto-reply: Please use
 *     our contact form" guest tickets/day, each spawning a ticket-runner
 *     session (46% of a day's LLM token spend).
 *
 * RFC 3834 §2: an automatic responder MUST NOT reply to an automatic
 * response. WHMCS violates this by acking autoresponders. This hook enforces
 * the standard from PM's side: an inbound auto-reply is closed at ingress
 * WITHOUT any customer-facing reply, so WHMCS emits nothing back to the
 * autoresponder and the loop cannot form.
 *
 * WHAT THIS HOOK DOES
 * -------------------
 * On TicketOpen (new ticket, any source) and TicketUserReply, if the ticket
 * is a GUEST ticket (userid==0) whose SUBJECT carries a standard, START-
 * ANCHORED auto-reply marker, it sets status=Closed via a direct Capsule
 * UPDATE on tbltickets -- exactly like autoclose_blacklisted_tickets.php,
 * which bypasses AddTicketReply so NO customer-visible reply is posted and
 * NO customer email is triggered by WHMCS. An internal (non-client-visible)
 * note is written for audit.
 *
 * ZERO FALSE POSITIVES (the design requirement)
 * ---------------------------------------------
 *   1. userid==0 guard: only GUEST tickets. Any account-holder (real
 *      customer) is never touched — they have a userid.
 *   2. START-ANCHORED standard auto-reply subject prefixes only. These are
 *      machine-generated mail-client conventions (Auto-reply:, Automatic
 *      reply:, Out of Office) + the unmonitored-mailbox notice. A human does
 *      not title a support request "Auto-reply:". Start-anchoring excludes a
 *      forwarded/quoted "Fwd: Auto-reply ...".
 *   3. Reopen-safe: closing a ticket is reversible; a real sender can reply
 *      to reopen. (autoclose_blacklisted_tickets.php uses the identical
 *      reversible mechanism.)
 *   4. PRINCIPLE-BASED, not an enumerated per-vendor list (no "trustpilot"
 *      literal). It matches the SHAPE of an RFC-3834 auto-reply, so it covers
 *      Trustpilot, mailer-daemon, OOO, and any future autoresponder without
 *      edits. Enumerating senders would be the REGEX_FOR_SEMANTICS antipattern;
 *      the standard subject markers ARE the enumerable, deterministic signal.
 *
 * The markers are kept in sync with the runner-side deterministic pre-filter
 * isAutoCloseable() Pattern 5 (tools/tickets/lib/ticket-runner-queue/triage.php,
 * tested in tools/tickets/tests/test-isautocloseable-autoreply.php, 9/9). This
 * hook is the PERMANENT ingress home; Pattern 5 is the runner-side backstop
 * (memory/lessons/architecture/20260531-blacklist-gate-placement-runner-side-
 * is-the-emergency-fix-web4-whmcs-ingress-is-the-right-permanent-home).
 *
 * DEPLOY
 * ------
 * Copy to the WHMCS install's includes/hooks/ directory on the billing server
 * (web4/web5). Operator-bound: agent has no SSH to billing infra (HG-31).
 * After deploy, VERIFY: watch guest-ticket arrivals from support@trustpilot.com
 * / trust.operations@trustpilot.com STOP (get-tickets.php). If tickets keep
 * arriving, the WHMCS creation-ack fires BEFORE this hook — add the companion
 * EmailPreSend suppression (suppress the ticket auto-ack when the ticket is
 * flagged auto-reply); see the design note in GH #969.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

/**
 * Does this ticket's subject carry a standard RFC-3834 auto-reply marker?
 * Kept in sync with isAutoCloseable() Pattern 5 (triage.php). Principle-based:
 * matches the SHAPE of an autoresponder subject, NOT enumerated senders.
 *
 * @param int    $userid  0 == guest (no account)
 * @param string $subject
 * @return bool
 */
function pm_is_autoresponder_ticket($userid, $subject) {
    if ((int) $userid !== 0) {
        return false; // real customers have an account; never auto-close them
    }
    $subject = html_entity_decode((string) $subject, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return (bool) preg_match(
        '/^(Auto-?reply|Automatic reply|Out of( the)? Office|This (e-?mail|address) is no longer monitored)\b/i',
        $subject
    );
}

/**
 * Silently close a ticket. Direct Capsule UPDATE on tbltickets bypasses
 * AddTicketReply, so no customer-visible reply is posted and no customer
 * email is triggered by WHMCS -- this is what breaks the autoresponder loop.
 *
 * @param int    $ticketid
 * @param string $event    'TicketOpen' or 'TicketUserReply'
 */
function pm_autoclose_autoresponder($ticketid, $event) {
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
            'admin'    => 'Auto-close (RFC-3834 auto-reply)',
            'message'  => sprintf(
                'AUTOCLOSE: guest ticket with RFC-3834 auto-reply subject marker. '
                . 'Event=%s. Closed silently (no reply) to prevent the WHMCS '
                . 'ack -> autoresponder mail loop. Reopen-safe.',
                $event
            ),
        ]);
    } catch (\Exception $e) {
        // Fail closed: never disrupt ticket flow on a DB error.
        error_log(sprintf(
            'autoclose_autoresponder_tickets: close failed tid=%d event=%s: %s',
            $ticketid, $event, $e->getMessage()
        ));
        return;
    }

    if (function_exists('logActivity')) {
        logActivity(sprintf(
            'autoclose_autoresponder_tickets: %s tid=%d (RFC-3834 auto-reply)',
            $event, (int) $ticketid
        ));
    }
}

/**
 * Hook: TicketOpen -- fires after a new ticket is created (panel, email, API).
 * $vars: ticketid, userid, deptid, subject, message, priority, status, ...
 */
add_hook('TicketOpen', 1, function ($vars) {
    if (!pm_is_autoresponder_ticket($vars['userid'] ?? 0, $vars['subject'] ?? '')) {
        return;
    }
    pm_autoclose_autoresponder((int) ($vars['ticketid'] ?? 0), 'TicketOpen');
});

/**
 * Hook: TicketUserReply -- WHMCS reopens a closed ticket on user reply.
 * An autoresponder that keeps replying to its own closed thread would reopen
 * it; re-close so the loop stays broken. Subject travels with the ticket.
 * $vars: ticketid, userid, subject, message, ...
 */
add_hook('TicketUserReply', 1, function ($vars) {
    if (!pm_is_autoresponder_ticket($vars['userid'] ?? 0, $vars['subject'] ?? '')) {
        return;
    }
    pm_autoclose_autoresponder((int) ($vars['ticketid'] ?? 0), 'TicketUserReply');
});
