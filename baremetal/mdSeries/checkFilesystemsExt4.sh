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
set -e

### Options & Defaults ###
DRY_RUN=0
JSON_OUTPUT=0
NO_COLOR=0

# Process command-line options.
for arg in "$@"; do
    case "$arg" in
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
        *)
            ;;
    esac
done

# Logging file in /tmp
LOGFILE="/tmp/ext_fs_check.log"
exec > >(tee -a "$LOGFILE") 2>&1

# ANSI color codes or plain text if --no-color is given.
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

# Retrieve block device info using lsblk. Use full paths (-p) and no headers (-n).
# Also remove any tree-format characters using sed.
lsblk_output=$(lsblk -pn -o NAME,FSTYPE,TYPE,MOUNTPOINT 2>/dev/null | sed -E 's/^[^\/]*//')

# Extract unique device paths with FSTYPE starting with "ext".
ext_devices=$(echo "$lsblk_output" | awk '$2 ~ /^ext/ {print $1}' | sort -u)

echo -e "${YELLOW}Devices with EXT filesystems found:${NC}"
for dev in $ext_devices; do
    # Get mountpoint from lsblk output.
    mnt=$(echo "$lsblk_output" | grep -E "^$dev[[:space:]]" | awk '{print $4}')
    [ -z "$mnt" ] && mnt="Not mounted"
    echo -e "${WHITE}  Device: $dev    Mountpoint(s): $mnt${NC}"
done

print_step "Starting e2fsck checks on unmounted devices..."

# Function to run e2fsck on a given device.
run_e2fsck() {
    local dev="$1"
    print_step ">>> Initiating filesystem check on $dev ..."
    local e2fsck_output
    local retcode
    # If dry-run, simply print the intended command.
    if [ "$DRY_RUN" -eq 1 ]; then
      echo "[DRY-RUN] Would run: e2fsck -f -y $dev"
      return 0
    fi

    # Check if 'expect' is available. If so, use it.
    if command -v expect >/dev/null 2>&1; then
        e2fsck_output=$(expect <<EOF
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
        # Use the "yes" command to provide many y's.
        e2fsck_output=$(yes | e2fsck -f -y "$dev")
        retcode=$?
    fi

    # Check for a superblock error in the output.
    if echo "$e2fsck_output" | grep -qi "bad magic number in super-block"; then
        echo -e "${RED}WARNING: $dev reported a 'bad magic number in super-block'.${NC}"
        echo "This indicates a possible superblock corruption."
        echo "Suggested manual recovery commands:"
        echo "    mke2fs -n $dev        # To list backup superblock locations"
        echo "    e2fsck -b <backup> -y $dev  # To try using a backup superblock (replace <backup> with one of the listed blocks)"
        COUNT_ERRORS=$((COUNT_ERRORS+1))
        return 1
    fi

    # If the return code is non-zero, count as an error.
    if [ "$retcode" -ne 0 ]; then
        echo -e "${RED}ERROR: e2fsck failed on $dev with return code $retcode${NC}"
        COUNT_ERRORS=$((COUNT_ERRORS+1))
        return $retcode
    else
        echo -e "${CYAN}e2fsck completed successfully on $dev${NC}"
        COUNT_OK=$((COUNT_OK+1))
    fi
    return 0
}

# Process each found device.
for dev in $ext_devices; do
    COUNT_TOTAL=$((COUNT_TOTAL+1))
    # Verify the device exists and is a block device.
    if [ ! -b "$dev" ]; then
        echo -e "${RED}Skipping $dev: not a valid block device.${NC}"
        COUNT_SKIPPED=$((COUNT_SKIPPED+1))
        continue
    fi

    # Additional mounted check using /proc/mounts.
    if grep -q "^$dev " /proc/mounts; then
        echo -e "${YELLOW}Skipping $dev: device appears mounted as per /proc/mounts.${NC}"
        COUNT_SKIPPED=$((COUNT_SKIPPED+1))
        continue
    fi

    # Check for LUKS or ZFS: use blkid.
    fstype=$(blkid -o value -s TYPE "$dev" 2>/dev/null || echo "")
    if [[ "$fstype" == "crypto_LUKS" || "$fstype" == "zfs_member" ]]; then
        echo -e "${YELLOW}Skipping $dev: filesystem type ($fstype) is not supported for e2fsck.${NC}"
        COUNT_SKIPPED=$((COUNT_SKIPPED+1))
        continue
    fi

    # Run e2fsck on the device.
    run_e2fsck "$dev"
done

# Print summary.
print_step "Summary:"
echo -e "${WHITE}  Devices processed  : $COUNT_TOTAL${NC}"
echo -e "${WHITE}  Devices skipped    : $COUNT_SKIPPED${NC}"
echo -e "${WHITE}  Successful checks  : $COUNT_OK${NC}"
echo -e "${WHITE}  Errors encountered : $COUNT_ERRORS${NC}"

# If JSON output was requested, print a JSON summary.
if [ "$JSON_OUTPUT" -eq 1 ]; then
    echo
    echo -e "${WHITE}{"
    echo "  \"devices_total\": $COUNT_TOTAL,"
    echo "  \"devices_skipped\": $COUNT_SKIPPED,"
    echo "  \"checks_successful\": $COUNT_OK,"
    echo "  \"errors\": $COUNT_ERRORS"
    echo "}"
    echo -e "${NC}"
fi
