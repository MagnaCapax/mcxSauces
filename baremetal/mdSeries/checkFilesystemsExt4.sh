#!/bin/bash
# ----------------------------------------------------------------------------
# checkFilesystemsExt4.sh  from mcxSauce repo (Magna Capax Finland Oy)
# Author: Aleksi @ MCX
#
# This script scans for all available EXT filesystems (ext2, ext3, ext4)
# on the system. It prints a list of unique block devices (with mountpoints)
# and then runs a filesystem check (e2fsck) on each unmounted device.
#
# It runs e2fsck in non-interactive mode using -f (force) and -y (assume yes).
# In case e2fsck still prompts (e.g., due to a superblock error),
# the script attempts:
#   - to use expect if available; otherwise it falls back to piping a stream of "y"
#   - If a message indicating "bad magic number in super-block" is found,
#     the script issues a warning (without auto-recovering) and suggests manual commands.
#
# The script also:
#   4. Checks /proc/mounts for any active mount.
#   5. Detects and skips LUKS or ZFS devices.
#   6. Logs all output to /tmp/ext_fs_check.log.
#   7. Provides a summary with counters.
#   10. Supports command-line options: --json, --no-color, and --dry-run.
#
# This program is free software: you can redistribute it and/or modify it under
# the terms of the GNU General Public License as published by the Free Software
# Foundation, either version 3 of the License, or (at your option) any later version.
#
# This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
# without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
# Use at your own risk.
#
# ----------------------------------------------------------------------------

### Defaults and Option Variables ###
DRY_RUN=0
JSON_OUTPUT=0
NO_COLOR=0
TIMEOUT_SECS=600  # Default: 600 seconds (10 minutes)

# Process command-line options.
while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run)
            DRY_RUN=1
            shift
            ;;
        --json)
            JSON_OUTPUT=1
            shift
            ;;
        --no-color)
            NO_COLOR=1
            shift
            ;;
        --timeout)
            shift
            TIMEOUT_SECS="$1"
            shift
            ;;
        --timeout=*)
            TIMEOUT_SECS="${1#*=}"
            shift
            ;;
        *)
            shift
            ;;
    esac
done

# Logging file in /tmp
LOGFILE="/tmp/ext_fs_check.log"
exec > >(tee -a "$LOGFILE") 2>&1

# Set ANSI color codes (or disable if --no-color is provided)
if [ "$NO_COLOR" -eq 1 ]; then
  CYAN=""
  WHITE=""
  YELLOW=""
  RED=""
  NC=""
else
  CYAN="\033[0;36m"
  WHITE="\033[1;37m"
  YELLOW="\033[1;33m"
  RED="\033[1;31m"
  NC="\e[0m"
fi

# Function for stylized printing.
print_step() {
  echo -e "${CYAN}=> ${WHITE}$*${NC}"
}

# Arrays for tracking devices.
declare -a REPAIRED_DEVICES
declare -a FAILED_DEVICES

# Summary counters.
COUNT_TOTAL=0
COUNT_SKIPPED=0
COUNT_ERRORS=0
COUNT_OK=0

# Ensure the script is run as root.
if [ "$(id -u)" -ne 0 ]; then
  echo -e "${RED}This script must be run as root!${NC}"
  exit 1
fi

print_step "Scanning for available EXT filesystems..."

# Retrieve block device info using lsblk (full paths, no headers).
# Remove any leading tree-format characters.
lsblk_output=$(lsblk -pn -o NAME,FSTYPE,TYPE,MOUNTPOINT 2>/dev/null | sed -E 's/^[^\/]*//')

# Extract unique device paths with FSTYPE starting with "ext".
ext_devices=$(echo "$lsblk_output" | awk '$2 ~ /^ext/ {print $1}' | sort -u)

echo -e "${YELLOW}Devices with EXT filesystems found:${NC}"
for dev in $ext_devices; do
    # Get mountpoint info from lsblk output.
    mnt=$(echo "$lsblk_output" | grep -E "^$dev[[:space:]]" | awk '{print $4}')
    [ -z "$mnt" ] && mnt="Not mounted"
    echo -e "${WHITE}  Device: $dev    Mountpoint(s): $mnt${NC}"
done

print_step "Starting e2fsck checks on unmounted devices (timeout: ${TIMEOUT_SECS}s)..."

# Function to send an alert via syslog.
alert_failure() {
    local dev="$1"
    logger -t ext_fs_check "ALERT: Device $dev reported 'bad magic number in super-block'. Manual intervention may be required."
    echo -e "${RED}ALERT: $dev requires manual intervention! Check backups and consider superblock recovery.${NC}"
}

