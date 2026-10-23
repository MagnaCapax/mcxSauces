# ADR-0003: The blacklist group refuses new orders at checkout, and the per-customer cap has one answer for checkout and transfers

## Metadata
- Author: Väinämöinen
- Date: 2026-10-23
- Status: Accepted

## Context
Staff put clients they have banned (for example, payment dispute filers) into one WHMCS client group, the
blacklist group. Until now only the ticket hook (`autoclose_blacklisted_tickets.php`) read that group. The checkout
hooks did not, so a banned client could still place and pay for new orders. `payer_blocklist.php` matched only its
salted list of payer identities, and banned clients without a list entry passed.

`product_per_client_cap.php` limits some products to N per customer at checkout. Customers can also move a service
to another customer (a transfer, handled by separate code). Transfers did not ask the cap, so one account could end
up holding more of a capped product than checkout sells.

A scheduled job that re-provisions services for clients whose recurring payment still arrives deliberately serves
clients in the blacklist group too. Any ban therefore has to sit before payment, not at provisioning.

## Options Considered
- **Refuse at provisioning (PreModuleCreate) for the blacklist group.** Catches admin and API orders too, but it
  fires after payment and stops the deliberate re-provisioning job. Rejected.
- **Feed every banned client into the payer list automatically.** The list's provisioning check would then stop the
  same job. On disputes about a hijacked account it would also list the victim. Rejected.
- **Refuse every checkout by a blacklisted client.** That would also refuse carts that only renew or pay for what
  the client already has, and paying for existing service must stay possible. Rejected.
- **Refuse checkout when a blacklisted client's cart holds a new product or addon line** (chosen).
- **A transfer-side copy of the cap rule.** It could drift from checkout. Rejected in favour of one shared function.

## Decision
- `payer_blocklist.php` refuses checkout, with its generic message, when the client is in `PM_BLACKLIST_GROUP_ID`
  (9, the id the ticket hook uses) and the cart holds a product or addon line. The decision is the pure function
  `pm_pbl_group_blocks()`. It runs before the list file is loaded, so a missing list does not switch the ban off.
  Renewal-only carts pass. Provisioning is unchanged.
- `product_per_client_cap.php` exposes `pm_ppc_held()` and `pm_ppc_at_cap()`. Checkout uses `pm_ppc_held()`, and
  callers outside the cart, such as transfers, ask `pm_ppc_at_cap()`. Both give the same answer from the same config
  and the same counted statuses (Pending, Active, Suspended).
- Failure mode stays as before: a missing config or an exception allows the order. The checkout handler now writes
  the swallowed exception to `error_log`, so fail-open is visible.

## Consequences
- A banned client can no longer buy new products through the cart or anything that runs the same checkout hooks.
  Renewals, invoice payments and the re-provisioning job are unaffected.
- Orders created by staff in the admin area are still not checked, by design (staff exceptions).
- A banned person who opens a new account with different details is not caught by the group. The payer list remains
  the tool for that.
- Tests: `php whmcs/hooks/tests/blacklist-and-cap-test.php` (the group rule and the shared cap answer, no database).

## References
- ADR-0001 (layout and languages).
