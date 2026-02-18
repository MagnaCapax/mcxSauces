# Development SOP: sas2ircu LED Control Tool

## Purpose

Portable drive LED control for SuperMicro servers with LSI/Broadcom SAS HBAs (SAS2008, SAS2308, SAS3008, SAS3108, etc.).

This tool solves a real operational pain point: identifying individual drives in dense bays (20-80+ drives) during datacenter visits. The standard Linux SES subsystem (`sg_ses`, `ledctl`, sysfs enclosure) does NOT work on SuperMicro hardware. `sas2ircu locate` bypasses the Linux SES driver entirely -- the HBA firmware talks directly to the SAS expander to control locate LEDs at the firmware level.

Activity LED blinking via `dd` is unreliable in dense bays (sequential I/O creates indistinguishable wave patterns). This tool provides `sas2ircu locate` as the primary method and dd-blink as a fallback for drives on AHCI controllers.

**Target repo**: mcxSauces (MIT License, public GitHub)
**Target file**: `baremetal/sas2ircu-led-control.sh`

---

## Engineering Principles

- **KISS**: Single bash script, self-contained. No frameworks, no libraries, no abstractions.
- **DRY**: Extract repeated logic into functions. Single source of truth for topology parsing.
- **YAGNI**: Only implement what is specified below. No plugin system, no config files, no daemon mode.
- **Bash only**: No Python. No Perl. No compiled dependencies.
- **Portable**: Works on Debian 10-13, any server with `sas2ircu` or `sas3ircu` installed.
- **Fail-soft**: Graceful degradation when tools are missing. Never crash, always explain.

---

## Functional Requirements

### Subcommands

| Subcommand | Syntax | Description |
|-----------|--------|-------------|
| `list` | `led-control.sh list` | Show all drives with adapter:encl:slot mapping, serial, /dev/sdX, model |
| `blink` | `led-control.sh blink /dev/sdX` or `led-control.sh blink SERIAL` | Turn ON locate LED for one drive |
| `off` | `led-control.sh off /dev/sdX` or `led-control.sh off SERIAL` | Turn OFF locate LED for one drive |
| `all-on` | `led-control.sh all-on` | Turn ON locate LED for ALL drives |
| `all-off` | `led-control.sh all-off` | Turn OFF locate LED for ALL drives |
| `--help` | `led-control.sh --help` | Show usage information |
| `--version` | `led-control.sh --version` | Show version string |

### Drive Identification

Drives are identified by matching the serial number between two sources:
1. `smartctl -i /dev/sdX` provides: serial, model, firmware
2. `sas2ircu <adapter> display` provides: serial, enclosure, slot, adapter

Match on serial number to build the mapping: `/dev/sdX` <-> `adapter:encl:slot`.

Drives can be specified by:
- `/dev/sdX` path (e.g., `/dev/sdaf`)
- Serial number (exact match)

### Topology Discovery

1. Run `sas2ircu list` (or `sas3ircu list`) to discover all adapters
2. For each adapter, run `sas2ircu <N> display` to get all drives with encl:slot
3. For each drive, extract serial number from display output
4. Build mapping: `serial -> {adapter, encl, slot, device_type, sas2ircu_or_sas3ircu}`
5. For block devices in /sys/block/sd*, get serial via `smartctl -i` and match

### SAS2/SAS3 Mixed Support

- Check for both `sas2ircu` and `sas3ircu` binaries
- They have identical command syntax
- Track which binary to use per adapter
- Report which binary is being used in `list` output

### Parallel Execution Strategy

- **Across adapters**: PARALLEL (each HBA is independent hardware)
- **Within adapter**: SERIAL (HBA firmware jams if overwhelmed with concurrent locate calls)
- Use background processes (`&`) + `wait` for cross-adapter parallelism
- This turns an 80-second full sweep into ~16 seconds (5 adapters, ~16 drives each)

### Timeout Handling

