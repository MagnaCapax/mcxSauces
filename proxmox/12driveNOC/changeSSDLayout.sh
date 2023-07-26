#!/bin/bash

# Title: changeSSDLayout.sh
# Description: mcxSauces Script to change SSD layout on basic noc-ps Debian installs
#              of certain 12x3.5" + 2x2.5" systems for use with Proxmox and bcache.

printUsage() {
    echo "Usage: $0 <Drive>"
    echo "Example: $0 sdn"
    exit 1
}

checkDriveSpecified() {
    if [ -z "$drive" ]; then
        echo "No drive specified."
        printUsage
    fi
}

partitionDrive() {
    fullDrivePath="/dev/$drive"
    partitionTable=$(parted $fullDrivePath print | grep -o 'Partition Table:.*' | cut -d' ' -f3)
    
    if [ "$partitionTable" == "gpt" ] || [ "$partitionTable" == "msdos" ]; then
        echo "Detected $partitionTable partition table. Using parted..."
        partedCommands
    else
        echo "Unrecognized or no partition table found."
        exit 1
    fi
}

partedCommands() {
    umount ${fullDrivePath}3 || true

    oldPartStart=$(parted $fullDrivePath unit s print | grep "^ 3" | awk '{print $2}')
    oldPartEnd=$(parted $fullDrivePath unit s print | grep "^ 3" | awk '{print $3}')
    oldPartStart=${oldPartStart%s}
    oldPartEnd=${oldPartEnd%s}

    parted --script $fullDrivePath rm 3

    swapEnd=$(($oldPartStart + 32*1024*1024*1024/512 - 1))
    parted --script $fullDrivePath mkpart primary linux-swap ${oldPartStart}s ${swapEnd}s

    totalSectors=$(parted $fullDrivePath unit s print | grep Disk | awk '{print $3}')
    totalSectors=${totalSectors%s}
    onePercentSectors=$(($totalSectors / 100))

    dataStart=$(($swapEnd + 1))
    dataEnd=$(($totalSectors - $onePercentSectors))

    if [ "$partitionTable" == "gpt" ]; then
        parted --script $fullDrivePath mkpart primary ${dataStart}s ${dataEnd}s
    else
        parted --script $fullDrivePath mkpart primary ext4 ${dataStart}s ${dataEnd}s
    fi

    partprobe $fullDrivePath
    mkswap ${fullDrivePath}3
    swapon ${fullDrivePath}3
    echo "${fullDrivePath}3 none swap defaults 0 0" >> /etc/fstab
}

drive=$1
checkDriveSpecified
partitionDrive
