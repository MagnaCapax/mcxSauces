# Engineering principles

How we build software across the MagnaCapax / Pulsed Media repositories, and why. Each rule says what to do, why we do it, and the mistake that taught it. The rules are the result of things going wrong; the reasons are there so you can apply them to cases this page never imagined.

Copied from [mcxPikkumies development/engineering-principles.md](https://github.com/MagnaCapax/mcxPikkumies/blob/main/development/engineering-principles.md) on 2026-10-18, adapted for this repository. Change the canonical copy first.

`AGENTS.md` in each repository holds the short form of these rules plus anything specific to that repository. Accepted decision records in `docs/adr/` are binding: follow them, or write a new ADR that supersedes one. Never quietly work around them.

## 1. PHP for logic, shell only as thin glue, no Python

**Do:** write anything that parses data, branches, keeps state, does arithmetic or calls an API in PHP. Shell is for pure command orchestration: a cron line, an exec wrapper, a few lines that start the real program. Keep a shell script under about 20 lines; past that, or as soon as it holds logic, it becomes PHP. No Python, no new Perl.

**Why:** shell is fragile exactly where logic lives. Nested quoting breaks silently, there are no real data structures, `set -e` misses failures inside pipes and process substitution, and JSON needs a chain of external tools. PHP has strings, arrays, `json_encode` and `json_decode`, regular expressions without quoting torture, and exceptions, and it is the language our platform (PMSS) already runs. One primary language means every maintainer can read every tool, and one runtime to keep current on every server.

**Taught by:**
- 2026-02: the maintainer, after yet another large launcher script broke: logic belongs in PHP; long shell launchers are too fragile.
- 2026-06-28: a host-recovery script grown in shell derived the wrong address to probe with a greedy pattern match and reported a healthy machine as stuck. Only a dry run stopped it from power-cycling that machine. It was rewritten in PHP.
- 2026-07-18: a shell tool applied a positional comma-separated parser to JSON Lines data, a correctness bug that a made-up test fixture then hid.
- A shell poller of about 500 lines built JSON by hand-escaping untrusted text, which let crafted input inject extra fields. `json_encode` makes that class of bug impossible.
- 13 recorded times, an agent picked shell because "the file next to it is shell". Decide from what the code does, never from the neighbour.

**Exceptions:** a repository whose runtime has no PHP (a live-boot image built from shell hooks, a browser driver that only exists for Node) records the exception and its reason in its own ADR. The reason must be a capability ("the image has no PHP"), never a preference.

## 2. Files around 150 lines

**Do:** aim for files of about 150 lines or fewer. Split a file when it grows past that or when it starts doing two jobs. It is a target, not a hard limit: a 170-line file with one clear job is fine; a 400-line file is a design smell. Do not mass-split old files for the number's sake; split a big file when you are changing it anyway.

**Why:** small files are reviewed properly, tested at their edges, and understood in one reading by a person or by a coding agent with limited context. A big file hides its second and third responsibility, and its bugs hide with them. Splitting also forces names onto the pieces, and good names are documentation.

**Taught by:**
- PMSS learned this first. Its contributor rules ask for files under about 150 lines, because smaller files simplify reviews, make edge-case testing easier and help grow coverage.
- Agent-written code grows by accretion. Creating is cheap and consolidating is expensive, so files only get bigger unless a target pushes back.
- In mcxPikkumies on 2026-10-18, 40 of 192 runtime PHP files were over 150 lines and the largest was 990. The largest files are the ones reviewers skim.

## 3. Names from broad to specific, in camelCase

**Do:** use camelCase for variables, functions, classes and files that hold code. Read the name from the broadest context to the most specific, so it explains itself without outside knowledge: `nodeCommandQueueLastCompletionMinutesAgo`, not `doneLast1h`. Name subject, event and unit. No verb without its object, no unexplained constant baked into a name, no abbreviation a newcomer would have to look up.

**Why:** a name is read many more times than it is written, often by someone who never saw the code it came from: a dashboard reader, the next agent, a consumer of a data field. A context-first name sorts related things together and tells you what a value holds and in what unit. One case convention across the repositories means nobody has to remember which file uses which.

**Taught by:**
- 2026-09-19: a proposed data field `doneLast1h` was rejected. "Done" what? And why one hour? The rule that came out of it is this one: biggest context first, self-describing, no magic constant in the name.
- 2026-03-21: a new script name was corrected to camelCase, to match its siblings.

## 4. Keep it simple; do not repeat yourself; do not build what nobody asked for (KISS, DRY, YAGNI)

**Do:** build the simplest thing that solves the real problem. Before adding anything, check whether the operating system, the platform or an existing tool already does it, and use that. When the same decision is made in two places, make it once and call it from both. Do not add settings, limits, validations or "defensive" features that no requirement asked for.

**Why:** every line is a line to read, test and keep working forever. Duplicated logic drifts: two copies of one rule always end up disagreeing on some edge case, and the bug lives in the gap. Speculative features cost the same upkeep as real ones and usually guess the future wrong.

**Taught by:**
- 2026-05-08: a request for a simple paced email sender came back as about 700 lines, with invented thresholds, name blacklists and a markdown renderer nobody asked for.
- 2026-05-14: three separate pieces of code each decided "was this an infrastructure failure?" with different rules. For months the same event was classified differently depending on which path saw it.
- A fixed 4,096-token ceiling was put on a tool's answers before anyone had measured a need for one. Measure first, then limit.

## 5. Delete first: the SpaceX order

**Do:** work through these steps in order.
1. Question the requirement: who asked for it, and is it real?
2. Delete the part or step if you can.
3. Simplify what remains.
4. Speed it up.
5. Automate it.

Most of the value is in steps 1 and 2. The best part is no part.

**Caveat:** step 2 applies to parts whose requirement is not real. A part that is needed but broken gets debugged and simplified, not deleted. SpaceX's first rockets failed three times; they fixed the rocket, they did not delete it.

**Why:** the cheapest code to maintain is code that does not exist, and optimizing or automating a step that should not exist wastes all the work done on it.

**Taught by:**
- 2026-03-19: a cleanup deleted more than seventeen files of no value (dead scripts, stale backups, orphaned logs, finished experiments). Six candidates were kept, because a review showed they still earned their place.
- 2026-05-11: a build process with a low success rate was proposed for deletion, when it was a needed tool with fixable bugs. That is how the caveat above came to be written down.

## 6. Pit of success: the safe path is the default

**Do:** make the right thing the easy thing. Defaults are safe. Anything risky, destructive or for debugging only needs an explicit, visible opt-in, and it says loudly what it is doing. A mistake should be hard to make and easy to notice.

**Why:** people and agents take the default path most of the time, under time pressure and without reading the manual. If the default is safe, the common case is safe. A rule that depends on everyone remembering it fails the first tired evening.

**Taught by:**
- Pikkumies keeps itself up to date by default, with one setting to turn that off (`autoUpdate.disabled`), rather than leaving every install to age until someone remembers to update it.
- A destructive action proposed by the agent asks first, and in an unattended run it is refused unless the person approved that exact command before.

## 7. Never break old users

**Do:** keep existing behaviour, interfaces, file formats and command-line options working for the people already using them. Before removing or changing a path, find every caller. When something must change, keep the old way working alongside, or migrate it automatically.

**Why:** our users run our software on their own servers and in their own scripts. They do not read our changelog before an update, and an update that breaks their setup costs their trust and our support time. Stability is a feature.

**Taught by:**
- PMSS serves accounts that are many years old on servers of several Debian generations. Its rules say outright that old users must not break.
- Pikkumies kept older Debian releases working by installing a standalone runtime where the system's one is too old, instead of dropping them (mcxPikkumies ADR-0014).

## 8. Tests that can fail

**Do:** every change comes with tests. A test must be able to fail. Break the code on purpose (a mutation), check that a test catches it, then restore the code. Test the real path, not a hand-built state the real code can never produce. Run the whole suite and read its result before you commit; never pipe it into something that hides the exit code.

**Why:** a test that cannot fail is false comfort. It reports green over the bug it was meant to catch.

**Taught by:**
- 2026-10-18: a review broke mcxPikkumies code three different ways, and the tests passed every time. One test used a record the real code could never produce. The tests were rewritten until each mutation failed.
- 2026-10-18: a commit went in with a failing test, because the test output was piped into a filter that swallowed the failure.

## 9. Decisions are written down, and binding

**Do:** a significant change to behaviour, interfaces, security or workflow ships with an ADR in `docs/adr/`: one subject per record, saying what was decided, why, and what was rejected. Accepted ADRs are binding. To change course, write a new ADR that supersedes the old one, and mark the old one superseded.

**Why:** code shows what the system does, not why. Without the why, the next person "fixes" a deliberate choice, or argues the same decision again. A record that can be quietly ignored is not a decision.

**Taught by:**
- 2026-08-04: a significant behaviour change in PMSS shipped without an ADR, and its review did not notice. Review checklists now ask for the record.
- In mcxPikkumies, a sample of recent commits (2026-10-16 and 10-17) showed feature ADRs kept up well, while some security changes touched no ADR. That is the gap the conformance review in its [review unit](https://github.com/MagnaCapax/mcxPikkumies/tree/main/pikkumies/tools/development/code-review) checks for.

## 10. Fail soft, and explain why in comments

**Do:** prefer to recover and continue when it is safe; stop only when going on would damage data or the system, and then say why. Comment the reasons, constraints and surprises; the code already says what it does.

**Why:** our tools run unattended on many servers, and a crash in a side task should not take the main job down with it. A comment that records why a line exists is what keeps the next person from removing a guard they did not understand.

## Using these rules

- Before you commit, run the [review unit](https://github.com/MagnaCapax/mcxPikkumies/tree/main/pikkumies/tools/development/code-review) on your change: it checks it against this repository's `AGENTS.md`, its ADRs and this page. Any agent can follow its `skill.md`.
- When a rule here seems wrong for a case, say so in the ADR for that change. Do not silently skip it.
