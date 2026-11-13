# ADR-0011: Promotion codes: once per customer and a shared cap, enforced at checkout

## Metadata
- Author: Väinämöinen
- Date: 2026-11-13
- Status: Accepted

## Context
WHMCS 9.0.x refuses every existing client at checkout when a promotion has its own "once per client" flag set,
even a client who never used the code. The validation sits in encoded core code, so the cause cannot be read or
fixed there. Promotions that must be used once per customer therefore keep the flag off, and the rule has to be
enforced somewhere else.

One offer can also be sold under several codes (for example one code per payment term), with a limit on the
offer as a whole. WHMCS caps each code on its own (its maximum uses), so three codes of 100 uses each sell up to
300, not 100.

## Options Considered
- **A checkout hook (`ShoppingCartValidateCheckout`) driven by a small JSON config.** Refuses the checkout with a
  message before the order exists; staff orders and the API stay unchecked so staff can make exceptions. Chosen.
- **Keep the WHMCS once-per-client flag.** Refuses customers who are entitled to the code. Rejected.
- **Split the offer's limit across the codes' own maximums (34 + 33 + 33).** One popular code runs out while the
  others still have room, so customers are refused before the offer is sold out. Rejected.
- **Cancel extra orders by hand after the fact.** The customer has already ordered and paid. Rejected.

## Decision
- `whmcs/hooks/promo_once_per_client.php` reads `{"v":1,"codes":[...],"caps":[{"codes":[...],"max":N}]}` from a
  file outside the web root.
- A code in `codes` is refused for a customer who already has a Pending or Active order with it. Guests pass.
- A code in a `caps` group is refused for everyone, guests included, once the group's codes have together been
  used `max` times. Uses are WHMCS's own per-code counters, summed, so a cancelled order does not give its use back
  (the same rule WHMCS applies to a single code's maximum).
- Any error, a missing file or malformed JSON allows the checkout: a bug here never blocks a paying customer.
  The decisions are pure functions so they are tested without WHMCS.

## Consequences
- Once-per-customer codes work for existing customers again, and one offer sold under several codes stops at one
  total.
- Two checkouts in the same second can both pass, at a customer's first use or at the cap's last free use; staff
  can cancel the extra order.
- The rule counts accounts, not people. A config error fails open, so a cap can be exceeded rather than an order
  wrongly refused.

## References
- `whmcs/hooks/promo_once_per_client.php` and its test `whmcs/hooks/tests/promo-once-per-client-test.php`
- ADR-0001 (layout and languages)
