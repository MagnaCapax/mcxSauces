# ADR-0001: Architecture, layout and languages

## Metadata
- Author: Aleksi Ursin / MCX
- Date: 2026-10-18
- Status: Accepted

## Context
mcxSauces is a collection of scripts and configuration that Magna Capax uses to run its systems, published for others to read and reuse (see the [README](../../README.md) and its disclaimer). It has no build, no shared library and no runtime of its own: each file is used on its own, on the system it was written for.

Until now the repository had no decision records and no contributor rules. This record writes down what the repository actually holds, so that new work follows the [engineering principles](../../development/engineering-principles.md) and old files are improved when they are changed anyway, not rewritten for their own sake.

## What is here

The tree below is the tracked content on 2026-10-18. The structure drawn in the README (`shared`, `Proxmox/VM_Hosts`, `PMSS`, and so on) describes an intended layout; the directories that exist are these:

| Path | What it owns | Language |
|---|---|---|
| `assets/namingscheme` | A plain list of names, one per line, used for naming systems. | Text |
| `assets/randomName.sh` | Fetches `namingscheme` from GitHub and prints a random entry, optionally in l33t form. | Bash |
| `baremetal/mdSeries/` | Bare-metal maintenance for one server series: `checkFilesystemsExt4.sh` checks unmounted ext filesystems with `e2fsck`; `cloneLiveSystem.sh` clones a live system to a target with boot on USB RAID and data on NVMe. | Bash |
| `baremetal/sas2ircu-led-control.sh` | Drive locate LED control for SAS HBAs through `sas2ircu`/`sas3ircu`, with a dd-blink fallback. Its specification is `baremetal/sas2ircu-led-tool-dev-sop.md`. | Bash |
| `proxmox/12driveNOC/changeSSDLayout.sh` | Changes the SSD layout of one 12-bay server type for use with Proxmox and bcache. | Bash |
| `whmcs/hooks/` | WHMCS hooks, one file per concern (fraud and abuse checks at checkout and registration, ticket auto-close, invoice clean-up, an announcements RSS generator, a per-customer product cap). Each file is deployed on its own into a WHMCS installation's `includes/hooks/`. | PHP |
| `whmcs/eternal-vainamoinen/` | A pure PHP slot-release engine for a timed-availability campaign, with its tests, a simulator and a scenario driver. No database, clock or I/O; its [README](../../whmcs/eternal-vainamoinen/README.md) explains it. | PHP |
| `log/` | Runtime logs written by the WHMCS hooks (for example the checkout antifraud hook). Ignored by `.gitignore`; never committed. | (data) |

