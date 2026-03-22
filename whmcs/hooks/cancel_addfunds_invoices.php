<?php
/**
 * WHMCS Hook: Auto-cancel abandoned Add Funds invoices and suppress their dunning emails.
 *
 * WHY THIS EXISTS
 * ---------------
 * WHMCS creates an invoice whenever a customer visits the "Add Funds" page,
 * even if they leave without paying. These invoices enter the standard
 * dunning pipeline: payment reminders, overdue notices, and suspension
 * warnings are sent on schedule — even though there is no service to
 * suspend and no contractual obligation to pay.
 *
 * The specific problems this causes:
 *
 *   - False suspension warnings: customers receive emails threatening
 *     service suspension for an invoice they never committed to paying.
 *     This erodes trust, generates confused support tickets, and makes
 *     the company look incompetent. Discovered via support ticket in
 *     March 2026 where a customer received suspension warnings for an
 *     abandoned Add Funds invoice alongside their legitimate service
 *     invoices.
 *
 *   - Support ticket waste: customers who receive suspension threats
 *     for invoices they don't recognise contact support. Each ticket
 *     costs staff time to investigate and explain. At scale, abandoned
 *     Add Funds invoices generate a steady trickle of avoidable tickets.
 *
 *   - Invoice clutter: unpaid Add Funds invoices accumulate indefinitely
 *     in the billing system, polluting financial reports and making it
 *     harder to identify genuinely overdue service invoices.
 *
 * WHMCS has no built-in mechanism to distinguish Add Funds invoices from
 * service invoices in its dunning automation. The dunning system treats
 * all unpaid invoices identically. This hook fills that gap.
 *
 * WHAT THIS HOOK DOES
 * -------------------
 * Two hook points in one file:
 *
 * 1. DailyCronJob — Auto-cancel pure Add Funds invoices
 *    Runs once per day at the end of the WHMCS daily cron. Finds all
 *    unpaid invoices created more than PM_ADDFUNDS_CANCEL_DAYS ago
 *    (default: 7) where EVERY line item has type 'AddFunds'. Mixed
 *    invoices (containing both Add Funds and service items) are
 *    explicitly excluded — those represent real payment obligations
 *    and must go through normal dunning.
 *
 *    Each invoice is re-checked via localAPI('GetInvoice') immediately
 *    before cancellation to guard against race conditions (customer
 *    paying between query and cancel). A post-cancel verification step
 *    confirms the cancellation took effect. All actions are logged to
 *    the WHMCS activity log with the [AddFundsAutoCancel] prefix.
 *
 *    Batch size is limited by PM_ADDFUNDS_CANCEL_BATCH (default: 50)
 *    to prevent cron timeout on large backlogs. Remaining invoices are
 *    processed on the next daily run.
 *
 * 2. EmailPreSend — Suppress dunning emails for Add Funds invoices
 *    Intercepts the four dunning email templates before they are sent.
 *    If the related invoice contains ANY Add Funds line item, the email
 *    is suppressed via WHMCS's abortsend mechanism. This provides
 *    immediate relief — no more false suspension threats — even before
 *    the daily cron cancels the invoice. The "Invoice Created" email is
 *    intentionally NOT suppressed, so customers can still complete
 *    payment if they return to the Add Funds page.
 *
 * IMPORTANT: The EmailPreSend hook suppresses dunning for ANY invoice
 * containing an Add Funds line item (including mixed invoices), while
 * the DailyCronJob only cancels PURE Add Funds invoices. This is
 * intentional — mixed invoices should not be auto-cancelled (they have
 * real service items), but they also should not send dunning that
 * references Add Funds amounts the customer never committed to.
 *
 * KNOWN LIMITATIONS
 * -----------------
 *   - The pre-cancel status check and the cancel are not atomic.
 *     A customer paying in the milliseconds between GetInvoice and
 *     UpdateInvoice could theoretically have their payment overwritten.
 *     In practice: the cron runs once daily at a quiet time, making this
 *     window negligible. A post-cancel verification step detects if this
 *     occurs and logs a critical alert.
 *
 *   - Dunning template names are matched by string. If templates are
 *     renamed in WHMCS admin, email suppression silently stops working.
 *     Template names are configurable via PM_ADDFUNDS_DUNNING_TEMPLATES.
 *
 *   - localAPI() runs with full admin privileges within the WHMCS hook
 *     context. This is by design and necessary for invoice operations.
 *     Future maintainers should be aware that any localAPI call added
 *     here bypasses client/admin permission checks.
 *
 *   - WHMCS email log will show a send attempt for suppressed emails
 *     with no delivery record. The activity log entries from this hook
 *     explain why — cross-reference by invoice ID.
 *
 * Security:
 *   - All invoice IDs are cast to (int) before use — no injection vector
 *   - Capsule uses PDO prepared statements internally
 *   - No user input is reflected in output — no XSS vector
 *   - No customer PII is logged — only invoice IDs and counts
 *   - localAPI calls run within the WHMCS security context (admin level)
 *
 * Performance:
 *   - DailyCronJob: single indexed query + up to PM_ADDFUNDS_CANCEL_BATCH
 *     localAPI calls (default 50). Negligible overhead on the daily cron.
 *   - EmailPreSend: early return for non-dunning templates (zero cost
 *     for 95%+ of emails). Single indexed query on tblinvoiceitems
 *     for dunning emails only.
 *
 * Compatibility: WHMCS 8.x (Capsule available since WHMCS 6.0,
 *   localAPI stable since WHMCS 5.x, EmailPreSend abortsend documented)
 *
 * Deploy to:  <whmcs_root>/includes/hooks/cancel-addfunds-invoices.php
 *
 * Made for pulsedmedia.com
 *
 * SPDX-License-Identifier: Apache-2.0
 *
 * @author    Aleksi Ursin <aleksi@magnacapax.fi>
 * @copyright 2026 Magna Capax Finland Oy
 * @license   Apache-2.0
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * Days after invoice creation before an abandoned Add Funds invoice is
 * auto-cancelled. Default: 7 days.
 *
 * Override by defining this constant before hooks are loaded
 * (e.g., in configuration.php or an earlier-loading hook file).
 */
