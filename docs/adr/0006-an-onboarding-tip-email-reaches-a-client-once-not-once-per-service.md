# ADR-0006: An onboarding tip email reaches a client once, not once per service

## Metadata
- Author: Väinämöinen
- Date: 2026-10-23
- Status: Accepted

## Context
The onboarding tips ("Did you know?" emails) are sent by WHMCS Email Marketer rules of the product type. A rule
fires for every service that is exactly N days old, and Email Marketer sends one email per matching service. A
client who orders many services on one day receives the same tip once per service that morning, and again on the
following days for services ordered a day later. The email log confirmed it in October 2026: the number of copies
a client received equalled the number of their services on the rule day. The tips talk about the account, so one
copy is the right number.

## Options Considered
- **An `EmailPreSend` hook that aborts a repeat of the same tip to the same client.** Keeps the rules, their
  timing and their consent handling; only removes repeats. Chosen.
- **Client-type rules.** These trigger on the client's signup age, so a long-time customer's new service would
  never get the tips. Rejected.
- **Move the tips to a per-client sender outside Email Marketer.** Removes the cause; a larger change to the
  onboarding schedule. Recorded as the follow-up, not done here.
- **Repeat suppression for the same day only.** Leaves the same tip arriving on consecutive days. Rejected.

## Decision
- `whmcs/hooks/onboardingTipOncePerClient.php` acts only on the tip templates named in its list. For one of
  them it aborts the send when the service's client already received the same tip in this run or within 30 days
  (the email log, same subject, merge fields matching anything). 30 days is the span of the tip rules.
- Templates shared by two rules on purpose (a trial follow-up sent twice) stay off the list. Welcome, access,
  billing, support and every other email are never looked at.
- Any lookup error sends the email (fail-open): the hook can only remove a repeat, never a first copy.

## Consequences
- A client receives each tip once in 30 days however many services they order. Single-service clients see no
  change.
- An aborted send leaves no email-log row and one activity-log line, so it never counts as received.
- A tip sent by hand within 30 days of the last one is aborted too; send it as a custom message instead. A
  renamed tip template drops out of the list and is sent per service again until the list is updated.
- Tests: `php whmcs/hooks/tests/onboardingTipOncePerClientTest.php`.

## References
- `cancel_addfunds_invoices.php` and `autoclose_autoresponder_tickets.php`: the same `EmailPreSend` abort pattern.