## Options Considered
- **Leave the repository without rules.** Every new script picks its own language, naming and size. Rejected: the repository already holds three naming styles and shell scripts of several hundred lines, and nothing would stop that growing.
- **Rewrite the existing shell scripts in PHP now.** Rejected: principle 5 (question the requirement) and principle 2 (do not mass-change old files for a rule's sake). The README says some scripts are in active use and some are kept as a snapshot; they are rewritten when they are changed anyway and the change adds logic.
- **Adopt the organisation's principles for new work and for files being changed (chosen).**

## Decision

### Languages
- **New logic is PHP** (principle 1). Anything that parses data, branches, keeps state, does arithmetic or calls an API is written in PHP. PHP is already the largest part of this repository (about 3,400 of its 4,850 lines of code, all under `whmcs/`), it is what WHMCS itself runs, and it is the language of the rest of our platform, so one maintainer can read every file. The reasons shell is the wrong home for logic are in principle 1.
- **Shell only as thin glue**, about 20 lines or fewer: starting a program, a cron line, an exec wrapper. No Python, no new Perl.
- **Existing shell scripts stay as they are** until they are changed. When a change adds logic to a long shell script, move that logic to PHP in the same change.
- **Exception, by capability only.** A script that must run where PHP is not installed (for example a minimal or rescue system) may stay shell, and says so in its header: "this runs on X, which has no PHP". A preference is not a reason.
- WHMCS hooks are PHP by necessity: WHMCS loads hooks as PHP files.

### Layout
- Group files by the system they run on (`baremetal/`, `proxmox/`, `whmcs/`, `assets/` for shared data), then by purpose or hardware type. Add a top-level directory only for a new kind of system.
- A self-contained component that is more than one file gets its own directory and a README (as `whmcs/eternal-vainamoinen/` does).
- Runtime output (logs, state) never goes into tracked files; it goes under an ignored path such as `log/`.

### Build, run and test
- There is no build step and no CI.
- Shell scripts are run directly on their target system, as the README describes; read a script before running it, especially as root.
- WHMCS hooks are copied into `<whmcs_root>/includes/hooks/`, as each hook's header states. Their behaviour is checked in a WHMCS installation after deploy. Newer hooks (`payer_blocklist.php`, `product_per_client_cap.php`) register with WHMCS only when `add_hook` exists and `ROOTDIR` is defined, so the file can be loaded outside WHMCS without registering anything; new hooks follow that pattern, which also lets a plain-PHP test load their functions.
- The release engine has plain-PHP tests: `php whmcs/eternal-vainamoinen/EternalVainamoinenReleaseTest.php` (exit 0 when all pass) and a simulator, `php whmcs/eternal-vainamoinen/sim-scenarios.php`.
- Before committing: the relevant tests pass, and `php -l` / `bash -n` pass on changed files.

### Existing conventions (as found)
- PHP hook files open with a `WHY THIS EXISTS` block, then deploy notes and a license header. Keep that: it is principle 10 (comment the reasons).
- Hook files are named in snake_case (`payer_blocklist.php`) and their functions carry a short prefix per hook (`pm_pbl_`, `pm_ppc_`). Shell scripts are mostly camelCase (`cloneLiveSystem.sh`, `randomName.sh`); one is kebab-case (`sas2ircu-led-control.sh`). The release engine uses PascalCase class files.
- Licensing is stated per file and is mixed: the repository `LICENSE` is GPL-3.0; 10 of the 14 PHP files carry an `SPDX-License-Identifier: Apache-2.0` header; `clientadd_skeleton_detect.php` and the two `mdSeries` scripts state GPL-3.0; the sas2ircu specification names MIT. This record does not settle licensing; state the license in the header of a new file.

### How the principles apply here
- **1, PHP for logic:** as above.
- **2, files around 150 lines:** new files aim for it. Several existing files are longer (the longest hook is about 690 lines, `sas2ircu-led-control.sh` about 670); split them when changing them, not before.
- **3, names broad to specific in camelCase:** new variables, functions and files use camelCase, read from the broadest context to the most specific (a hook function starts with its subject, e.g. `payerBlocklistLoad`, not `pm_pbl_load`). Existing file names stay: each is a deploy path or a published URL (principle 7).
- **4 and 5, KISS, DRY, YAGNI, delete first:** a script does one job on one kind of system. Check whether WHMCS, the OS or an existing script already does it before adding one.
- **6, pit of success:** a new or changed script that is destructive (filesystem repair, cloning, disk layout changes) defaults to the safe path, prints what it will do, and needs an explicit option to proceed.
- **7, never break old users:** people fetch these scripts by URL (README). Do not move or rename a published script without leaving the old path working or noting the move.
- **8, tests that can fail:** PHP logic comes with a plain-PHP test like `EternalVainamoinenReleaseTest.php`; break the code once to see the test fail.
- **9, ADRs:** a significant behaviour, interface, security or workflow change ships with an ADR in `docs/adr/`.
- **10, fail soft and explain why:** most hooks catch their own errors and fail open, so a broken check does not stop checkout, tickets or mail (`cancel_addfunds_invoices.php` and `autoclose_autoresponder_tickets.php` say so in comments). New hooks do the same unless failing open would let the harm through, and say which in a comment.

## Consequences
- New contributors and coding agents have one place that says what lives where and in which language.
- The repository keeps its mixed past: long shell scripts and three naming styles remain until someone changes those files.
- Hooks still have no automated test harness; that is a known gap, closed hook by hook as they are changed.

## References
- [Engineering principles](../../development/engineering-principles.md)
- [README](../../README.md)
- [Slot-release algorithm README](../../whmcs/eternal-vainamoinen/README.md)
