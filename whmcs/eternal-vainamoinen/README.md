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
| `EternalVainamoinenRelease.php` | The pure decision engine — inputs in, decision out. No database, no clock, no I/O. **This is the whole algorithm.** |
| `EternalVainamoinenReleaseTest.php` | Plain-PHP regression tests that lock the reviewed behaviour. The cheapest way to verify. |
| `EternalVainamoinenSimulator.php` | A synthetic-data simulator that runs the engine over a full campaign and asserts the invariants every tick. |
| `sim-scenarios.php` | An example driver — 4 scenarios × 6 seeds, with a readable report. **Run this** to watch a full campaign. |

## Run it (= verify it)

```bash
php EternalVainamoinenReleaseTest.php     # → ALL 17 TESTS PASSED
php sim-scenarios.php                      # → full multi-scenario simulation report
```

The simulator demonstrates the three things that matter:

- **Convergence** — the drip paces toward the target and stops there, tightly bounded: a long pause followed
  by catch-up can tip the realized total ~1 over, but it never runs away past the target.
- **Reserve safety** — a held-back reserve is never breached, even under capacity pressure (the campaign
  *undershoots* rather than eat the reserve).
- **Published == enforced** — the realized drop-share per type matches the odds the engine published, within
  sampling noise. That equality is the fairness guarantee, shown empirically.

## How it works (two stages per tick)

Call `evaluate()` once per "stream" per tick. It runs, in order:

1. **Rate** (`dropProbability`) — *does* a drop fire this tick? A real-time pace toward the target across **four**
   horizons (day, week, month, full campaign): each horizon's needed rate clears that window's schedule deficit of
   already-released (sold + open) vs target, and the engine takes the **min** — the tightest window governs, so the
   rate falls to 0 once already-released is at/ahead of pace on *any* window (it brakes when ahead; no floor). Then an
   **over-supply divider on the stream's total open inventory** damps the chance further — a stream that has piled up
   unsold stock slows its new releases, hard-zeroing once too much is already available. Bounded by an account cap and a
   live operator knob (`0` = pause, `<1` throttle, `>1` boost), part of the published config so live tuning stays verifiable.
2. **Selection** (`weights` → `pickByWeight`) — *which* type, only if a drop fired? Weighted by available
   capacity (`free − reserve`, the classic protection level), then an **over-supply divider on each type's open
   stock** — a smooth curve that damps a type as its unsold inventory grows and hard-zeros at `min(cap, free)`, so
   released-but-unsold can never exceed true capacity — then scaled by per-tier and per-SKU biases. `publishedOdds()`
   returns exactly these normalized weights — what a page displays *is* what the code uses.

For provably-fair draws, inject a seeded / commit-reveal RNG via the constructor (the default `mt_rand` is
convenient, not auditable).

## What's NOT here (and why that's fine)

Only the **decision logic** is open. The production cron that wires this to live billing — reading capacity,
applying the release, writing the public feed — is deployment-specific plumbing and stays private. It carries
**no** decision logic; everything that determines *fairness* lives in this directory. You can verify the
fairness without seeing the plumbing, which is the whole idea.

## License

Apache-2.0 — see the SPDX header in each file. Part of [mcxSauces](https://github.com/MagnaCapax/mcxSauces).
