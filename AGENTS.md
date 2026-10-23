# mcxSauces: notes for contributors and coding agents

## What this is

Scripts and configuration Magna Capax uses to run its systems, published as-is for others to read and reuse. Files are grouped by the system they run on: `baremetal/`, `proxmox/`, `whmcs/` (WHMCS hooks and a slot-release engine), `assets/` for shared data. There is no build and no shared runtime; each file is used on its own. [ADR-0001](docs/adr/0001-architecture-layout-and-languages.md) describes the layout and the languages.

Start with [`development/`](development/README.md): the [engineering principles](development/engineering-principles.md) explain every rule below with its reason and the mistake that taught it, and list the sibling repositories' docs and decisions.

## Principles

KISS, DRY, YAGNI. Delete first: question the requirement, delete the part, simplify, then speed up, then automate (a needed but broken part is debugged, not deleted). Pit of success: safe defaults, risky paths explicit and loud. Never break old users. Tests that can fail. Files around 150 lines (a target, not a hard limit). Accepted ADRs are binding: follow them or supersede them with a new ADR, never work around them. Before committing, review the change with the [review unit](https://github.com/MagnaCapax/mcxPikkumies/tree/main/pikkumies/tools/development/code-review) against these rules and the ADRs.

## Rules

- **Language:** new logic is PHP. Shell only as thin glue (about 20 lines, no logic). No Python, no new Perl. A script that must run where there is no PHP may stay shell and says so in its header. Existing shell scripts are not rewritten for their own sake; logic added to one moves to PHP in the same change.
- **Naming:** camelCase for new variables, functions and files, read from the broadest context to the most specific. Existing file names stay: they are deploy paths and published URLs.
- **Old files:** apply these rules to an old file when you change it anyway. Do not mass-split, rename or rewrite.
- **WHMCS hooks:** one concern per file, deployed into `<whmcs_root>/includes/hooks/`. Start with a `WHY THIS EXISTS` comment and deploy notes. Register with `add_hook` only when it exists and `ROOTDIR` is defined, so the file can be loaded and tested outside WHMCS.
- **Runtime output** (logs, state) goes under an ignored path such as `log/`, never into tracked files.
- **ADRs:** a significant behaviour, interface, security or workflow change ships with an ADR in `docs/adr/` (template `0000-template.md`, one subject per ADR).
- **Tests:** run `php whmcs/eternal-vainamoinen/EternalVainamoinenReleaseTest.php` when touching that directory, `php whmcs/hooks/tests/blacklist-and-cap-test.php` when touching `payer_blocklist.php` or `product_per_client_cap.php`, and `php -l` / `bash -n` on every changed file. New PHP logic comes with a plain-PHP test that fails when the logic is broken.
- **Public repository.** Nothing that is not meant for the public: no credentials or default passwords, no internal hostnames, addresses or paths, no customer data, no model provider or model names.