- Each `sas2ircu` call gets a 5-second timeout via `timeout 5 sas2ircu ...`
- If a call times out, log a warning and continue (don't abort the whole operation)
- sas2ircu occasionally enters D-state and hangs -- the timeout prevents script hang

### Mixed Controller Support

Some servers have drives on both SAS HBAs and onboard AHCI controllers.

- Drives on SAS HBAs: use `sas2ircu locate` (blue locate LED, persistent, reliable)
- Drives on AHCI controllers: use dd-blink fallback (activity LED, 3s on / 3s off cycle)
- The `list` subcommand MUST show which drives are SAS-controlled and which are AHCI-only
- The `blink` subcommand MUST auto-detect the controller type and use the right method

### dd-blink Fallback (AHCI drives)

When a drive is not on any SAS HBA (no serial match in sas2ircu output):
1. Start a background process: `while true; do dd if=/dev/sdX bs=1M count=100 iflag=direct of=/dev/null 2>/dev/null; sleep 3; done`
2. This creates a ~3s sustained read / ~3s dark cycle
3. Store the background PID in a temp file for later cleanup
4. The `off` subcommand kills the background dd process via saved PID

Short blink cycles (< 1s) are visually indistinguishable in dense bays. The 3-second cycle is proven to work.

### Topology Cache

Parsing `sas2ircu display` is slow (~1s per adapter). Cache the topology:

- Cache file: `/tmp/sas2ircu-led-topology.cache`
- Format: plain text, one line per drive: `ADAPTER ENCL SLOT SERIAL MODEL SIZE BINARY`
- Cache validity: 5 minutes (drives don't move between slots)
- The `list` subcommand always refreshes the cache
- Other subcommands use cache if valid, refresh if stale
- `--no-cache` flag to force refresh

---

## Architecture

### Script Structure

```
#!/usr/bin/env bash
# sas2ircu-led-control.sh — Drive LED control for SuperMicro + LSI/Broadcom SAS HBAs
# MIT License — https://github.com/MagnaCapax/mcxSauces

set -uo pipefail

# Constants
CACHE_FILE="/tmp/sas2ircu-led-topology.cache"
CACHE_TTL=300  # seconds
BLINK_PID_DIR="/tmp/sas2ircu-led-blink-pids"
SAS2IRCU_TIMEOUT=5
DD_CYCLE_READ=3   # seconds of sustained read
DD_CYCLE_SLEEP=3  # seconds of darkness

# Functions:
#   usage()           — print help
#   die()             — print error and exit 1
#   warn()            — print warning to stderr
#   find_binaries()   — locate sas2ircu and/or sas3ircu
#   discover_topology() — run sas2ircu list + display, build cache
#   load_cache()      — read cache if valid
#   resolve_drive()   — given /dev/sdX or serial, return adapter:encl:slot
#   locate_on()       — turn on locate LED (sas2ircu or dd-blink)
#   locate_off()      — turn off locate LED (sas2ircu or kill dd)
#   bulk_locate()     — all-on or all-off with parallelism
#   list_drives()     — pretty-print topology
#   main()            — argument parsing and dispatch
```

### No External Dependencies Beyond

- `bash` (4.x+)
- `smartctl` (for serial number lookup from /dev/sdX)
- `sas2ircu` and/or `sas3ircu` (for HBA communication)
- Standard coreutils: `timeout`, `awk`, `grep`, `sed`, `mktemp`, `kill`, `date`
- `dd` (for AHCI fallback blink)

### Error Handling

- Missing `sas2ircu` AND `sas3ircu`: die with helpful message ("Install sas2ircu from Broadcom/LSI")
- Missing `smartctl`: warn, but still allow blink-by-serial and list (just can't resolve /dev/sdX)
- sas2ircu call timeout: warn per-call, continue with remaining drives
- No drives found: die with "No SAS drives found via sas2ircu"
- Drive not found in topology: if resolving /dev/sdX, check if it's an AHCI drive and fall back to dd-blink
- Permission errors: die with "Must run as root"

---

## Output Formats

### `list` Output

```
Adapter  Encl  Slot  Device      Serial          Model                    Size     Controller
0        1     0     /dev/sda    WFJ1T3RK        ST18000NM000J-2TV103     18.0TB   sas2ircu
0        1     1     /dev/sdb    WFJ1T4PL        ST18000NM000J-2TV103     18.0TB   sas2ircu
3        2     0     /dev/sdc    ZR50MFBH        ST18000NM000J-2TV103     18.0TB   sas2ircu
---      ---   ---   /dev/sde    Y9G0A03HFSAG    TOSHIBA HDWG480          8.0TB    AHCI (dd-blink)
```

AHCI drives (not on any SAS HBA) show `---` for adapter/encl/slot and `AHCI (dd-blink)` for controller.

### `blink` / `off` Output

```
[OK] Locate ON: /dev/sda (adapter 0, encl 1, slot 0) via sas2ircu
[OK] Blink ON: /dev/sde (AHCI fallback, dd PID 12345)
[WARN] Timeout: sas2ircu 3 locate 2:5 ON (5s timeout exceeded)
[ERR] Drive not found: /dev/sdz
```

### `all-on` / `all-off` Output

```
[INFO] Turning ON locate LEDs for 81 drives across 5 adapters...
[INFO] Adapter 0: 8 drives (parallel)
[INFO] Adapter 1: 8 drives (parallel)
[INFO] Adapter 3: 45 drives (parallel)
[INFO] Adapter 4: 12 drives (parallel)
[INFO] AHCI: 8 drives (dd-blink)
[OK] Done in 16.2s (81 drives, 5 SAS adapters + AHCI fallback)
```

---

## Testing Checklist

### Can verify without physical DC access:

1. **`bash -n sas2ircu-led-control.sh`** — syntax check (zero exit = pass)
2. **`shellcheck sas2ircu-led-control.sh`** — static analysis (zero warnings = ideal, some SC exceptions OK)
3. **`./sas2ircu-led-control.sh --help`** — prints usage (exit 0)
4. **`./sas2ircu-led-control.sh list`** on a server with sas2ircu installed — correct topology output
5. **Timeout handling**: Replace sas2ircu call with `sleep 10` mock, verify 5s timeout triggers
6. **Cache behavior**: Run list, check cache file exists, run list again within 5 min (should use cache), run with `--no-cache` (should refresh)
7. **Error messages**: Run without root (should show permission error), run without sas2ircu (should show install message)
8. **Parallel behavior**: Time `all-off` on a multi-adapter server, verify it's faster than serial

### Requires physical DC access:

9. **LED verification**: `blink /dev/sdX` actually lights up the correct bay
10. **dd-blink visibility**: AHCI fallback creates visible 3s on / 3s off pattern
11. **all-on / all-off**: All LEDs respond correctly
12. **Mixed controller**: SAS drives get locate LED, AHCI drives get dd-blink

---

## Known Hardware Tested

| Server | Adapters | Chip | Drives | Notes |
|--------|----------|------|--------|-------|
| le4-0-103 | 5 (adapters 0-4) | SAS2308 x4, SAS2008 x1 | 81 (chassis + 45-bay JBOD) | All drives on SAS HBAs |
| le4-0-77 | 2 (adapters 0-1) | SAS2008 x2 | 22 (14 SAS + 8 AHCI) | Mixed controllers |

---

## Key Discoveries from Production

1. **Each sas2ircu locate call takes ~1 second** — HBA firmware communication, unavoidable
2. **Parallel within adapter causes HBA firmware jam** — MUST be serial within adapter
3. **SES does NOT work on SuperMicro hardware** — no enclosure devices visible to kernel
4. **dd activity blink unreliable for ID in dense bays** — use long cycles (3s on / 3s off minimum)
5. **Locate LEDs persist until explicitly turned OFF** — always provide all-off cleanup
6. **sas2ircu is statically linked** — can be copied between servers without dependencies
7. **sas3ircu has identical syntax** — just different binary name for SAS3 HBAs

---

## Pitfalls to Avoid

1. **Don't parallelize within adapter** — firmware jams, commands hang, LEDs don't toggle
2. **Don't use short dd blink cycles** — visually indistinguishable in 20+ drive bays
3. **Don't assume all drives are on SAS HBAs** — AHCI drives exist on mixed servers
4. **Don't forget timeout on sas2ircu calls** — they hang in D-state sometimes
5. **Don't hardcode adapter/slot maps** — auto-discover everything via sas2ircu display
6. **Don't use sg_ses/ledctl/sysfs enclosure** — broken on SuperMicro hardware
7. **Don't use /dev/sdX in cache keys** — device names can change after reboot; use serials
8. **Don't forget to clean up dd-blink PIDs** — `off` and `all-off` must kill background dd

---

## File Location

**Script**: `baremetal/sas2ircu-led-control.sh`
**SOP**: `baremetal/sas2ircu-led-tool-dev-sop.md` (this file)

---

## sas2ircu Output Formats (for parsing reference)

### `sas2ircu list` output

```
LSI Corporation SAS2 IR Configuration Utility.
Version 20.00.00.00 (2014.09.18)
Copyright (c) 2008-2014 LSI Corporation. All rights reserved.


         Adapter      Vendor  Device                       SubSys  SubSys
 Index    Type          ID      ID    Pci Address          Ven ID  Dev ID
 -----  ------------  ------  ------  -----------------    ------  ------
   0     SAS2308       1000h    87h   00h:01h:00h:00h      1043h   8534h
   1     SAS2308       1000h    87h   00h:21h:00h:00h      1043h   8534h
   2     SAS2308       1000h    87h   00h:43h:00h:00h      1043h   8534h
   3     SAS2008       1000h    72h   00h:61h:00h:00h      1000h   3020h
   4     SAS2308       1000h    87h   00h:62h:00h:00h      1043h   8534h
SAS2IRCU: Utility Completed Successfully.
```

Parse: Lines matching `^\s+\d+\s+SAS` — extract index (field 1) and type (field 2).

### `sas2ircu <N> display` output (drive section)

```
Device is a Hard disk
  Enclosure #                             : 2
  Slot #                                  : 0
  SAS Address                             : 5000c500-e740-fb22
  State                                   : Ready (RDY)
  Size (in MB)/(in sectors)               : 17166912/35157655552
  Manufacturer                            : ATA
  Model Number                            : ST18000NM000J-2T
  Firmware Revision                       : SS02
  Serial No                               : ZR50MFBH
  GUID                                    : 5000c500e740fb23
  Protocol                                : SATA
  Drive Type                              : SATA_HDD
```

Parse: Look for `Device is a Hard disk` blocks. Within each block, extract:
- `Enclosure #` — the enclosure number
- `Slot #` — the slot number
- `Serial No` — the serial number (match key)
- `Model Number` — for display
- `Size (in MB)` — first number for display

### `smartctl -i /dev/sdX` output (relevant fields)

```
=== START OF INFORMATION SECTION ===
Model Family:     Seagate Exos X18
Device Model:     ST18000NM000J-2TV103
Serial Number:    ZR50MFBH
LU WWN Device Id: 5 000c50 0e740fb23
Firmware Version: SS02
User Capacity:    18,000,207,937,536 bytes [18.0 TB]
```

Parse: `Serial Number:` line — extract value after colon, strip whitespace.

---

## Implementation Notes

### Serial Number Matching

The serial number is the key for matching between smartctl and sas2ircu. Both sources report the same serial, but:
- sas2ircu may truncate long model names (16 chars)
- Serial numbers are always exact match (alphanumeric, no spaces)
- Compare serials case-insensitively (some firmware reports differently)

### Root Check

The script requires root for:
- `smartctl -i` (needs raw device access)
- `sas2ircu` commands (needs HBA access)
- `dd if=/dev/sdX` (needs raw device access)

Check at script start: `[[ $EUID -ne 0 ]] && die "Must run as root"`

### PID Tracking for dd-blink

- Create directory: `/tmp/sas2ircu-led-blink-pids/`
- When starting dd-blink: write PID to `/tmp/sas2ircu-led-blink-pids/<serial>.pid`
- When stopping: read PID, kill it, remove PID file
- `all-off` kills all PIDs in the directory

### Signal Handling

Trap EXIT to clean up any dd-blink processes on unexpected script termination:
```bash
cleanup() { kill $(cat "$BLINK_PID_DIR"/*.pid 2>/dev/null) 2>/dev/null; }
trap cleanup EXIT
```

Only register the trap when dd-blink processes are actually started (not for list, not for SAS-only operations).
