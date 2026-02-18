#!/usr/bin/env bash
# =============================================================================
# sas2ircu-led-control.sh — Drive Locate LED Control for SuperMicro + LSI/Broadcom
# =============================================================================
#
# Portable drive locate LED control for SuperMicro servers with LSI/Broadcom
# SAS HBAs (SAS2008, SAS2308, SAS3008, SAS3108, etc.).
#
# PURPOSE:
#   Identify individual drives in dense bays (20-80+ drives) during datacenter
#   visits. The standard Linux SES subsystem (sg_ses, ledctl, sysfs enclosure)
#   does NOT work on SuperMicro hardware. This tool uses sas2ircu/sas3ircu to
#   control locate LEDs at the HBA firmware level, bypassing the broken Linux
#   SES driver entirely. For drives on AHCI controllers (not SAS), a dd-blink
#   fallback creates a visible activity LED pattern.
#
# SUBCOMMANDS:
#   list              Show all drives with adapter:encl:slot mapping
#   blink DEV|SERIAL  Turn ON locate LED for one drive
#   off DEV|SERIAL    Turn OFF locate LED for one drive
#   all-on            Turn ON locate LED for ALL drives
#   all-off           Turn OFF locate LED for ALL drives
#   --help            Show usage information
#   --version         Show version string
#
# EXAMPLES:
#   sas2ircu-led-control.sh list
#   sas2ircu-led-control.sh blink /dev/sdaf
#   sas2ircu-led-control.sh blink ZR50MFBH
#   sas2ircu-led-control.sh off /dev/sdaf
#   sas2ircu-led-control.sh all-on
#   sas2ircu-led-control.sh all-off
#
# DEPENDENCIES:
#   - bash 4.x+
#   - sas2ircu and/or sas3ircu (from Broadcom/LSI)
#   - smartctl (smartmontools) — for /dev/sdX serial lookup
#   - Standard coreutils: timeout, awk, grep, sed, mktemp, kill, date
#   - dd (for AHCI fallback blink)
#
# TESTED HARDWARE:
#   - le4-0-103: 5x SAS2308/SAS2008, SuperMicro chassis + 45-bay JBOD (81 drives)
#   - le4-0-77:  2x SAS2008 + onboard AMD FCH AHCI (14 SAS + 8 AHCI drives)
#
# LICENSE: MIT — https://github.com/MagnaCapax/mcxSauces
# =============================================================================

set -uo pipefail

readonly VERSION="1.0.0"
readonly CACHE_FILE="/tmp/sas2ircu-led-topology.cache"
readonly CACHE_TTL=300  # seconds
readonly BLINK_PID_DIR="/tmp/sas2ircu-led-blink-pids"
readonly SAS2IRCU_TIMEOUT=5
readonly DD_BLOCK_COUNT=100  # dd bs=1M count=N — ~3 seconds of sustained read
readonly DD_CYCLE_SLEEP=3            # seconds of darkness

# Discovered binaries (populated by find_binaries)
SAS2IRCU_BIN=""
SAS3IRCU_BIN=""
USE_CACHE=true

# =============================================================================
# Utility Functions
# =============================================================================

##
# Print message to stderr and exit with code 1.
#
# @param $1  Error message to display.
#
die() {
    echo "[ERR] $1" >&2
    exit 1
}

##
# Print warning to stderr. Does not exit.
#
# @param $1  Warning message to display.
#
warn() {
    echo "[WARN] $1" >&2
}

##
# Print informational message to stdout.
#
# @param $1  Info message to display.
#
info() {
    echo "[INFO] $1"
}

##
# Print success message to stdout.
#
# @param $1  Success message to display.
#
ok() {
    echo "[OK] $1"
}

