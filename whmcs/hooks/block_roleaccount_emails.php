<?php
/**
 * WHMCS Hook: Block client registration with system/technical email addresses.
 *
 * WHY THIS EXISTS
 * ---------------
 * System and technical email addresses (postmaster@, mailer-daemon@,
 * noreply@, root@) are infrastructure mailboxes that belong to automated
 * processes, not individual people. Registrations using these addresses
 * are either fraudulent, automated, or accidental — no human checks
 * mailer-daemon@ for an account activation email.
 *
 * The specific risks these addresses create in WHMCS:
 *
 *   - Bounce loops: mailer-daemon@ and postmaster@ are SMTP system
 *     addresses (RFC 5321). WHMCS auto-replies to tickets and sends
 *     invoices to the registered email. When that email is a bounce
 *     handler, each WHMCS message generates a bounce, which WHMCS
 *     imports as a new ticket, which generates another reply. A single
 *     campaign of 74 recipients has been observed producing 171,000
 *     bounce entries through this loop mechanism.
 *
 *   - Undeliverable communications: noreply@, devnull@, and similar
 *     addresses discard incoming mail by design. Account notifications,
 *     password resets, and invoices vanish silently.
 *
 *   - Spam registration: bots cycle through common technical prefixes
 *     against harvested domains. Blocking these prefixes eliminates a
 *     class of registrations that never convert to paying customers.
 *
 * IMPORTANT: This hook intentionally does NOT block business role
 * addresses like sales@, billing@, info@, support@, or contact@.
 * These are commonly used as primary email addresses by small business
 * owners and sole traders. Blocking them would reject legitimate
 * paying customers. The line drawn here is between addresses that a
 * human might plausibly monitor (sales@) and addresses that exist
 * purely for automated infrastructure (mailer-daemon@).
 *
 * WHMCS has a built-in "Banned Emails" feature (Clients → Banned
 * Emails) but it operates at the domain level only (e.g., block
 * mailinator.com). It cannot block by local-part prefix across all
 * domains and has no wildcard or regex support. This hook fills that
 * gap for system/technical addresses.
 *
 * WHAT THIS HOOK DOES
 * -------------------
 * On ClientDetailsValidation (fires before adding or updating a
 * client), extracts the local part of the submitted email address
 * and checks it against a configurable blocklist of system/technical
 * prefixes. If matched and no client session exists (new registration),
 * returns a validation error that prevents account creation.
 *
 * Existing clients editing their profile are not affected — the hook
 * checks for an active WHMCS client session ($_SESSION['uid']) and
 * skips validation if one exists. This means a client who originally
 * registered with a now-blocked address can still update their other
 * profile fields without being locked out.
 *
 * The default blocklist targets three categories:
 *   1. SMTP infrastructure (RFC 5321): postmaster, mailer-daemon
 *   2. System/root accounts: root, daemon, hostmaster, webmaster
 *   3. Automation/discard: noreply variants, devnull, bounce handlers
 *
 * Deploy to: <whmcs_root>/includes/hooks/block_roleaccount_emails.php
 *
 * Made for pulsedmedia.com
 *
 * SPDX-License-Identifier: Apache-2.0
 *
 * @author    Aleksi Ursin <aleksi@magnacapax.fi>
 * @copyright 2026 Magna Capax Finland Oy
 * @license   Apache-2.0
 */

/**
 * Blocked local-part prefixes (case-insensitive, exact match).
 *
 * Only system/technical/infrastructure addresses — NOT business roles.
 * A human might use sales@ or billing@ as their real email. Nobody uses
 * mailer-daemon@ or devnull@ as a personal address.
 *
 * Override by defining PM_BLOCKED_EMAIL_PREFIXES before this file loads.
 * To extend without replacing the defaults:
 *
 *   define('PM_BLOCKED_EMAIL_PREFIXES', array_merge(
 *       PM_DEFAULT_BLOCKED_EMAIL_PREFIXES, ['extra-prefix']
 *   ));
 *
 * Default categories:
 *
 *   SMTP infrastructure (RFC 5321):
 *     postmaster, mailer-daemon, mailerdaemon, maildaemon
 *
 *   DNS/network infrastructure (RFC 2142 §4-5):
 *     hostmaster, abuse, noc, webmaster, www, ftp, usenet, news, uucp
 *
 *   System accounts:
 *     root, daemon, sysadmin, administrator
 *
 *   No-reply / automation:
 *     noreply, no-reply, do-not-reply, donotreply, no_reply
 *
 *   Discard / black hole:
 *     devnull, /dev/null, null, blackhole, trash, discard
 *
 *   Bounce handling:
 *     bounce, bounces
 *
 *   Security/spam reporting:
 *     spam, phish, phishing
 *
 *   Other non-personal:
 *     undisclosed-recipients, unsubscribe
 *
 *   Protocol services:
 *     smtp, imap, pop, pop3, dns, ssl, tls
 */
if (!defined('PM_DEFAULT_BLOCKED_EMAIL_PREFIXES')) {
    define('PM_DEFAULT_BLOCKED_EMAIL_PREFIXES', [
        // SMTP infrastructure (RFC 5321 — bounce-loop risk)
        'postmaster', 'mailer-daemon', 'mailerdaemon', 'maildaemon',

        // DNS and network infrastructure (RFC 2142 §4-5)
        'hostmaster', 'abuse', 'noc',
        'webmaster', 'www', 'ftp', 'usenet', 'news', 'uucp',

        // System accounts
        'root', 'daemon', 'sysadmin', 'administrator',

        // No-reply and automation senders
        'noreply', 'no-reply', 'do-not-reply', 'donotreply', 'no_reply',

        // Discard / black-hole addresses
        'devnull', 'null', 'blackhole', 'trash', 'discard',

        // Bounce handling
        'bounce', 'bounces',

        // Security and spam reporting
        'spam', 'phish', 'phishing',

        // Mailing infrastructure
        'unsubscribe', 'undisclosed-recipients',

        // Protocol service accounts
        'smtp', 'imap', 'pop', 'pop3', 'dns', 'ssl', 'tls',
    ]);
}

/**
 * Validation error message shown to the registering user.
 *
 * Override to customize for your site's language or tone:
 *   define('PM_BLOCKED_EMAIL_MESSAGE', 'Your custom message here.');
 */
if (!defined('PM_BLOCKED_EMAIL_MESSAGE')) {
    define('PM_BLOCKED_EMAIL_MESSAGE',
        'The email address provided appears to be a system or '
        . 'technical address (e.g., postmaster@, noreply@, root@). '
        . 'Please register with a personal or business email address.'
    );
}

add_hook('ClientDetailsValidation', 1, function ($vars) {
    // Logged-in client editing their profile — allow through.
    // $_SESSION['uid'] is set by WHMCS for authenticated client sessions.
    // This ensures existing clients are never blocked from updating their
    // details, even if they originally registered with a now-blocked address.
    if (!empty($_SESSION['uid'])) {
        return [];
    }

    $blocked = defined('PM_BLOCKED_EMAIL_PREFIXES')
        ? PM_BLOCKED_EMAIL_PREFIXES
        : PM_DEFAULT_BLOCKED_EMAIL_PREFIXES;

    $email = strtolower(trim($vars['email'] ?? ''));
    $localPart = strstr($email, '@', true);

    if ($localPart !== false && in_array($localPart, $blocked, true)) {
        return [PM_BLOCKED_EMAIL_MESSAGE];
    }

    return [];
});
