#!/bin/bash
# ----------------------------------------------------------------------------
# cloneLiveSystem  from  mcxSauce repo
# Author: Aleksi @ MCX, Matt
#
# This script clones a live source Linux system to a target node.
# The target node has a dual boot setup, with boot info on /dev/md1 (2xUSB stick)
# and bulk storage on an NVMe drive. The script also sets up overprovisioning
# for the NVMe drive.
#
# The reason for this approach is that some BIOS Firmware actively prevents booting
# from NVMe drives larger than 2TB unless the system is installed from a UEFI-booted
# installer and booted as UEFI. Unfortunately, this is an uncommon practice for PXE 
# installers and can # be difficult to implement. In particular, Debian preseeds
# have fatal shortcomings # which make it hard to adapt a preseed for this kind of setup.
# Adding a USB sticks for boot also adds more flexibility for end users.
#
# Usage: ./cloneLiveSystem.sh <source_hostname>
#
#
# This program is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with this program. If not, see <https://www.gnu.org/licenses/>.
# ----------------------------------------------------------------------------
set -e

# Ensure source system hostname is provided
if [ "$#" -ne 1 ]; then
    echo "Usage: $0 source_hostname"
    exit 1
fi

## Partition size templates ##
# Change as needed. All sizes are in gigabytes.
SWAP_SIZE="32"  # Size of the swap partition
BOOT_SIZE="1"   # Size of the boot partition
ROOT_SIZE="80"  # Size of the root partition
OVERPROV_PERCENT=1  # Overprovisioning percentage

## Helper functions ##
print_step() {
  echo -ne "\033[0;36m=> \033[1;37m"
  echo -n "$@"
  echo -e "\e[0m"
  [ -n "$DEBUG" ] && read -p "Paused for debugging. Press Enter to continue..." || true
}

refresh_partitions() {
  partprobe
  print_step "Waiting 2 seconds for disks to be rescanned..."
  sleep 2
}

# Ensure source system hostname is provided
if [ "$#" -ne 1 ]; then
    echo "Usage: $0 source_hostname"
    exit 1
fi
SOURCE_HOSTNAME="$1"

# Check for root permissions
if [ "$(id -u)" -ne 0 ]; then
    echo "This script must be run as root!"
    exit 1
fi

# Check if mdadm is installed
if ! command -v mdadm &> /dev/null; then
    echo "mdadm is not installed. Please install it first."
    exit 1
fi

# Cleanup and prepare the target disks
print_step "Cleaning up and preparing the target disks..."
swapoff -a
umount -l /dev/sda* || true
umount -l /dev/nvme0n1* || true
mdadm --stop --scan || true
for array in /dev/md*; do
   mdadm --stop "$array" || true
done
dd if=/dev/zero of=/dev/sda bs=1M count=100 &
dd if=/dev/zero of=/dev/sdb bs=1M count=100 &
wipefs -a /dev/sda
wipefs -a /dev/sdb
wipefs -a /dev/nvme0n1
blkdiscard /dev/nvme0n1
mdadm --zero-superblock /dev/sda || true
mdadm --zero-superblock /dev/sda1 || true
mdadm --zero-superblock /dev/sdb || true
mdadm --zero-superblock /dev/sdb2 || true
mdadm --zero-superblock /dev/nvme0n1 || true
refresh_partitions

# Create a new GPT partition table
print_step "Creating NVMe partitions..."
parted /dev/nvme0n1 mklabel gpt

# Create the swap partition
parted /dev/nvme0n1 mkpart primary linux-swap 0% ${SWAP_SIZE}GB

# Create the root partition
parted /dev/nvme0n1 mkpart primary ext4 ${SWAP_SIZE}GB $((${SWAP_SIZE} + ${ROOT_SIZE}))GB

# Create the /home partition, leaving some space unpartitioned for overprovisioning
parted /dev/nvme0n1 mkpart primary ext4 $((${SWAP_SIZE} + ${ROOT_SIZE}))GB $((100 - $OVERPROV_PERCENT))%

# Create boot partition
print_step "Creating USB partitions..."
parted /dev/sda mklabel msdos
parted /dev/sda mkpart primary ext4 0% ${BOOT_SIZE}GB
parted /dev/sda set 1 boot on
parted /dev/sda set 1 raid on
parted /dev/sda mkpart primary ext4 ${BOOT_SIZE}GB 100%

parted /dev/sdb mklabel msdos
parted /dev/sdb mkpart primary ext4 0% ${BOOT_SIZE}GB
parted /dev/sdb set 1 boot on
parted /dev/sdb set 1 raid on
parted /dev/sdb mkpart primary ext4 ${BOOT_SIZE}GB 100%



# Update the kernel about disk structure change
refresh_partitions

# Format the partitions
print_step "Formatting the partitions... Step1: Re-clear md superblock"
mdadm --stop /dev/md1  || true   # Sometimes it gets auto-assembled
mdadm --stop /dev/md127  || true   # Sometimes it gets auto-assembled
mdadm --zero-superblock /dev/sda1 || true
mdadm --zero-superblock /dev/sdb1 || true
wipefs -a /dev/sda1
wipefs -a /dev/sdb2
refresh_partitions
print_step "Create md1"
mdadm --create --quiet /dev/md1 -l1 -n2 /dev/sd[ab]1  --metadata=1.2

