# Task: Implement sas2ircu LED Control Script

Read `baremetal/sas2ircu-led-tool-dev-sop.md` FIRST — it contains the complete specification.

## Deliverable

Create `baremetal/sas2ircu-led-control.sh` — a single self-contained bash script.

## Requirements Summary

- Auto-discover SAS topology via `sas2ircu list` + `sas2ircu N display`
- Map /dev/sdX to adapter:encl:slot via serial number matching (smartctl + sas2ircu)
- Subcommands: `list`, `blink`, `off`, `all-on`, `all-off`, `--help`, `--version`
- Parallel across adapters, serial within adapter (HBA firmware jams on concurrent calls)
- Mixed controller support: SAS locate LED (primary) + dd-blink fallback for AHCI drives
- Topology cache at /tmp/sas2ircu-led-topology.cache (5 min TTL)
- 5-second timeout on each sas2ircu call
- PID tracking for dd-blink background processes
- Support both `sas2ircu` and `sas3ircu` (identical syntax, different binary)

## Engineering Standards (CRITICAL)

- **KISS**: Single bash script, no frameworks, no libraries
- **DRY**: Functions for repeated logic, single source of truth for parsing
- **YAGNI**: Only implement what the SOP specifies. No plugin system, no config files.
- **Bash only**: No Python, no Perl. Standard coreutils only.
- **Portable**: Debian 10-13, any server with sas2ircu installed
- **Fail-soft**: Graceful degradation, never crash, always explain

## FULL DOCBLOCKS (OPERATOR REQUIREMENT)

Every function MUST have a docblock comment explaining:
- Purpose (what the function does)
- Parameters (what it takes)
- Returns/side effects (what it produces)
- Example usage where helpful

The script header MUST include:
- Full description of what the tool does and why it exists
- Usage examples for every subcommand
- License (MIT)
- Repository URL (https://github.com/MagnaCapax/mcxSauces)
- Dependencies list
- Tested hardware reference

## Existing Prototype (Reference Only)

There is a hardcoded prototype at `/root/led-control.sh` on le4-0-103. Key patterns from it:
- Parallel execution: `process_adapter "$adapter" &` + `wait`
- Timeout with kill: `timeout 5 sas2ircu ... || pkill -9 -f ...`
- Retry logic for flaky HBA commands

The new script must NOT hardcode slot maps. Auto-discover everything.

## Output Format

Follow the output formats specified in the SOP exactly:
- `list`: tabular with Adapter, Encl, Slot, Device, Serial, Model, Size, Controller
- `blink`/`off`: `[OK]`, `[WARN]`, `[ERR]` prefixed status lines
- `all-on`/`all-off`: Summary with per-adapter counts and total time

## Testing (What You Can Verify)

1. `bash -n sas2ircu-led-control.sh` — zero exit
2. `shellcheck sas2ircu-led-control.sh` — zero errors (warnings OK if justified with SC disable comments)
3. Verify `--help` prints complete usage with examples
4. Verify `--version` prints version string
5. Verify script structure: all functions from SOP are present
6. Verify error handling: root check, missing binary check, permission check

## Commit

After creating the script:
```
git add baremetal/sas2ircu-led-control.sh
git commit -m "feat: sas2ircu LED control script for SuperMicro + LSI/Broadcom SAS HBAs

Portable drive locate LED control with auto-discovery, parallel execution,
and AHCI dd-blink fallback. Tested on SAS2008/SAS2308 hardware.

Co-Authored-By: Väinämöinen <noreply@pulsedmedia.com>"
```

Do NOT push. Do NOT modify any other files.
