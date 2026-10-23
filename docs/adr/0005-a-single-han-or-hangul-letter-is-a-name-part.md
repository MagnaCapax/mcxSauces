# ADR-0005: A single Han or Hangul letter is a name part

## Metadata
- Author: Väinämöinen
- Date: 2026-10-23
- Status: Accepted

## Context
`payer_blocklist.php` matches names on pairs of name parts and drops every part shorter than two letters, so that
initials ("J. R.") do not turn into keys. In Han script a surname is usually one letter, and a given name one or
two, written as two parts. Those names kept one part or none, made no pair keys, and the list tooling refused to
list them. Five payment-dispute filers could not be listed for this reason.

With PHP's `Normalizer` present, a Hangul syllable decomposes into two or three jamo before the length check, so
Korean names already passed; without it, a one-syllable surname was dropped too.

## Options Considered
- **Keep a single letter when it is Han or Hangul.** One syllable, the usual surname length in those scripts. The
  pair rule stays: a lone surname still makes no key, and a name alone still never blocks. Chosen.
- **Split a Han part written without spaces into surname and given name.** Guesses where the surname ends, which is
  wrong for two-letter surnames. Rejected.
- **Keep every single letter.** Brings initials back as keys, which collide often. Rejected.

## Decision
- `pm_pbl_name_tokens()` keeps a part of two or more letters, or a part that is exactly one Han or Hangul letter.
- Keys change only for names that contain a single Han or Hangul letter. Pairs that existed before are unchanged,
  so list entries built under the old rule keep matching.

## Consequences
- Han names written as surname and given name can be listed and matched.
- A Han name written as one part ("王小明") still makes no key. A checkout name typed in Latin letters does not
  match a list entry written in Han; the provisioning check, which reads the payer's own name, still can.
- Tests: `php whmcs/hooks/tests/blacklist-and-cap-test.php` (synthetic names).

## References
- ADR-0004 (the after-payment stop that the list also feeds).
