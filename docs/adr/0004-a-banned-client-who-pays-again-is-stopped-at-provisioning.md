# ADR-0004: A banned client who pays again is stopped at provisioning

## Metadata
- Author: Väinämöinen
- Date: 2026-10-23
- Status: Accepted. Supersedes the provisioning part of ADR-0003.

## Context
ADR-0003 made the blacklist group refuse new orders at checkout and left provisioning alone, because a scheduled
job re-provisions services for clients whose recurring payment still arrives, and that job was meant to serve
banned clients too.

The operator then ruled, on banned clients (2026-10-23): "check upon payment if they try to pay again and then
block them, maybe not even refund, just cancel that account or something like that, they are bypassing and
circumventing limits." Checkout is not the only way a banned client gets a new service. Paying an unpaid invoice of
an order placed before the ban, an order staff create in the admin area, and the re-provisioning job all reach
provisioning without passing checkout.

No WHMCS hook can refuse a payment: the invoice is marked paid before any hook runs. `PreModuleCreate` returning
`abortcmd` is the last point where a new service can still be stopped.

## Options Considered
- **Refuse at provisioning (PreModuleCreate) for the blacklist group.** Catches every path to a new service,
  including admin orders and the re-provisioning job. It runs after payment, so the money stays booked. Chosen:
  the operator's ruling asks for exactly this, and keeping the money matches "maybe not even refund".
- **Checkout only (ADR-0003 as it was).** Leaves the paths above open. Rejected by the ruling.
- **Cancel the order and the account from inside the hook.** Changing order and service status while WHMCS is in
  the middle of a create is risky, and there is no test bed for it. Rejected for now. The internal note records the
  ruling, so staff can cancel.

## Decision
- `payer_blocklist.php` aborts `PreModuleCreate` (`abortcmd`) for a new, non-addon service whose client is in
  `PM_BLACKLIST_GROUP_ID`. The decision is the pure function `pm_pbl_provision_group_blocks()`. The group is read
  live from `tblclients`, and the check runs before the payer list is loaded, so a missing list does not switch it
  off.
- Each block writes an Activity Log line, an audit row and, once per service, an internal client note that quotes
  the ruling and names the way to lift it: move the client out of the group first.
- Addon creates are not checked: they hang off a parent service, and the parent was already refused.
- Failure mode is unchanged: an exception allows provisioning.
- The re-provisioning job skips clients in the blacklist group, so it does not try a create the hook will refuse.

## Consequences
- A banned client who pays again gets no new service, whatever the path. The payment stays booked; the hook
  neither refunds nor cancels anything.
- Renewals and invoice payments for services the client already holds still work: they do not call ModuleCreate.
  Unsuspending a suspended service is not checked, because the hook watches creates only.
- A failed create is retried by WHMCS. The note is written once per service; the Activity Log line repeats.
- Tests: `php whmcs/hooks/tests/blacklist-and-cap-test.php`.
- Update 2026-10-23: the cancel left to staff is now done by a daily job outside WHMCS, so the hook still changes
  no order state. The job cancels the order with no refund through the order-cancel API, then closes the client
  once nothing is left on it. It acts only when every service on the order is Pending, the invoice is Paid and the
  block note is dated no later than one day after the payment. A later note means a create was retried after a
  later ban, so the payment may predate the ban; that order is left alone.

## References
- ADR-0003 (checkout refusal; its provisioning part is superseded here).