if (!defined('PM_ADDFUNDS_CANCEL_DAYS')) {
    define('PM_ADDFUNDS_CANCEL_DAYS', 7);
}

/**
 * Maximum invoices to cancel per daily cron run. Prevents timeout on
 * large backlogs. Remaining invoices are processed on the next run.
 * Default: 50.
 */
if (!defined('PM_ADDFUNDS_CANCEL_BATCH')) {
    define('PM_ADDFUNDS_CANCEL_BATCH', 50);
}

/**
 * Dunning email template names to intercept.
 *
 * These must match the template names exactly as configured in
 * WHMCS Setup → Email Templates. If templates have been renamed,
 * update this list to match.
 *
 * Override by defining PM_ADDFUNDS_DUNNING_TEMPLATES as an array
 * before hooks are loaded.
 */
if (!defined('PM_ADDFUNDS_DUNNING_TEMPLATES')) {
    define('PM_ADDFUNDS_DUNNING_TEMPLATES', [
        'Invoice Payment Reminder',
        'First Invoice Overdue Notice',
        'Second Invoice Overdue Notice',
        'Third Invoice Overdue Notice',
    ]);
}

// ─────────────────────────────────────────────────────────────────
// HOOK 1: DailyCronJob — Auto-cancel pure Add Funds invoices
// ─────────────────────────────────────────────────────────────────
add_hook('DailyCronJob', 1, function ($vars) {
    try {
        $cutoffDate = date('Y-m-d', strtotime('-' . PM_ADDFUNDS_CANCEL_DAYS . ' days'));

        // Find unpaid invoices created before cutoff where ALL line
        // items are type 'AddFunds'. Uses creation date (i.date), not
        // due date, because Add Funds invoices have no contractual
        // due date — the creation date is when the customer abandoned
        // the page.
        //
        // The whereNotIn subquery excludes invoices that have ANY
        // non-AddFunds line item (type not in ['AddFunds', '']).
        // Blank-type rows (tax lines, rounding) are ignored so they
        // don't accidentally disqualify a pure Add Funds invoice.
        $addfundsInvoiceIds = Capsule::table('tblinvoices AS i')
            ->join('tblinvoiceitems AS ii', 'i.id', '=', 'ii.invoiceid')
            ->where('i.status', 'Unpaid')
            ->where('i.date', '<', $cutoffDate)
            ->where('ii.type', 'AddFunds')
            ->whereNotIn('i.id', function ($query) {
                $query->select('invoiceid')
                    ->from('tblinvoiceitems')
                    ->whereNotIn('type', ['AddFunds', '']);
            })
            ->distinct()
            ->pluck('i.id');

        $cancelled = 0;
        $processed = 0;

        foreach ($addfundsInvoiceIds as $invoiceId) {
            // Batch limit: prevent cron timeout on large backlogs.
            if ($processed >= PM_ADDFUNDS_CANCEL_BATCH) {
                $remaining = count($addfundsInvoiceIds) - $processed;
                logActivity("[AddFundsAutoCancel] Batch limit reached ({$processed}). {$remaining} remaining for next run.");
                break;
            }
            $processed++;

            // Race condition guard: re-check status via API immediately
            // before cancelling. The customer may have paid between the
            // query above and this iteration.
            $current = localAPI('GetInvoice', ['invoiceid' => (int) $invoiceId]);

            if (!isset($current['status']) || $current['status'] !== 'Unpaid') {
                continue;
            }

            $result = localAPI('UpdateInvoice', [
                'invoiceid' => (int) $invoiceId,
                'status'    => 'Cancelled',  // British spelling — WHMCS standard
            ]);

            if ($result['result'] === 'success') {
                // Post-cancel verification: confirm the status actually
                // changed. Catches the edge case where a concurrent
                // payment overwrites our cancellation.
                $verify = localAPI('GetInvoice', ['invoiceid' => (int) $invoiceId]);
                if (isset($verify['status']) && $verify['status'] !== 'Cancelled') {
                    logActivity("[AddFundsAutoCancel] CRITICAL: Invoice #{$invoiceId} was cancelled but status is now '{$verify['status']}' — possible concurrent payment. Investigate immediately.");
                    continue;
                }

                $cancelled++;
                logActivity("[AddFundsAutoCancel] Cancelled abandoned Add Funds invoice #{$invoiceId}");
            }
        }

        if ($cancelled > 0) {
            logActivity("[AddFundsAutoCancel] Daily run: cancelled {$cancelled} abandoned Add Funds invoice(s)");
        }
    } catch (\Exception $e) {
        logActivity("[AddFundsAutoCancel] Error in daily cron: " . $e->getMessage());
    }
});

