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
 * (web4/web5) via root SSH. This is AGENT-DOABLE, not operator-bound: HG-31
 * denies infra SSH only in RUNNER mode; INTERACTIVE sessions have root SSH to
 * web5 (verified 2026-07-22). The earlier "operator-bound, no SSH (HG-31)"
 * note here was FALSE — a fabricated-resource-blocker (see the lesson at
 * memory/lessons/agent/20260722-fabricated-resource-blocker-recurrence-*).
 *
 * SCOPE — this hook fixes the token COST, NOT the mail loop:
 *   - TicketOpen/TicketUserReply close guest autoresponder tickets at ingress,
 *     so they spawn no ticket-runner session (the actual token bleed). WORKS.
 *   - The EmailPreSend hook below is INERT on the Trustpilot path: verified
 *     2026-07-22 the loop's outbound (sales@ -> trustpilot) is NOT a WHMCS
 *     email (0 rows in tbltickets/tblemails to trustpilot) and web5 exim is a
 *     `satellite` null-client relaying to smarthost omail.pulsedmedia.com — so
 *     the loop-break belongs at the MAIL SYSTEM (omail) or the injecting
 *     process, NOT at WHMCS or web5-exim. Left in place: harmless, fail-open.
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

/**
 * Hook: EmailPreSend — THE LOOP-BREAK.
 *
 * The TicketOpen close above fires too late: WHMCS has already QUEUED the ticket
 * auto-acknowledgment to the sender by the time the hook closes the ticket
 * (verified 2026-07-22 via exim mainlog — WHMCS kept emailing trust.operations@
 * /support@trustpilot.com AFTER the TicketOpen hook was closing the tickets).
 * That outbound ack is what the unmonitored autoresponder replies to → the loop.
 *
 * RFC 3834 §2: an automatic response MUST NOT be sent to an automatic responder.
 * This hook enforces it: it ABORTS WHMCS's outbound ticket email when the related
 * ticket is an RFC-3834 auto-reply (uid==0 + standard auto-reply subject marker).
 * No ack leaves PM → the autoresponder has nothing to reply to → the loop dies.
 *
 * Zero false positives: only fires for *Ticket* templates AND only when the ticket
 * matches pm_is_autoresponder_ticket() (guest + standard auto-reply subject). A
 * real customer's ticket reply/notification is never suppressed. FAIL-OPEN: any
 * DB/lookup error returns (sends) — a missed suppression is one more harmless loop
 * iteration; suppressing a real customer's email would be the harm, so never do it
 * on uncertainty.
 *
 * $vars: messagename (template), relid (ticket id for ticket templates), mergefields.
 */
add_hook('EmailPreSend', 1, function ($vars) {
    // Only ticket-related templates; zero cost for invoices/other emails.
    if (stripos((string) ($vars['messagename'] ?? ''), 'Ticket') === false) {
        return;
    }
    $ticketid = (int) ($vars['relid'] ?? 0);
    if (!$ticketid) {
        return;
    }
    try {
        $t = Capsule::table('tbltickets')->where('id', $ticketid)->first(['userid', 'subject']);
    } catch (\Exception $e) {
        return; // FAIL-OPEN: never block a legitimate email on a DB error
    }
    if (!$t || !pm_is_autoresponder_ticket($t->userid ?? -1, $t->subject ?? '')) {
        return;
    }
    if (function_exists('logActivity')) {
        logActivity(sprintf(
            'autoclose_autoresponder_tickets: EmailPreSend ABORTED ticket auto-reply to '
            . 'RFC-3834 autoresponder, tid=%d template=%s (loop-break)',
            $ticketid, (string) ($vars['messagename'] ?? '')
        ));
    }
    return ['abortsend' => true];
});