##
# Print usage information and exit.
#
usage() {
    cat <<'USAGE'
Usage: sas2ircu-led-control.sh <command> [options]

Commands:
  list              Show all drives with adapter:encl:slot, serial, device, model
  blink DEV|SERIAL  Turn ON locate LED (or dd-blink for AHCI) for one drive
  off DEV|SERIAL    Turn OFF locate LED (or stop dd-blink) for one drive
  all-on            Turn ON locate LED for ALL drives
  all-off           Turn OFF locate LED for ALL drives (and stop dd-blinks)

Options:
  --no-cache        Force topology refresh (skip cache)
  --help            Show this help
  --version         Show version

Drive specification:
  /dev/sdX          Block device path (e.g., /dev/sdaf)
  SERIAL            Drive serial number (exact match, case-insensitive)

Examples:
  sas2ircu-led-control.sh list
  sas2ircu-led-control.sh blink /dev/sdaf
  sas2ircu-led-control.sh blink ZR50MFBH
  sas2ircu-led-control.sh off /dev/sdaf
  sas2ircu-led-control.sh all-off

Notes:
  - Parallel across SAS adapters, serial within each adapter
  - AHCI drives (not on SAS HBA) use dd activity LED blink as fallback
  - Locate LEDs persist until explicitly turned OFF
  - Requires root (HBA and raw device access)
USAGE
    exit 0
}

# =============================================================================
# Binary Discovery
# =============================================================================

##
# Locate sas2ircu and/or sas3ircu binaries.
# Sets SAS2IRCU_BIN and SAS3IRCU_BIN globals.
# Dies if neither binary is found.
#
find_binaries() {
    SAS2IRCU_BIN="$(command -v sas2ircu 2>/dev/null || true)"
    SAS3IRCU_BIN="$(command -v sas3ircu 2>/dev/null || true)"

    if [[ -z "$SAS2IRCU_BIN" && -z "$SAS3IRCU_BIN" ]]; then
        die "Neither sas2ircu nor sas3ircu found in PATH. Install from Broadcom/LSI."
    fi
}

# =============================================================================
# Topology Discovery & Cache
# =============================================================================

##
# Discover SAS topology: adapters, enclosures, slots, drive serials.
# Parses 'sas2ircu list' and 'sas2ircu N display' output.
# Writes results to CACHE_FILE in format:
#   ADAPTER ENCL SLOT SERIAL MODEL SIZE_MB BINARY
# One line per drive.
#
# Globals set: none (writes to CACHE_FILE).
# Side effects: overwrites CACHE_FILE.
#
discover_topology() {
    local tmpfile
    tmpfile="$(mktemp "${CACHE_FILE}.XXXXXX")"

    # Process each available binary (sas2ircu, sas3ircu)
    local bin binname
    for bin in "$SAS2IRCU_BIN" "$SAS3IRCU_BIN"; do
        [[ -z "$bin" ]] && continue
        binname="$(basename "$bin")"

        # Parse adapter list: lines matching index + SAS type
        local adapter_indices
        adapter_indices="$(timeout "$SAS2IRCU_TIMEOUT" "$bin" list 2>/dev/null \
            | awk '/^\s+[0-9]+\s+SAS/ { print $1 }')" || true

        if [[ -z "$adapter_indices" ]]; then
            warn "No adapters found via $binname"
            continue
        fi

        local idx
        for idx in $adapter_indices; do
            # Parse display output for this adapter: extract drive blocks
            local display_output
            display_output="$(timeout 10 "$bin" "$idx" display 2>/dev/null)" || {
                warn "Timeout reading adapter $idx via $binname"
                continue
            }

            # Parse each "Device is a Hard disk" block
            echo "$display_output" | awk -v adapter="$idx" -v binary="$binname" '
            /Device is a Hard disk/ { in_block=1; encl=""; slot=""; serial=""; model=""; size="" }
            in_block && /Enclosure #/ { split($0, a, ":"); gsub(/^[ \t]+|[ \t]+$/, "", a[2]); encl=a[2] }
            in_block && /Slot #/ { split($0, a, ":"); gsub(/^[ \t]+|[ \t]+$/, "", a[2]); slot=a[2] }
            in_block && /Serial No/ { split($0, a, ":"); gsub(/^[ \t]+|[ \t]+$/, "", a[2]); serial=a[2] }
            in_block && /Model Number/ { split($0, a, ":"); gsub(/^[ \t]+|[ \t]+$/, "", a[2]); model=a[2] }
            in_block && /Size \(in MB\)/ {
                split($0, a, ":")
                gsub(/^[ \t]+|[ \t]+$/, "", a[2])
                split(a[2], sz, "/")
                size=sz[1]
            }
            in_block && /Protocol/ {
                # End of relevant fields for this block
                if (serial != "" && encl != "" && slot != "") {
                    printf "%s %s %s %s %s %s %s\n", adapter, encl, slot, serial, model, size, binary
                }
                in_block=0
            }
            ' >> "$tmpfile"
        done
    done

    if [[ ! -s "$tmpfile" ]]; then
        rm -f "$tmpfile"
        die "No SAS drives found via sas2ircu/sas3ircu"
    fi

    mv -f "$tmpfile" "$CACHE_FILE"
}

