# ADR-0008: Name plus street address or phone blocks a listed buyer

## Metadata
- Author: Väinämöinen
- Date: 2026-11-01
- Status: Accepted

## Context
`payer_blocklist.php` blocks a new order only when a listed name matches together with the email or the PayPal
payer ID of the same list entry. A name alone only flags, because common names collide.

A banned buyer came back under a new account with the same name, the same street address and the same phone
number, and a new email. The order matched on the name only, so it was flagged and went through. Nothing was paid
or provisioned that time, but only because a separate payment check refused the payment.

## Options Considered
- **Hash the street address line and the phone number into the list entry, and count either as the second
  identifier.** Same salted-hash scheme and the same "name AND a second identifier on the same entry" rule as the
  email and payer ID. Chosen.
- **Block on a name match alone for some entries.** Brings back the common-name false positive the flag rule
  exists to avoid. Rejected.
- **Hash the full postal address (street, postcode, city).** A returning buyer kept the street line and changed the
  postcode, so the full address would miss. Rejected.

## Decision
- Two optional key lists per entry: `a` (street address line, `address1`) and `t` (phone).
- Address key: accents stripped and lowercased as for names, letters and digits kept, every other run of
  characters one space; under 6 characters gives no key.
- Phone key: digits only, the last 8; under 8 digits gives no key. The last 8 digits are the same whether the
  buyer typed a country code, a trunk prefix or neither.
- `pm_pbl_match()` blocks on name AND (email OR payer ID OR address OR phone) on the same entry. Any single
  identifier alone flags, as before. The new arguments are optional, so a four-argument call behaves as before.
- Checkout reads the address and phone from the client record when the buyer is logged in, otherwise from the
  checkout form. Provisioning reads them from the client details WHMCS passes to the hook.
- The list file keeps format version 1; entries without `a` or `t` match as before. An older hook ignores the
  new lists.

## Consequences
- A listed buyer who reuses the name and either the street line or the phone number is refused at checkout and
  stopped at provisioning, whatever email they use.
- A household member or flatmate with the same name pair and the same street line or phone would be refused too.
  The name pair is still required, so this needs both a shared name and a shared address or phone. When a listed
  buyer used someone else's identity, that person is refused too if they later order with their own address or
  phone, as already happens with their email.
- Values are compared after normalization only; if the client record and the checkout form encode a character
  differently, the keys differ and the match is missed (never a false block).
- A buyer who changes the street line, the phone and the email still only flags on the name.
- The salt sits in the list file, and a phone key has only 10^8 possible values, so anyone holding a copy of the
  file can recover the listed phone numbers by trying them all. The file stays outside the web root and readable
  by the web user only; the hashes keep a casual reader from seeing who is listed, not a determined one.
- Tests: `php whmcs/hooks/tests/blacklist-and-cap-test.php` (synthetic data).

## References
- ADR-0003, ADR-0004 (the checkout and provisioning stops this list feeds), ADR-0005 (name parts).
