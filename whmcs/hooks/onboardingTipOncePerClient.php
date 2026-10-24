<?php
/**
 * WHMCS Hook: an onboarding tip email reaches a client once, not once per service.
 *
 * WHY THIS EXISTS
 * ---------------
 * The onboarding tips ("Did you know?" emails) are sent by WHMCS Email Marketer rules of the
 * product type: a rule fires for every service that is exactly N days old. Email Marketer sends
 * one email per matching service, so a client who ordered several services on the same day gets
 * one identical copy of the same tip per service that morning, and more copies on the next days
 * for the services ordered a day later. The email log confirmed it in October 2026: the number of
 * copies a client received equalled the number of their services on the rule day. The tip is
 * about the account, not one service; one copy is the right number.
 *
 * Email Marketer has no per-client setting, and a client-type rule would trigger on the client's
 * signup age instead, so a long-time customer's new service would never get the tips. This hook
 * is a compensating control for that per-service behaviour; moving the tips to a per-client
 * sender is the change that removes the cause (ADR-0006).
 *
 * WHAT THIS HOOK DOES
 * -------------------
 * On EmailPreSend, for the tip templates named in PM_ONBOARDING_TIP_TEMPLATES only: find the client of
 * the service the email is for, and abort the send when that client already received the same
 * tip in this run or within the last PM_ONBOARDING_TIP_REPEAT_WINDOW_DAYS days (tblemails, same subject; merge fields
 * in the subject match anything). The window is the span of the tip rules (service day 1 to 30),
 * so a repeat inside it is always the same tip again for the same customer.
 *
 * Every other email (invoices, welcome and access emails, tickets, any template not in the list)
 * returns at the first line. Fail-open: any lookup error sends the email, as before this hook,
 * and writes one activity-log line per PHP process saying the check was skipped and why, so a
 * guard that stopped guarding is visible. An aborted send writes no email-log row, so it never
 * counts as received.
 *
 * Known effects: a tip sent by hand from the admin area to a client who got it in the last 30
 * days is aborted too (send it as a custom message instead). If the first copy of a run is
 * accepted here but then fails to send, the later copies of that run are still aborted. A tip
 * template renamed in WHMCS falls out of the list and is sent per service again. An email from
 * another sender with the identical subject counts as the same tip received. Each abort is
 * written to the activity log with the template and service.
 *
 * Deploy to:  <whmcs_root>/includes/hooks/onboardingTipOncePerClient.php
 * Ownership:  same as the sibling hooks (644)
 * Test:       php whmcs/hooks/tests/onboardingTipOncePerClientTest.php
 *
 * SPDX-License-Identifier: Apache-2.0
 */
declare(strict_types=1);

use WHMCS\Database\Capsule;

if (!defined('PM_ONBOARDING_TIP_TEMPLATES')) {
    define('PM_ONBOARDING_TIP_TEMPLATES', [
        'DidYouKnow 01 - Your control panel',
        'DidYouKnow 02 - Where your files live',
        'DidYouKnow 03 - Where answers live',
        'DidYouKnow 04 - Media stack',
        'DidYouKnow 05 - Prepay and plan changes',
        'DidYouKnow 06 - Trial ending',
        'DidYouKnow onboarding 18d - what support can do',
    ]);
}
if (!defined('PM_ONBOARDING_TIP_REPEAT_WINDOW_DAYS')) {
    define('PM_ONBOARDING_TIP_REPEAT_WINDOW_DAYS', 30);
}

