# Development

Start here if you change this repository, by hand or with a coding agent.

## Read first

1. [`AGENTS.md`](../AGENTS.md): the rules for this repository, short.
2. [Engineering principles](engineering-principles.md): the same rules with their reasons and the mistakes that taught them. Copied from the canonical copy in mcxPikkumies; change that one first.
3. [Decision records](../docs/adr/): accepted ADRs are binding. A significant change ships with a new one ([template](../docs/adr/0000-template.md)). Start with [ADR-0001](../docs/adr/0001-architecture-layout-and-languages.md), which describes the layout and the languages.
4. [README](../README.md): what the repository is for, how to fetch and run a script, and the disclaimer.
5. [Slot-release algorithm README](../whmcs/eternal-vainamoinen/README.md): how the release engine works and how to verify it.

## Before you commit

There is no CI and no repository-wide test suite. Run what exists:

- `php whmcs/eternal-vainamoinen/EternalVainamoinenReleaseTest.php` passes when you touch that directory (it prints `ALL 16 TESTS PASSED` and exits 0; a failure exits non-zero). Read the summary and the exit code; do not pipe it through anything that hides them.
- `php -l <file>` on every PHP file you changed, and `bash -n <file>` on every shell script you changed.
- `php whmcs/hooks/tests/blacklist-and-cap-test.php` passes when you touch `payer_blocklist.php` or `product_per_client_cap.php` (pure decisions only, no database; exit 0 when all pass).
- The other WHMCS hooks have no automated tests. A hook change is checked in a WHMCS installation after deploy; say in the commit what you checked.
- New behaviour has a test that fails when the behaviour is broken (break it once and see).
- Review the change with the [review unit](https://github.com/MagnaCapax/mcxPikkumies/tree/main/pikkumies/tools/development/code-review): correctness, an adversarial pass, weakness classes, and conformance to `AGENTS.md`, the ADRs and the principles. Any agent can follow its `skill.md`.
- No model provider or model name, no internal hostname or address, no credential, no customer data and nothing else that is not meant for the public: this repository is public.

## Other MagnaCapax repositories

Each has its own `AGENTS.md`, decision records and docs. When your change touches another project, read its records first.

| Repository | What it is | Docs | Decisions |
|---|---|---|---|
| [PMSS](https://github.com/MagnaCapax/PMSS) | Pulsed Media Seedbox Software, the platform Pikkumies ships on | [docs](https://github.com/MagnaCapax/PMSS/tree/main/docs) | [ADRs](https://github.com/MagnaCapax/PMSS/tree/main/docs/adr) |
| [mcxPikkumies](https://github.com/MagnaCapax/mcxPikkumies) | Lightweight helper agent that runs on the server it manages; holds the canonical engineering principles | [docs](https://github.com/MagnaCapax/mcxPikkumies/tree/main/docs) | [ADRs](https://github.com/MagnaCapax/mcxPikkumies/tree/main/docs/adr) |
| [mcxVirsilipas](https://github.com/MagnaCapax/mcxVirsilipas) | Prepaid token-metering proxy for model APIs | [docs](https://github.com/MagnaCapax/mcxVirsilipas/tree/main/docs) | [ADRs](https://github.com/MagnaCapax/mcxVirsilipas/tree/main/docs/adr) |
| [mcxForge](https://github.com/MagnaCapax/mcxForge) | Bare-metal qualification, repair and installation suite | [docs](https://github.com/MagnaCapax/mcxForge/tree/main/docs) | [ADRs](https://github.com/MagnaCapax/mcxForge/tree/main/docs/adr) |
| [mcxRescue](https://github.com/MagnaCapax/mcxRescue) | Rescue live system for bare-metal servers | [docs](https://github.com/MagnaCapax/mcxRescue/tree/main/docs) | [ADRs](https://github.com/MagnaCapax/mcxRescue/tree/main/docs/adr) |
| [mcxTemplate](https://github.com/MagnaCapax/mcxTemplate) | Templating engine for distribution installs | [docs](https://github.com/MagnaCapax/mcxTemplate/tree/main/docs) | [ADRs](https://github.com/MagnaCapax/mcxTemplate/tree/main/docs/adr) |
| [nodeAgent](https://github.com/MagnaCapax/nodeAgent) | Server telemetry and hardware health agent | [docs](https://github.com/MagnaCapax/nodeAgent/tree/main/docs) | [ADRs](https://github.com/MagnaCapax/nodeAgent/tree/main/docs/adr) |
| [mcxSerp](https://github.com/MagnaCapax/mcxSerp) | Search results API client for PHP | [docs](https://github.com/MagnaCapax/mcxSerp/tree/main/docs) | [ADRs](https://github.com/MagnaCapax/mcxSerp/tree/main/docs/adr) |
| [mcxSauces](https://github.com/MagnaCapax/mcxSauces) | This repository | [docs](https://github.com/MagnaCapax/mcxSauces/tree/main/docs) | [ADRs](https://github.com/MagnaCapax/mcxSauces/tree/main/docs/adr) |
| [mcxBMCView](https://github.com/MagnaCapax/mcxBMCView) | BMC console screenshot and keyboard automation | [docs](https://github.com/MagnaCapax/mcxBMCView/tree/main/docs) | [ADRs](https://github.com/MagnaCapax/mcxBMCView/tree/main/docs/adr) |
