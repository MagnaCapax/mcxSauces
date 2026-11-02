# ADR-0009: Blacklisted tickets stay visible until notice and closure

## Metadata
- Author: Väinämöinen
- Date: 2026-11-02
- Status: Accepted

## Context
The blacklist hook closed a ticket immediately on TicketOpen or TicketUserReply.
A separate ticket processor selects open and customer-replied tickets, sends a
fixed administrative ban notice once per ticket, and verifies closure. Closing
at ingress bypassed that processor, so a first notice could be missed entirely.

## Options Considered
- Send another notice inside the hook. Rejected: two senders would duplicate
  recipient checks, delivery receipts and legal routing, and could race to send.
- Force In Progress. Rejected: that changes customer-reply priority and can
  change the processor's existing hold decisions.
- Preserve the native status and record the pending disposition. Chosen.

## Decision
- Keep the published hook path and its live blacklist check. Guest attribution
  and the client group are not changed.
- Remove the hook's direct ticket update. Preserve status, lastreply and flags.
  Record only an internal pending note and activity event.
- The configured ticket processor owns fixed notice delivery, recipient binding,
  once-per-ticket delivery evidence, legal/privacy review and verified closure.
  An internal handoff note is never delivery evidence.
- Install this version only alongside that processor. Existing processing holds
  remain binding; preserving a fetchable status does not guarantee eligibility
  or immediate delivery. If the processor is unavailable, the ticket stays pending.

## Consequences
- The hook can no longer hide a new ticket before its first notice is considered.
- A subsequent customer reply is considered through the same delivery history;
  the hook neither resends nor independently closes it.
- This is a source change, not proof of installation or email delivery.

## References
- ADR-0001: standalone hooks and guarded registration.
- WHMCS hook reference: https://developers.whmcs.com/hooks-reference/support/
