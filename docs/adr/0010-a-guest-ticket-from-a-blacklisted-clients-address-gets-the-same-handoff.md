# ADR-0010: A guest ticket from a blacklisted client's address gets the same handoff

## Metadata
- Author: Väinämöinen
- Date: 2026-11-04
- Status: Accepted

## Context
The blacklist hook (ADR-0009) checked only the event's `userid`. A closed account
cannot log in, so after closure its tickets arrive by email as guest tickets
(`userid` 0). The hook never saw them: a blacklisted client's new ticket and its
reply carried no handoff note, while the same client's reply on an old ticket
it owned did.

## Options Considered
- Leave guests to the ticket processor. Rejected: the hook is the only check
  that runs at ingress, and the audit note was missing for exactly the tickets
  a closed, blacklisted account sends.
- Reassign the guest ticket to the matching client. Rejected: attribution
  changes what the requester can see and is not this hook's decision.
- Check the guest ticket's sender address against client emails. Chosen.

## Decision
- For a guest event, read the ticket's sender address and look for clients with
  that email, of any status. Exactly one match is the client to check; zero or
  several matches, or any lookup error, mean no note.
- Everything else is ADR-0009 unchanged: an internal pending note and activity
  event only, no status, lastreply, flag or attribution change, no reply.

## Consequences
- A blacklisted client's guest tickets get the same handoff note as its own.
- An address shared by several accounts is never treated as proof.
- The note is still not delivery evidence; the processor owns the notice.

## References
- ADR-0009: blacklisted tickets stay visible until notice and closure.
- WHMCS hook reference: https://developers.whmcs.com/hooks-reference/support/