if (!function_exists('pmOnboardingTipSubjectLikePattern')) {
    /** SQL LIKE pattern for a template subject: literal text escaped, each {$merge_field} matches anything. */
    function pmOnboardingTipSubjectLikePattern(string $subject): string
    {
        $parts = preg_split('/\{\$[^}]*\}/', $subject);
        $escaped = array_map(static function (string $part): string {
            return strtr($part, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
        }, $parts === false ? [$subject] : $parts);
        return implode('%', $escaped);
    }
}

if (!function_exists('pmOnboardingTipDecision')) {
    /**
     * 'skip' (not a tip, or no client), 'abort' (this client already has this tip) or 'send'.
     * $seen holds the client|template pairs sent earlier in this PHP process; 'send' adds one.
     * $receivedRecently(int $clientId): bool asks the email log about the last PM_ONBOARDING_TIP_REPEAT_WINDOW_DAYS days.
     */
    function pmOnboardingTipDecision(string $template, int $clientId, array &$seen, callable $receivedRecently): string
    {
        if (!in_array($template, PM_ONBOARDING_TIP_TEMPLATES, true) || $clientId <= 0) {
            return 'skip';
        }
        $key = $clientId . '|' . $template;
        if (isset($seen[$key]) || $receivedRecently($clientId)) {
            return 'abort';
        }
        $seen[$key] = true;
        return 'send';
    }
}

if (!function_exists('pmOnboardingTipSkippedNote')) {
    /** Activity-log line for a tip sent without the check: one line, at most 200 characters of reason. */
    function pmOnboardingTipSkippedNote(string $template, string $reason): string
    {
        $reason = trim((string) preg_replace('/\s+/', ' ', $reason));
        if (strlen($reason) > 200) {
            $reason = substr($reason, 0, 200) . '...';
        }
        return sprintf('onboardingTipOncePerClient: check skipped, "%s" sent without the repeat check (%s);'
            . ' logged once per run', $template, $reason);
    }

    /** Write $note with $log, falling back to error_log; never throws (the database $log uses may be the one that failed). */
    function pmOnboardingTipWriteNote(string $note, callable $log): void
    {
        try {
            $log($note);
            return;
        } catch (\Throwable $e) {
        }
        error_log($note);
    }
}

if (function_exists('add_hook') && defined('ROOTDIR')) {
    add_hook('EmailPreSend', 1, function ($vars) {
        static $seen = [];
        static $skipNoted = false;
        $template = (string) ($vars['messagename'] ?? '');
        if (!in_array($template, PM_ONBOARDING_TIP_TEMPLATES, true)) {
            return;
        }
        $noteSkipped = static function (string $template, string $reason) use (&$skipNoted): void {
            if (!$skipNoted) {
                $skipNoted = true;
                pmOnboardingTipWriteNote(pmOnboardingTipSkippedNote($template, $reason),
                    function_exists('logActivity') ? 'logActivity' : 'error_log');
            }
        };
        $serviceId = (int) ($vars['relid'] ?? 0);
        try {
            $clientId = (int) Capsule::table('tblhosting')->where('id', $serviceId)->value('userid');
            $subject = (string) Capsule::table('tblemailtemplates')->where('name', $template)
                ->where('language', '')->value('subject');
            if ($subject === '') {
                $noteSkipped($template, 'template subject not found');
                return;
            }
            $like = pmOnboardingTipSubjectLikePattern($subject);
            $since = date('Y-m-d H:i:s', time() - PM_ONBOARDING_TIP_REPEAT_WINDOW_DAYS * 86400);
            $decision = pmOnboardingTipDecision($template, $clientId, $seen,
                static function (int $cid) use ($like, $since): bool {
                    return Capsule::table('tblemails')->where('userid', $cid)
                        ->where('subject', 'like', $like)->where('date', '>=', $since)->exists();
                });
        } catch (\Throwable $e) {
            $noteSkipped($template, get_class($e) . ': ' . $e->getMessage());
            return; // fail-open: a lookup problem must never stop an email
        }
        if ($decision !== 'abort') {
            return;
        }
        if (function_exists('logActivity')) {
            logActivity(sprintf('onboardingTipOncePerClient: aborted "%s" for service #%d, client already received it'
                . ' (this run or within %d days)', $template, $serviceId, PM_ONBOARDING_TIP_REPEAT_WINDOW_DAYS), $clientId);
        }
        return ['abortsend' => true];
    });
}