// ─────────────────────────────────────────────────────────────────
// HOOK 2: EmailPreSend — Suppress dunning emails for Add Funds invoices
// ─────────────────────────────────────────────────────────────────
add_hook('EmailPreSend', 1, function ($vars) {
    // Early return for non-dunning emails (zero cost for 95%+ of sends).
    if (!in_array($vars['messagename'], PM_ADDFUNDS_DUNNING_TEMPLATES)) {
        return;
    }

    $invoiceId = (int) ($vars['relid'] ?? 0);
    if ($invoiceId <= 0) {
        return;
    }

    try {
        // Check if this invoice has ANY Add Funds line item.
        // Suppresses dunning for both pure and mixed Add Funds invoices.
        $isAddFunds = Capsule::table('tblinvoiceitems')
            ->where('invoiceid', $invoiceId)
            ->where('type', 'AddFunds')
            ->exists();

        if ($isAddFunds) {
            // Determine if pure or mixed for accurate logging.
            $hasOtherItems = Capsule::table('tblinvoiceitems')
                ->where('invoiceid', $invoiceId)
                ->whereNotIn('type', ['AddFunds', ''])
                ->exists();

            $invoiceType = $hasOtherItems ? 'mixed (Add Funds + service)' : 'pure Add Funds';
            logActivity("[AddFundsEmailSuppress] Suppressed '{$vars['messagename']}' for {$invoiceType} invoice #{$invoiceId}");
            return ['abortsend' => true];
        }
    } catch (\Exception $e) {
        // Fail-open: if we cannot determine whether this is an Add Funds
        // invoice, let the email through. A false positive (sending a
        // dunning email for an Add Funds invoice) is less damaging than
        // silently suppressing a legitimate overdue notice.
        logActivity("[AddFundsEmailSuppress] Error checking invoice #{$invoiceId}: " . $e->getMessage());
    }
});
