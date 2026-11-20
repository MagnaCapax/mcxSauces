# ADR-0013: A promotion code can allow several orders per customer

## Metadata
- Author: Väinämöinen
- Date: 2026-11-20
- Status: Accepted
- Amends: ADR-0011, ADR-0012

## Context
ADR-0011 limits a listed promotion code to one order per customer. Some offers allow a few per customer, for
example "at most 5 per customer". The WHMCS code settings cannot say that: its own once-per-client flag is unusable
(ADR-0011), and the code's maximum uses counts orders across all customers, not per customer.

With the code set to discount one item per order (WHMCS "apply once"), "N per customer" is the same as "N orders
with this code per customer", which the hook already counts.

## Options Considered
- **A per-code limit in the same config file (`"limits": {"CODE": N}`), counted with the existing order count.**
  One new config key, the same decision, cart and checkout paths. Chosen.
- **Count services instead of orders, including the items in the cart.** Allows several discounted items in one
  order, but needs the promotion's product list, cycles and the cart contents. More code for the same limit.
  Rejected for now.
- **A per-product cap.** Would also limit customers who buy the product at the normal price. Rejected.

## Decision
- `limits` is optional. Each entry maps a code to a whole number of at least 1. Other values are dropped.
- A code in `limits` is enforced as if it were in `codes`. The cart and checkout refuse it once the customer holds
  that many Pending or Active orders with it. A code in `codes` without an entry keeps the limit of one.
- For a limit above one, the message names the limit and how many orders the customer already has.
- Guests, errors and shared caps behave as before.

## Consequences
- "N per customer" is exact only when the code discounts one item per order. A code that discounts several items in
  one order can give a customer more than N discounted items.
- A cancelled order frees one of the customer's N, as under ADR-0011.
- Configs without `limits` behave exactly as before.

## References
- `whmcs/hooks/promo_once_per_client.php` and its test `whmcs/hooks/tests/promo-once-per-client-test.php`
- ADR-0011, ADR-0012