##
# Load topology from cache if valid (< CACHE_TTL seconds old).
# If cache is stale or missing, runs discover_topology().
# If USE_CACHE is false, always refreshes.
#
# Outputs: nothing (CACHE_FILE is up to date after call).
#
load_cache() {
    if [[ "$USE_CACHE" == true && -f "$CACHE_FILE" ]]; then
        local now cache_mtime age
        now="$(date +%s)"
        cache_mtime="$(stat -c %Y "$CACHE_FILE" 2>/dev/null || echo 0)"
        age=$(( now - cache_mtime ))
        if (( age < CACHE_TTL )); then
            return 0
        fi
    fi
    discover_topology
}

# =============================================================================
# Drive Resolution
# =============================================================================

##
# Get serial number for a block device via smartctl.
#
# @param $1  Block device path (e.g., /dev/sda).
# @return    Serial number string on stdout, or empty if not found.
#
get_serial_for_device() {
    local dev="$1"
    smartctl -i "$dev" 2>/dev/null \
        | awk -F: '/Serial Number/ { gsub(/^[ \t]+|[ \t]+$/, "", $2); print $2; exit }'
}

##
# Resolve a drive specification (device path or serial) to topology entry.
# Returns: "ADAPTER ENCL SLOT SERIAL MODEL SIZE_MB BINARY" on stdout.
# Returns empty string if drive is not on any SAS HBA (AHCI drive).
#
# @param $1  Drive spec: /dev/sdX or serial number.
# @return    Topology line if SAS, empty if AHCI, exits on not found.
#
resolve_drive() {
    local spec="$1"
    local serial=""
    local is_device=false

    # Determine if spec is a device path or serial
    if [[ "$spec" == /dev/* ]]; then
        is_device=true
        if [[ ! -b "$spec" ]]; then
            die "Block device not found: $spec"
        fi
        serial="$(get_serial_for_device "$spec")"
        if [[ -z "$serial" ]]; then
            die "Cannot read serial for $spec (is smartctl installed? run as root?)"
        fi
    else
        serial="$spec"
    fi

    load_cache

    # Search cache for serial (case-insensitive)
    local match
    match="$(awk -v ser="$serial" 'BEGIN{IGNORECASE=1} $4 == ser { print; exit }' "$CACHE_FILE")"

    if [[ -n "$match" ]]; then
        echo "$match"
        return 0
    fi

    # Not found in SAS topology — if it's a device, it might be AHCI
    if [[ "$is_device" == true ]]; then
        # Return empty to signal AHCI
        return 0
    fi

    die "Drive not found: $spec (not in SAS topology, not a device path)"
}

# =============================================================================
# LED Control — SAS
# =============================================================================

##
# Turn locate LED ON or OFF for a SAS drive via sas2ircu/sas3ircu.
#
# @param $1  Adapter index.
# @param $2  Enclosure:Slot (e.g., "2:5").
# @param $3  Action: "ON" or "OFF".
# @param $4  Binary to use (sas2ircu or sas3ircu).
# @return    0 on success, 1 on failure.
#
sas_locate() {
    local adapter="$1" encl_slot="$2" action="$3" bin="$4"

    if timeout "$SAS2IRCU_TIMEOUT" "$bin" "$adapter" locate "$encl_slot" "$action" >/dev/null 2>&1; then
        return 0
    else
        warn "Timeout: $bin $adapter locate $encl_slot $action (${SAS2IRCU_TIMEOUT}s timeout exceeded)"
        return 1
    fi
}

# =============================================================================
# LED Control — AHCI dd-blink Fallback
# =============================================================================

##
# Start dd-blink for an AHCI drive (activity LED pattern: ~3s read, 3s dark).
# Stores the background process PID for later cleanup.
#
# @param $1  Block device path (e.g., /dev/sde).
# @param $2  Serial number (for PID file naming).
#
start_dd_blink() {
    local dev="$1" serial="$2"

    mkdir -p "$BLINK_PID_DIR"

    # Kill existing blink for this drive if any
    stop_dd_blink "$serial" 2>/dev/null

    # Start background blink loop
    (
        while true; do
            dd if="$dev" bs=1M count="$DD_BLOCK_COUNT" iflag=direct of=/dev/null 2>/dev/null
            sleep "$DD_CYCLE_SLEEP"
        done
    ) &
    local pid=$!
    echo "$pid" > "${BLINK_PID_DIR}/${serial}.pid"
    ok "Blink ON: $dev (AHCI fallback, dd PID $pid)"
}

##
# Stop dd-blink for a drive by serial number.
#
# @param $1  Serial number.
#
stop_dd_blink() {
    local serial="$1"
    local pidfile="${BLINK_PID_DIR}/${serial}.pid"

    if [[ -f "$pidfile" ]]; then
        local pid
        pid="$(cat "$pidfile")"
        # Kill the subshell and all its children
        kill -- -"$pid" 2>/dev/null || kill "$pid" 2>/dev/null || true
        rm -f "$pidfile"
    fi
}

##
# Stop all dd-blink processes.
#
stop_all_dd_blinks() {
    if [[ -d "$BLINK_PID_DIR" ]]; then
        local pidfile
        for pidfile in "$BLINK_PID_DIR"/*.pid; do
            [[ -f "$pidfile" ]] || continue
            local pid
            pid="$(cat "$pidfile")"
            kill -- -"$pid" 2>/dev/null || kill "$pid" 2>/dev/null || true
            rm -f "$pidfile"
        done
    fi
}

# =============================================================================
# High-Level LED Operations
# =============================================================================

##
# Turn locate LED on for a single drive (SAS or AHCI fallback).
#
# @param $1  Drive spec: /dev/sdX or serial.
#
locate_on() {
    local spec="$1"
    local topo serial

    topo="$(resolve_drive "$spec")"

    if [[ -n "$topo" ]]; then
        # SAS drive — use sas2ircu locate
        local adapter encl slot model size bin
        read -r adapter encl slot _ model size bin <<< "$topo"
        if sas_locate "$adapter" "${encl}:${slot}" "ON" "$bin"; then
            ok "Locate ON: $spec (adapter $adapter, encl $encl, slot $slot) via $bin"
        else
            warn "Failed to turn on locate for $spec"
        fi
    else
        # AHCI drive — dd-blink fallback
        if [[ "$spec" != /dev/* ]]; then
            die "AHCI fallback requires device path, not serial: $spec"
        fi
        local serial_ahci
        serial_ahci="$(get_serial_for_device "$spec")"
        start_dd_blink "$spec" "$serial_ahci"
    fi
}

##
# Turn locate LED off for a single drive (SAS or AHCI fallback).
#
# @param $1  Drive spec: /dev/sdX or serial.
#
locate_off() {
    local spec="$1"
    local topo

    topo="$(resolve_drive "$spec")"

    if [[ -n "$topo" ]]; then
        # SAS drive
        local adapter encl slot model size bin
        read -r adapter encl slot _ model size bin <<< "$topo"
        if sas_locate "$adapter" "${encl}:${slot}" "OFF" "$bin"; then
            ok "Locate OFF: $spec (adapter $adapter, encl $encl, slot $slot) via $bin"
        else
            warn "Failed to turn off locate for $spec"
        fi
    else
        # AHCI drive — stop dd-blink
        if [[ "$spec" != /dev/* ]]; then
            die "AHCI fallback requires device path, not serial: $spec"
        fi
        local serial_ahci
        serial_ahci="$(get_serial_for_device "$spec")"
        stop_dd_blink "$serial_ahci"
        ok "Blink OFF: $spec (AHCI fallback)"
    fi
}

##
# Turn locate LED on or off for ALL drives.
# Parallelizes across SAS adapters, serial within each.
# AHCI drives use dd-blink.
#
# @param $1  Action: "ON" or "OFF".
#
bulk_locate() {
    local action="$1"
    local start_time
    start_time="$(date +%s)"

    load_cache

    # Count totals
    local total_drives adapters
    total_drives="$(wc -l < "$CACHE_FILE")"
    adapters="$(awk '{ print $1 }' "$CACHE_FILE" | sort -u)"
    local num_adapters
    num_adapters="$(echo "$adapters" | wc -l)"

    info "Turning $action locate LEDs for $total_drives SAS drives across $num_adapters adapters..."

    # Process each adapter in parallel
    local pids=()
    local adapter
    for adapter in $adapters; do
        (
            local count=0 ok_count=0 fail_count=0
            while IFS=' ' read -r a encl slot serial model size bin; do
                [[ "$a" == "$adapter" ]] || continue
                ((count++))
                if sas_locate "$adapter" "${encl}:${slot}" "$action" "$bin"; then
                    ((ok_count++))
                else
                    ((fail_count++))
                fi
            done < "$CACHE_FILE"
            info "Adapter $adapter: $ok_count OK, $fail_count FAIL (of $count)"
        ) &
        pids+=($!)
    done

    # Handle AHCI drives
    if [[ "$action" == "ON" ]]; then
        # Find block devices not in SAS topology
        local ahci_count=0
        for dev in /sys/block/sd*; do
            local devname
            devname="/dev/$(basename "$dev")"
            local serial
            serial="$(get_serial_for_device "$devname" 2>/dev/null)"
            [[ -z "$serial" ]] && continue
            if ! awk -v ser="$serial" 'BEGIN{IGNORECASE=1} $4 == ser { found=1; exit } END { exit !found }' "$CACHE_FILE" 2>/dev/null; then
                start_dd_blink "$devname" "$serial"
                ((ahci_count++))
            fi
        done
        if (( ahci_count > 0 )); then
            info "AHCI: $ahci_count drives (dd-blink)"
        fi
    else
        stop_all_dd_blinks
    fi

    # Wait for all SAS adapter subshells
    for pid in "${pids[@]}"; do
        wait "$pid" 2>/dev/null
    done

    local end_time elapsed
    end_time="$(date +%s)"
    elapsed=$(( end_time - start_time ))
    ok "Done in ${elapsed}s ($total_drives SAS drives, $num_adapters adapters)"
}

# =============================================================================
# List Command
# =============================================================================

##
# Pretty-print drive topology table.
# Shows adapter, encl, slot, device path, serial, model, size, and controller.
# Always refreshes cache. AHCI drives are appended with "---" for SAS fields.
#
list_drives() {
    # Always refresh for list
    discover_topology

    # Build serial → device mapping via smartctl
    declare -A serial_to_dev
    for dev in /sys/block/sd*; do
        local devname
        devname="/dev/$(basename "$dev")"
        local serial
        serial="$(get_serial_for_device "$devname" 2>/dev/null)"
        [[ -n "$serial" ]] && serial_to_dev["${serial^^}"]="$devname"
    done

    # Print header
    printf "%-8s %-5s %-5s %-12s %-16s %-24s %-8s %s\n" \
        "Adapter" "Encl" "Slot" "Device" "Serial" "Model" "Size" "Controller"
    printf '%0.s-' {1..100}; echo

    # Print SAS drives from cache
    while IFS=' ' read -r adapter encl slot serial model size bin; do
        local dev="${serial_to_dev[${serial^^}]:-N/A}"
        local size_tb
        if [[ "$size" =~ ^[0-9]+$ ]] && (( size > 0 )); then
            size_tb="$(awk "BEGIN { printf \"%.1fTB\", $size / 1000000 }")"
        else
            size_tb="N/A"
        fi
        printf "%-8s %-5s %-5s %-12s %-16s %-24s %-8s %s\n" \
            "$adapter" "$encl" "$slot" "$dev" "$serial" "$model" "$size_tb" "$bin"
    done < "$CACHE_FILE"

    # Find and list AHCI drives (not in SAS topology)
    for dev in /sys/block/sd*; do
        local devname
        devname="/dev/$(basename "$dev")"
        local serial
        serial="$(get_serial_for_device "$devname" 2>/dev/null)"
        [[ -z "$serial" ]] && continue
        if ! awk -v ser="$serial" 'BEGIN{IGNORECASE=1} $4 == ser { found=1; exit } END { exit !found }' "$CACHE_FILE" 2>/dev/null; then
            local model_ahci size_ahci
            model_ahci="$(smartctl -i "$devname" 2>/dev/null | awk -F: '/Device Model/ { gsub(/^[ \t]+|[ \t]+$/, "", $2); print $2; exit }')"
            size_ahci="$(smartctl -i "$devname" 2>/dev/null | awk -F: '/User Capacity/ { gsub(/^[ \t]+|[ \t]+$/, "", $2); print $2; exit }')"
            printf "%-8s %-5s %-5s %-12s %-16s %-24s %-8s %s\n" \
                "---" "---" "---" "$devname" "$serial" "${model_ahci:-N/A}" "${size_ahci:-N/A}" "AHCI (dd-blink)"
        fi
    done
}

# =============================================================================
# Main
# =============================================================================

##
# Entry point. Parses arguments and dispatches to subcommands.
#
main() {
    # Root check
    if [[ $EUID -ne 0 ]]; then
        die "Must run as root (needs HBA and raw device access)"
    fi

    # Parse global flags first
    local args=()
    for arg in "$@"; do
        case "$arg" in
            --no-cache) USE_CACHE=false ;;
            --help|-h) usage ;;
            --version|-V) echo "sas2ircu-led-control $VERSION"; exit 0 ;;
            *) args+=("$arg") ;;
        esac
    done

    if (( ${#args[@]} == 0 )); then
        usage
    fi

    find_binaries

    local cmd="${args[0]}"
    case "$cmd" in
        list)
            list_drives
            ;;
        blink)
            [[ ${#args[@]} -lt 2 ]] && die "Usage: $0 blink /dev/sdX|SERIAL"
            locate_on "${args[1]}"
            ;;
        off)
            [[ ${#args[@]} -lt 2 ]] && die "Usage: $0 off /dev/sdX|SERIAL"
            locate_off "${args[1]}"
            ;;
        all-on)
            bulk_locate "ON"
            ;;
        all-off)
            bulk_locate "OFF"
            ;;
        *)
            die "Unknown command: $cmd (try --help)"
            ;;
    esac
}

main "$@"