# Function to run e2fsck on a given device.
run_e2fsck() {
    local dev="$1"
    print_step ">>> Initiating filesystem check on $dev ..."
    local e2fsck_output retcode

    if [ "$DRY_RUN" -eq 1 ]; then
      echo "[DRY-RUN] Would run: e2fsck -f -y $dev"
      return 0
    fi

    # Run e2fsck with a configurable timeout.
    if command -v expect >/dev/null 2>&1; then
        e2fsck_output=$(timeout "${TIMEOUT_SECS}"s expect <<EOF
set timeout 60
spawn e2fsck -f -y "$dev"
expect {
    -re "\(y\/n\)" { send "y\r"; exp_continue }
    eof
}
EOF
)
        retcode=$?
    else
        print_step "Fallback: using 'yes' pipe for $dev"
        e2fsck_output=$(timeout "${TIMEOUT_SECS}"s yes | e2fsck -f -y "$dev")
        retcode=$?
    fi

    # Check for a superblock error.
    if echo "$e2fsck_output" | grep -qi "bad magic number in super-block"; then
        echo -e "${RED}WARNING: $dev reported a 'bad magic number in super-block'.${NC}"
        echo "Suggested manual recovery commands:"
        echo "    mke2fs -n $dev        # List backup superblock locations"
        echo "    e2fsck -b <backup> -y $dev  # Use a backup superblock (replace <backup> with one listed)"
        alert_failure "$dev"
        COUNT_ERRORS=$((COUNT_ERRORS+1))
        FAILED_DEVICES+=("$dev")
        return 1
    fi

    # Treat exit codes 0 and 1 as acceptable (1 means filesystem modified and repaired).
    if [ "$retcode" -eq 0 ] || [ "$retcode" -eq 1 ]; then
        if [ "$retcode" -eq 1 ]; then
            REPAIRED_DEVICES+=("$dev")
            echo -e "${CYAN}e2fsck completed on $dev with exit code $retcode: Filesystem modified.${NC}"
            echo -e "${CYAN}Last few lines of e2fsck output:${NC}"
            echo "$e2fsck_output" | tail -n 10
        else
            echo -e "${CYAN}e2fsck completed successfully on $dev (exit code $retcode)${NC}"
        fi
        COUNT_OK=$((COUNT_OK+1))
    else
        echo -e "${RED}ERROR: e2fsck failed on $dev with return code $retcode${NC}"
        COUNT_ERRORS=$((COUNT_ERRORS+1))
        FAILED_DEVICES+=("$dev")
    fi
    return 0
}

# Process each found device.
for dev in $ext_devices; do
    COUNT_TOTAL=$((COUNT_TOTAL+1))

    # Verify device is valid.
    if [ ! -b "$dev" ]; then
        echo -e "${RED}Skipping $dev: not a valid block device.${NC}"
        COUNT_SKIPPED=$((COUNT_SKIPPED+1))
        continue
    fi

    # Additional mount check using /proc/mounts.
    if grep -q "^$dev " /proc/mounts; then
        echo -e "${YELLOW}Skipping $dev: appears mounted as per /proc/mounts.${NC}"
        COUNT_SKIPPED=$((COUNT_SKIPPED+1))
        continue
    fi

    # Skip LUKS or ZFS devices.
    fstype=$(blkid -o value -s TYPE "$dev" 2>/dev/null || echo "")
    if [[ "$fstype" == "crypto_LUKS" || "$fstype" == "zfs_member" ]]; then
        echo -e "${YELLOW}Skipping $dev: unsupported filesystem type ($fstype).${NC}"
        COUNT_SKIPPED=$((COUNT_SKIPPED+1))
        continue
    fi

    # Pre-check: ensure device is readable.
    if ! dd if="$dev" bs=512 count=1 iflag=direct of=/dev/null 2>/dev/null; then
        echo -e "${RED}WARNING: Cannot read from $dev (I/O error). Skipping.${NC}"
        COUNT_SKIPPED=$((COUNT_SKIPPED+1))
        continue
    fi

    # Skip devices smaller than 100MB.
    fs_size=$(blockdev --getsize64 "$dev" 2>/dev/null || echo 0)
    if [ "$fs_size" -lt 104857600 ]; then
        echo -e "${YELLOW}Skipping $dev: size ($fs_size bytes) is less than 100MB.${NC}"
        COUNT_SKIPPED=$((COUNT_SKIPPED+1))
        continue
    fi

    # Run e2fsck on this device.
    run_e2fsck "$dev" || true
done

# Print final summary.
print_step "Summary:"
echo -e "${WHITE}  Devices processed  : $COUNT_TOTAL${NC}"
echo -e "${WHITE}  Devices skipped    : $COUNT_SKIPPED${NC}"
echo -e "${WHITE}  Successful checks  : $COUNT_OK${NC}"
echo -e "${WHITE}  Errors encountered : $COUNT_ERRORS${NC}"

if [ "${#REPAIRED_DEVICES[@]}" -gt 0 ]; then
    echo -e "${YELLOW}  Devices repaired   : ${REPAIRED_DEVICES[*]}${NC}"
fi

if [ "${#FAILED_DEVICES[@]}" -gt 0 ]; then
    echo -e "${RED}  Devices failed     : ${FAILED_DEVICES[*]}${NC}"
fi

# JSON summary output if requested.
if [ "$JSON_OUTPUT" -eq 1 ]; then
    echo
    echo -e "${WHITE}{"
    echo "  \"devices_total\": $COUNT_TOTAL,"
    echo "  \"devices_skipped\": $COUNT_SKIPPED,"
    echo "  \"checks_successful\": $COUNT_OK,"
    echo "  \"errors\": $COUNT_ERRORS,"
    echo "  \"devices_repaired\": \"${REPAIRED_DEVICES[*]}\","
    echo "  \"devices_failed\": \"${FAILED_DEVICES[*]}\""
    echo "}"
    echo -e "${NC}"
fi
