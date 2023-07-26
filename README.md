
# mcxSauces

This repository hosts all of the scripts and configuration files for Magna Capax's various systems. 

The goal of our hierarchy philosophy is to organize resources in a way that reflects their use cases and their associated systems. We've structured the repository into categories that make sense for our specific systems and scripts, making it intuitive for users to navigate.

## Repository Structure

Here's an overview of our repository structure:

```bash
mcxSauces
│
├── README.md
│
├── shared (Things useable in baremetal, VM hosts & Guests etc.)
│    ├── NamingSchemes
│    ├── CommonScripts
│    └── CommonConfigs
│
├── Proxmox
│    ├── VM_Hosts
│    │    ├── Setup
│    │    └── Maintenance
│    └── HardwareSpecific
│         ├── HardwareType1
│         └── HardwareType2
│
... (similar structure for Baremetal, PMSS, DC Stuff, and Monitoring)
```

Each system type has its own directory (e.g., `Proxmox`, `Baremetal`, `PMSS`), which are further categorized by function (`Setup`, `Maintenance`) and/or specific hardware type. 

The `CrossSystem` (or `SharedResources`) directory houses resources that are common across multiple systems.

## Fetching and Executing a Script Directly From Repo
To fetch and execute a specific script directly from the command line, you can use `curl` or `wget`. Here's an example:

```bash
curl -s https://raw.githubusercontent.com/MagnaCapax/mcxSauces/main/Proxmox/VM_Hosts/Setup/my_script.sh | bash
```

Or, if you prefer `wget`:

```bash
wget -qO- https://raw.githubusercontent.com/MagnaCapax/mcxSauces/main/Proxmox/VM_Hosts/Setup/my_script.sh | bash
```

Replace the URL with the path to your actual script.

**WARNING**: Executing scripts in this manner should only be done if you completely trust the script to do what you wanted, as it opens the potential for big mistakes. It's generally better to download the script, inspect it to make sure it's safe, and then run it.


## Accessing Repo and Executing a Script - The long boring way
To get and execute a bash script from this repository, follow these steps in a Debian shell:

1. First, you need to clone this repository to your local machine. You can do this using the `git` command:

```
git clone https://github.com/MagnaCapax/mcxSauces.git
```

2. Navigate to the directory containing the script you wish to execute. For example:

```
cd mcxSauces/Proxmox/VM_Hosts/Setup
```

3. Ensure the script is executable. If you're not sure, you can add executable permissions to the script using the `chmod` command:

```
chmod +x my_script.sh
```

4. Now, you can run the script directly:

```
./my_script.sh
```

Note: Always make sure you know what a script does before executing it, especially when running as root.


## Disclaimer and Warranties

This repository has been built primarily for our own internal needs at Magna Capax, but we believe in the power of sharing. These tools, scripts, and configurations are used within our own environment, and we make them available publicly in the hopes that they may be helpful to others.

However, please be aware of the following:

- We provide these resources as-is, without warranty of any kind.
- These resources may or may not be maintained regularly, or at all. Our commitment to update or maintain these resources will be based on our own needs and time constraints.
- While we strive to make this repository reflect the tools and scripts we use every day, it may not always be a 1:1 mirror. Some resources we use may not be shared here, and some resources here may not be in active use in our environment.
- This repository may also serve as a form of documentation for us. As such, some scripts or tools may be more of a snapshot of a point in time rather than something we actively use.

Please keep these points in mind when using or referencing this repository. As always, we encourage you to thoroughly review any code or resources before deploying them in your own environment.
