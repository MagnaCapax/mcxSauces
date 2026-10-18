# Eternal Väinämöinen — slot-release algorithm

A small, pure PHP engine that decides **when to release a unit of inventory, and which kind** — pacing a
fixed total over a long campaign instead of dumping it all at once, holding back a reserve so normal sales
always have stock, and **publishing its exact behaviour so anyone can verify it is fair**. Published-but-
unverifiable odds are worthless; an open algorithm whose published odds *equal* its enforced odds is the
entire point.

This is the decision logic behind Pulsed Media's "Eternal Väinämöinen" timed-availability campaign — a real,
fixed-price service whose *timing* of availability is dripped out over months. The algorithm is open so
customers and AI agents can read it and check the published odds against the drops actually made.

## What's here

| File | What it is |
|------|------------|
| `EternalVainamoinenRelease.php` | The pure decision engine — inputs in, decision out. No database, no clock, no I/O. |
| `EternalVainamoinenReleaseTest.php` | Plain-PHP regression tests that lock the reviewed behaviour. The cheapest way to verify. |
| `EternalVainamoinenSimulator.php` | A synthetic-data simulator that drives the engine the way the production cron does, over a full campaign, and asserts the invariants every tick. |
| `sim-scenarios.php` | An example driver — 4 scenarios × 6 seeds, with a readable report. **Run this** to watch a full campaign. |

## Run it (= verify it)

```bash
php EternalVainamoinenReleaseTest.php     # → ALL 49 TESTS PASSED
php sim-scenarios.php                      # → full multi-scenario simulation report
```

The tests and the simulator show the things that matter:

- **Convergence** — the drip paces toward the target and stops there; it can undershoot when capacity or demand
  lags, it does not run away past the target.
- **Reserve safety** — a held-back reserve is never breached, even under capacity pressure (the campaign
  *undershoots* rather than eat the reserve).
- **Limits hold** — a product sold as "Claimed: N of LIMIT" never passes its limit, and its releases are spread to
  its due date instead of selling out months early.
- **Published == enforced** — the realized drop-share per type matches the odds the engine published, within
  sampling noise.

## How it works

Each 5-minute tick the cron asks the engine, stream by stream: *does* a drop fire, and if so, *which* product?
At most **one release per tick** across the whole campaign.

### 1. Rate — does a drop fire this tick?

For a stream (a group of products sharing one target), the needed rate is the mean pace that lands what is still
to release at the campaign end:

```
rate = rate(target − (Active + Free), seconds left in the campaign, tick interval)     // = remaining / ticks left
```

*Active* is sold accounts, *Free* is released-but-unsold stock. Counting Free means standing stock slows the drip
by itself. Once Active + Free reaches the target the stream stops. Then:

- **Global open buffer** (`factorGlobalOpen`): the rate tapers linearly to 0 as the TOTAL unsold stock across every
  product goes from 40 to 50 (`TARGET_OPEN` 50, band 10), so open stock holds near 50; as people buy, it refills.
- **Operator knob** (`factorOperatorControl`): `0` pauses, `<1` throttles, `>1` boosts. Its live value is published
  in the feed as `operator_control`.

### 1b. Limited releases — "Claimed: N of LIMIT"

Some products are sold with a published limit, shown on the page as "Claimed: N of LIMIT · due DATE". Each one is
its own stream with that **due date**: the date shown, which is the product's own date or otherwise the campaign end
(2026-12-31). Its **maximum** rate is:

```
limitedRate = rate(limit − taken − open, seconds to the due date, tick interval)     // taken = claimed + pending
```

that is, everything that may still be released, spread evenly over the time left to the due date. *Claimed* counts
every slot ever taken, including ones later cancelled — the same number the page shows. *Pending* counts unpaid
orders, each of which holds a slot until it is paid or cancelled. This maximum is also the ceiling
(`Pacing::maxRate`): the buffer and the knob can slow a limited product, nothing can make it faster. At the limit the
rate is 0, and `withinLimit()` (taken + open < limit) is re-checked at the moment of release, so a sale that lands
between the check and the release cannot push it over. Every tick the cron also trims open stock back to
`limit − taken` for every limited product, including one-shot batches that are not dripped, because a cancelled
order hands its slot back to stock. If the published limits cannot be read, nothing is released.

### 2. Selection — which product, if a drop fired?

`weights()` → `pickByWeight()`. Weighted by available capacity (`free − reserve`, the classic protection level),
then an **over-supply divider on each product's open stock** — weight ÷ (1 + (open − 3)/10) above 3 open, hard 0 at
`min(10, free)`, so released-but-unsold can never exceed true capacity — then per-tier and per-product biases.
`publishedOdds()` returns exactly these normalized weights — what the feed shows *is* what the code uses.

For provably-fair draws, inject a seeded / commit-reveal RNG via the constructor (the default `mt_rand` is
convenient, not auditable).

## Check the live campaign

The live feed is `https://pulsedmedia.com/data/v1/eternal-drops.json`, rewritten every tick; every release is
appended to `https://pulsedmedia.com/data/v1/eternal-drops-audit.jsonl`. In the feed, each limited product the drip
paces carries `limit`, `claimed`, `pending`, `due` and `max_drop_chance_per_tick` next to `open_now` and
`drop_chance_per_tick`. (A one-shot batch, with all its slots put on sale at once, is not in the drip feed; its
page card shows Available and Claimed, and Available + Claimed never exceeds the limit.) For each one you can check:

- `claimed + pending + open_now <= limit`;
- `max_drop_chance_per_tick == limitedRate(limit, claimed + pending, open_now, due − generated_at, tick_interval_seconds)`
  (to rounding);
- `drop_chance_per_tick <= max_drop_chance_per_tick`.

The maximum is computed at the start of the tick; on a tick where that product was released, `open_now` already
counts the new slot, so recompute with `open_now − 1` for that tick (the audit log shows which tick released it).

## What's NOT here (and why that's fine)

The production cron that wires this to live billing stays private: it reads live capacity, holds back the reserve
that keeps normal full-price stock for regular orders (a product with no room for that has `free = 0`), reads the
published limits, applies the release, and writes the feed. Apart from those capacity inputs, the rules it applies
are the engine functions above (`rate`, `limitedRate`, `withinLimit`, `evaluate`), so the logic that decides the
pace and the odds is all in this directory.

## License

Apache-2.0 — see the SPDX header in each file. Part of [mcxSauces](https://github.com/MagnaCapax/mcxSauces).
