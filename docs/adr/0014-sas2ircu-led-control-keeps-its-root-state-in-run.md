# ADR-0014: sas2ircu-led-control keeps its root state in /run

## Metadata
- Author: Väinämöinen
- Date: 2026-11-23
- Status: Accepted

## Context
`baremetal/sas2ircu-led-control.sh` runs as root. It kept two pieces of state at fixed names in `/tmp`, which every
account on the host can write to:

- the topology cache (`adapter encl slot serial ...` per drive). Any file there under 5 minutes old was trusted,
  whoever owned it, so `blink <SERIAL>` could light the locate LED of a different bay than the one holding that
  serial;
- the dd-blink PID directory. `off` and `all-off` read every `*.pid` file in it and signal that process group as
  root. A file holding `1` turns that into a signal to every process on the host.

Some hosts with SAS controllers also have unprivileged shell accounts, so another account could create either path
first.

## Options Considered
- **Move both paths to `/run`.** `/run` is root-owned and not world-writable, so only root can create these names.
  It is cleared at boot, which is correct for a 5-minute cache and for PIDs of processes that end at reboot. Two
  constants change and no logic does. Chosen.
- **Keep `/tmp` and check the owner and mode of each path before use.** Adds checks to a shell script (logic moves
  to PHP under the repository rules) and still races with the other account. Rejected.
- **Drop the cache.** Every call would rescan every controller, about 5 seconds each on large hosts. Rejected.

## Decision
- The cache is `/run/sas2ircu-led-topology.cache`, and its temporary file is created next to it with `mktemp`.
- The dd-blink PID directory is `/run/sas2ircu-led-blink-pids`.
- Nothing else in the script changes. `--no-cache` still forces a fresh scan.

## Consequences
- Another account can no longer choose which bay gets lit or which processes root signals.
- A cache or PID file left in `/tmp` by an older copy of the script is ignored. Blinks started by an older copy are
  not stopped by `off` or `all-off` from this version; they end at reboot, or when killed by hand.
- Old users: commands, options and output are unchanged.

## References
- `baremetal/sas2ircu-led-control.sh`