# Slow down resync for faster install, after reboot resumes at max speed
echo 5 > /sys/block/md1/md/sync_speed_max

print_step "Create swap"
mkswap /dev/nvme0n1p1
print_step "Create ext4 file systems"
mkfs.ext4 -F /dev/nvme0n1p2
mkfs.ext4 -F /dev/nvme0n1p3
mkfs.ext4 -F /dev/md1
mkfs.ext4 -F /dev/sda2
mkfs.ext4 -F /dev/sdb2

# Create necessary directories
echo "Creating necessary directories..."
mkdir -p /mnt/target /mnt/target/tmp
chmod 755 /mnt/target /mnt/target/tmp

# Mount the root, home and boot filesystems
print_step "Mounting filesystems..."
mount /dev/nvme0n1p2 /mnt/target
mkdir -p /mnt/target/home
mount /dev/nvme0n1p3 /mnt/target/home
mkdir -p /mnt/target/boot
mount /dev/md1 /mnt/target/boot
mkdir -p /mnt/target/mnt/usb1
mount /dev/sda2 /mnt/target/mnt/usb1
mkdir -p /mnt/target/mnt/usb2
mount /dev/sdb2 /mnt/target/mnt/usb2


# Pull the source system's data
print_step "Downloading root file system archive..."
rsync -aAXv -e "ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null" --exclude={"/dev/*","/proc/*","/sys/*","/tmp/*","/run/*","/mnt/*","/media/*","/lost+found"} root@$SOURCE_HOSTNAME:/ /mnt/target

#wget https://_STORAGE_SERVER_URI_/bookworm.tar.gz -O /dev/shm/bookworm.tar.gz
#print_step "Unpacking root file system..."
#tar xpf /dev/shm/bookworm.tar.gz --xattrs-include='*.*' --numeric-owner -C /mnt/target

# Bind mounts
print_step "Mounting virtual kernel file systems and preparing chroot..."
mount -t proc none /mnt/target/proc
mount -o bind /dev /mnt/target/dev
mount -o bind /sys /mnt/target/sys
mount -t devpts none /mnt/target/dev/pts




# Chroot into the new system
print_step "Entering the chroot environment..."
chroot /mnt/target /bin/bash <<EOF
# Set PATH to avoid issues when the live system does not include /sbin.
export PATH="/usr/sbin:/usr/bin:/sbin/bin"
# Strip out unique system identifiers.
echo "Stripping unique system identifiers..."
rm -f /etc/ssh/ssh_host_*
ssh-keygen -A
rm /etc/machine-id
systemd-machine-id-setup

sed -i '/^RESUME=UUID=6a784fef-4385-48d4-b12f-ca4419ec7e26/d' /etc/initramfs-tools/conf.d/resume


# Prefill /etc/fstab with basic settings.
cat > /etc/fstab <<END
# /etc/fstab: static file system information.
#
# Use 'blkid' to print the universally unique identifier for a
# device; this may be used with UUID= as a more robust way to name devices
# that works even if disks are added and removed. See fstab(5).
#
# <file system> <mount point>   <type>  <options>       <dump>  <pass>
/dev/nvme0n1p2 /               ext4    errors=remount-ro 0       1
/dev/nvme0n1p3 /home           ext4    defaults        0       2
/dev/md1       /boot           ext4    defaults        0       2
/dev/sda2      /mnt/usb1        ext4    defaults,nofail        0       2
/dev/sdb2      /mnt/usb2        ext4    defaults,nofail        0       2
/dev/nvme0n1p1 none            swap    sw              0       0
END

mdadm --detail --scan > /etc/mdadm/mdadm.conf

# Update initramfs and GRUB.
update-initramfs -u
update-grub

# Install GRUB to /dev/sda
grub-install /dev/sda
grub-install /dev/sdb

EOF

hostname=$(cat /proc/cmdline | grep -o 'hostname=[^ ]*' | cut -d= -f2)
ip_and_mask=$(ip -o -f inet addr show | awk '/scope global/ {print $4}')
ip_address=${ip_and_mask%%/*}
echo "${hostname}.pulsedmedia.com" > /mnt/target/etc/hostname

cat <<EOF > /mnt/target/etc/hosts
127.0.0.1       localhost
${ip_address}    ${hostname} ${hostname}.pulsedmedia.com

# The following lines are desirable for IPv6 capable hosts
::1     localhost ip6-localhost ip6-loopback
ff02::1 ip6-allnodes
ff02::2 ip6-allrouters
EOF





# Get Gateway
gateway=$(ip route | awk '/default/ {print $3}')

# Create the Debian /etc/network/interfaces file
cat <<EOF > /mnt/target/etc/network/interfaces
# This file describes the network interfaces available on your system
# and how to activate them. For more information, see interfaces(5).

source /etc/network/interfaces.d/*

# The loopback network interface
auto lo
iface lo inet loopback

# The primary network interface
auto eth0
iface eth0 inet static
    address $ip_and_mask
    gateway $gateway
EOF


# Open files that need to be manually edited.
nano /mnt/target/etc/hostname
nano /mnt/target/etc/hosts
nano /mnt/target/etc/network/interfaces

# Exit chroot and unmount filesystems
umount -R /mnt/target || echo "Filesystems could not be cleanly unmounted. Use lsof to check processes that are still accessing the drive, and umount -R /mnt/target to try again."
echo "Setup complete. Please reboot the system."
