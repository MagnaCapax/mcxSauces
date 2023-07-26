#!/bin/bash
# ----------------------------------------------------------------------------
# mcxSauce repo
# Author: Aleksi @ MCX
# 
# This script clones a live source Linux system to a target node.
# The target node has a dual boot setup, with boot info on /dev/sda (USB stick) 
# and bulk storage on an NVMe drive. The script also sets up overprovisioning 
# for the NVMe drive.
# 
# The reason for this approach is that some BIOS Firmware actively prevents booting
# from NVMe drives larger than 2TB unless the system is installed from a UEFI-booted
# installer. Unfortunately, this is an uncommon practice for PXE installers and can 
# be difficult to implement. In particular, Debian preseeds have fatal shortcomings 
# which make it hard to adapt a preseed for this kind of setup. 
# Adding a USB stick for boot also adds more flexibility for end users.
# 
# Usage: ./clone_linux_system.sh <source_hostname>
# Example: ./clone_linux_system.sh server1
# ----------------------------------------------------------------------------
set -e

# Ensure source system hostname is provided
if [ "$#" -ne 1 ]; then
    echo "Usage: $0 source_hostname"
    exit 1
fi

SOURCE_HOSTNAME=$1

# Partition size templates (change these as needed)
SWAP_SIZE="32"  # Size of the swap partition
BOOT_SIZE="1"   # Size of the boot partition
ROOT_SIZE="80"  # Size of the root partition
OVERPROV_PERCENT=1  # Overprovisioning percentage

# Cleanup and prepare the target disks
echo "Cleaning up and preparing the target disks..."
swapoff -a
umount -l /dev/sda* || true
umount -l /dev/nvme0n1* || true
mdadm --stop --scan || true
wipefs -a /dev/sda
wipefs -a /dev/nvme0n1
partprobe

# Create partitions as per the provided scheme
echo "Creating partitions..."

# Create a new GPT partition table
parted /dev/nvme0n1 mklabel gpt

# Create the swap partition
parted /dev/nvme0n1 mkpart primary linux-swap 0% ${SWAP_SIZE}GB

# Create the root partition
parted /dev/nvme0n1 mkpart primary ext4 ${SWAP_SIZE}GB $((${SWAP_SIZE} + ${ROOT_SIZE}))GB

# Create the /home partition, leaving some space unpartitioned for overprovisioning
parted /dev/nvme0n1 mkpart primary ext4 $((${SWAP_SIZE} + ${ROOT_SIZE}))GB $((100 - $OVERPROV_PERCENT))%

# Create boot partition
parted /dev/sda mklabel msdos
parted /dev/sda mkpart primary ext4 0% ${BOOT_SIZE}GB

# Create additional partition for /mnt/usb
parted /dev/sda mkpart primary ext4 ${BOOT_SIZE}GB 100%

# Update the kernel about disk structure change
partprobe

# Format the partitions
echo "Formatting the partitions..."
mkswap /dev/nvme0n1p1
mkfs.ext4 /dev/nvme0n1p2
mkfs.ext4 /dev/nvme0n1p3
mkfs.ext4 /dev/sda1
mkfs.ext4 /dev/sda2

# Create necessary directories
echo "Creating necessary directories..."
mkdir -p /mnt/target /mnt/target/tmp
chmod 755 /mnt/target /mnt/target/tmp

# Mount the root, home and boot filesystems
mount /dev/nvme0n1p2 /mnt/target
mkdir -p /mnt/target/home
mount /dev/nvme0n1p3 /mnt/target/home
mkdir -p /mnt/target/boot
mount /dev/sda1 /mnt/target/boot
mkdir -p /mnt/target/mnt/usb
mount /dev/sda2 /mnt/target/mnt/usb

# Pull the source system's data
echo "Pulling data from source system..."
ssh root@$SOURCE_HOSTNAME 'cd / && tar --exclude={"/dev/*","/proc/*","/sys/*","/tmp/*","/run/*","/mnt/*","/media/*","/lost+found"} -cp --one-file-system --numeric-owner .' | tar xpvf - -C /mnt/target

# Bind mounts
mount -t proc none /mnt/target/proc
mount -o bind /dev /mnt/target/dev
mount -o bind /sys /mnt/target/sys
mount -t devpts none /mnt/target/dev/pts

# Chroot into the new system
echo "Entering the chroot environment..."
chroot /mnt/target /bin/bash <<EOF

# Strip out unique system identifiers.
echo "Stripping unique system identifiers..."
rm -f /etc/ssh/ssh_host_*
dpkg-reconfigure openssh-server
rm /etc/machine-id
systemd-machine-id-setup

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
/dev/sda1      /boot           ext4    defaults        0       2
/dev/sda2      /mnt/usb        ext4    defaults        0       2
/dev/nvme0n1p1 none            swap    sw              0       0
END

# Open files that need to be manually edited.
nano /etc/hostname
nano /etc/network/interfaces

# Update initramfs and GRUB.
update-initramfs -u
update-grub

# Install GRUB to /dev/sda
grub-install /dev/sda

echo "Setup complete. Please reboot the system."

EOF

# Exit chroot and unmount filesystems
umount -l /mnt/target/dev/pts
umount -l /mnt/target/dev
umount -l /mnt/target/proc
umount -l /mnt/target/sys
umount -l /mnt/target/boot
umount -l /mnt/target/mnt/usb
umount -l /mnt/target/home
umount -l /mnt/target
