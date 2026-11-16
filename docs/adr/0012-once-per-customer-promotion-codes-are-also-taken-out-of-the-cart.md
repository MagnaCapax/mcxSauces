# ADR-0012: Once-per-customer promotion codes are also taken out of the cart

## Metadata
- Author: Väinämöinen
- Date: 2026-11-16
- Status: Accepted
- Amends: ADR-0011

## Context
ADR-0011 refuses a once-per-customer code only at checkout. Until then the cart accepts the code and shows its
discount, so a customer who already used the code sees a discounted second item, and only pressing checkout
tells them no. A customer reported exactly that as "the limit does not work". A limit the customer can only
discover at the last step reads as broken, even when checkout would have refused it.

WHMCS runs the `PreCalculateCartTotals` hook at the start of working out the cart total, before it reads the
cart's promotion code, and merges the array a `ClientAreaPageCart` hook returns into the cart page's template
variables, where the cart template shows a promotion error.

## Options Considered
- **Take the code out of the cart in `PreCalculateCartTotals` and show the refusal message on the cart page.** The
  cart never shows a discount checkout would refuse. Chosen.
- **Add a negative cart adjustment that cancels the discount.** More code, and the discount line still shows.
  Rejected.
- **Keep refusing at checkout only.** The behaviour customers report as broken. Rejected.

## Decision
- For a logged-in customer, the hook applies the ADR-0011 rule whenever the cart total is worked out: a code they
  already hold (Pending or Active order with it) is removed from the cart and an Activity Log line is written.
- The next cart page shows the same message as the checkout refusal, once.
- The checkout refusal stays, for a customer who logs in only at checkout. Shared caps stay checkout-only.
- Guests and any error behave as before: nothing is removed.

## Consequences
- An existing customer no longer sees a discount they cannot get.
- A customer who logs in after adding the code sees the code disappear on the next cart view, with the message.
- If a WHMCS version stops calling either hook, behaviour falls back to ADR-0011 (refused at checkout).

## References
- `whmcs/hooks/promo_once_per_client.php` and its test `whmcs/hooks/tests/promo-once-per-client-test.php`
- ADR-0011
