---
description: 'Gherkin and Behat conventions for review — how a feature file must read, and how to tell whether it is actually tested'
applyTo: "{features/**/*.feature,features/README.md,tests/integration/**}"
---
<!--
  SPDX-FileCopyrightText: 2026 Kelly Ferrone
  SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Gherkin conventions — the spec, and whether it is real

`features/*.feature` is this app's **specification**, written before the code and
kept true after it. Review it as documentation that happens to execute.

**Read [`features/README.md`](../../features/README.md) first** — it is the
authority on layout and tags, and this file is the review checklist that follows
from it. Where the two disagree, `features/README.md` wins and this file is stale.

## Before saying anything about coverage, check the binding

| What | Where |
|---|---|
| The scenarios | `features/*.feature` |
| The step definitions | `tests/integration/bootstrap/Steps/*.php` |
| The context that composes them | `tests/integration/bootstrap/FeatureContext.php` |
| Transports (occ, WebDAV, the Grafana API) | `tests/integration/bootstrap/Support/` |
| What CI actually runs | `tests/integration/behat.dist.yml` |

CI runs `--strict`, so an **undefined step in an untagged scenario fails the
build** — a scenario with no status tag is claiming to be live, and CI enforces
the claim. A new `*Steps.php` that nobody `use`d in `FeatureContext` is silently
dead; check for it.

The behat filter excludes **all four** status tags
(`~@todo&&~@unbuilt&&~@blocked&&~@decision`). A PR that adds a fifth status tag
without adding it to that filter has just made those scenarios fail the build.

## The four status tags

| Tag | Means | Who picks it up |
|---|---|---|
| *(none)* | Runs in CI. | — |
| `@todo` | **The code exists; only the test is missing.** | Someone writing a test |
| `@unbuilt` | A spec awaiting code. | Someone building the feature |
| `@blocked` | Real behaviour the harness cannot reach. | Someone extending the harness |
| `@decision` | A deliberate absence. No operation, ever. | Nobody |

**`behat --tags @todo` is the work queue.** Flag anything that pollutes it:

- A scenario tagged `@todo` whose feature has no code → it is `@unbuilt`.
- A `@blocked` that does not **name the missing capability** → it is a `@todo`
  nobody checked. The four that exist here: no browser, no way to make Grafana
  unreachable mid-request, no app remove/reinstall in CI, no proven DAV REPORT
  search over `nc:metadata-*`.
- **A `@blocked` whose stated reason is no longer true.** When a tag cites a
  reason, check the reason still holds.
- A `@decision` on something with a real gesture. `@decision` is for the
  *permanent absence of a capability* (*"there is no scheduled Nextcloud→Grafana
  sweep"*), **not** for *"nothing happened"* (*"Grafana is never contacted"*) —
  that second kind is ordinary behaviour, testable by absence, and several run in
  CI today. Read the `Then`, not the `When`.

A `@todo` that fails because of a **defect** is legitimate — but it must say so in
a comment, or it is indistinguishable from an unwritten one.

### The trap this repo actually fell into: the file-level `@todo`

Eleven of seventeen files carried a status tag above `Feature:`, which Gherkin
applies to **every scenario inside**. The result was 54 `@todo`s that could not
distinguish "the delete engine is built and live-verified, only the WebDAV trash
steps are missing" from "nobody has written this yet" — and it made
`--tags @todo` useless as a work queue, which is the one thing it is for.

**Flag any new status tag above `Feature:`.** It is right only when every
scenario in the file genuinely shares one fate, and it needs a comment saying so.

## Tags are an index — treat them as data, not decoration

Tags go on **one line, directly above `Scenario:`** — axis tags first, status last:
`@user @in-nextcloud @gesture @unbuilt`. A tag separated from its scenario by a
comment binds to the *next* one.

The payoff is that `behat --tags` becomes a query: *"everything a user can do from the
Files app"*, *"everything that starts in Grafana"*. That only holds if the tags are
true, so review them as claims.

| Axis | Tags | Rule |
|---|---|---|
| **Actor** | `@user` · `@admin` · `@grafana` · `@time` | **Exactly one.** |
| **Origin** | `@in-nextcloud` · `@in-grafana` | **Exactly one, or neither. Never both.** |
| **Channel** | `@ui` · `@gesture` · `@occ` · `@admin` · `@scheduled` | Several is normal and expected. |
| **Subject** | `@recycle-bin` | Where the bin setting changes the outcome. |
| **Status** | `@todo` · `@unbuilt` · `@blocked` · `@decision` | At most one. |

`@ui` and `@occ` deliberately overlap — most of this app is reachable both ways, and
the edges are what you want to query: `@occ&&~@ui` is CLI-only (scriptable but
undiscoverable), `@ui&&~@occ` cannot be automated. **Channel describes the FEATURE's
surfaces, not how the harness drove the scenario** — a test that runs `occ` is still
`@ui` if the admin panel has a button for the same thing. Flag a channel tag that
records the test rather than the behaviour.

### Actor — exactly one, in the UML sense

Every scenario is a use case, so it has a **primary actor**: `@user`, `@admin`,
`@grafana`, or `@time`. Exactly one. Flag a scenario with two — it means the actor
was read off the whole scenario instead of off whoever *initiates* it.

| Tag | Starts the behaviour by |
|---|---|
| `@user` | working in the Files app |
| `@admin` | the settings panel or an admin-only `occ` command |
| `@grafana` | changing a dashboard in Grafana, mirrored by a reconcile |
| `@time` | the scheduled job firing, no human present |

The admin verb list is longer than it looks: *configures, maps, unmaps, sets,
enables, disables, pulls, pushes, reconciles, **purges**, removes the app*. A
scenario whose gesture is any of these is `@admin`, not `@user`, even when the
outcome lands in a user's files.

`@time` is currently unused, and that is a **gap in the specs**, not a tagging miss —
the scheduled job's own behaviour (self-gating on `schedule_enabled`, re-reading its
interval) is never exercised by the manual `occ` reconcile that stands in for it.

### Origin is decided by the WHEN, and it is exclusive

**A behaviour happens from one side or the other — never both.** A `Given` that
mentions Grafana is *arranging state*; it does not make the scenario Grafana-origin.
Read the `When`: whoever performed the action under test owns the scenario.

The giveaway is the title. *"A tag added **in Nextcloud** since the last sync is added
in Grafana"* is `@in-nextcloud` however much Grafana appears in its steps — the user
acted in Nextcloud and the payoff is what reached Grafana.

Flag any scenario carrying both. It is not "thorough", it means the origin was
inferred from the whole scenario rather than from its action.

### `@in-grafana` means the RECONCILE mirrors it

Use it only where the change happened in Grafana **and the payoff is what the
reconcile brings into Nextcloud**. A pull or a sync run is implied and usually
explicit.

That is narrower than "the scenario mentions Grafana". Nextcloud drives; Grafana does
not drive back except through a reconcile — so if no sync run is what makes the
outcome observable, the scenario is not `@in-grafana`.

**Neither tag** is a real answer, and a common one: configuration, a refusal, or a
local-only surface (the mimetype, the opener menu, a DAV property) never crosses the
boundary. Do not invent an origin to fill the column.

### `@recycle-bin` — the Grafana-specific axis

Grafana has **no undo**: a `DELETE` is permanent, proven against a live instance.
Nextcloud's trash is therefore the only reversibility this app has, and the
`bin_enabled` / `bin_folder` settings switch between two whole delete models that
disagree about what happens to the dashboard, what happens to the uid, and which
step is irreversible.

Tag any scenario whose outcome would differ under the other setting. Review
checklist for this axis:

- **A delete scenario that does not state its model is ambiguous.** Flag any
  `delete.feature` / `move.feature` / `remove-mapping.feature` scenario that does
  not arrange the bin as on or off.
- **`bin on` and `bin off` are not `Examples` rows.** They are different rules
  sharing a shape. Flag an Outline that tries.
- **The bin folder is not a mapping.** It holds dashboards Nextcloud does not
  manage. Flag any scenario or step implying a wholesale bin clear.

### `sync` vs `link` is not an axis

`@in-grafana` scenarios are mode-agnostic — a change in Grafana reaches Nextcloud the
same way either way, and a `link` simply has no bytes to update. Only
`@in-nextcloud` scenarios branch, because a link is a read-only projection. Flag a
scenario written twice per mode when the outcome does not differ.

## Community standards this project follows

From [Cucumber's own guidance](https://cucumber.io/docs/bdd/better-gherkin/):

- **Describe behaviour, not implementation.** The test: *will this wording need to
  change if the implementation does?* If yes, rewrite it.
- **`Given` is a precondition, `When` is the action, `Then` is an observable
  outcome.** Avoid user interaction in a `Given`.
- **Assert on something observable**, not on internal state. Cucumber says "resist
  the temptation to look in the database"; here that means **assert through
  Grafana's own API or over DAV, never only through this app**. A scenario that
  asks the app whether the app did something proves only that the app agrees with
  itself.
- **Keywords are ignored when matching step definitions.** Two steps with the same
  text are the same function whatever their keyword — which is why one function
  may carry several phrasings, and why near-duplicate wordings are a defect rather
  than a style choice.

### Where this project deliberately differs — do not "correct" these

- **Comments are long and carry the reasoning.** Ours record *why* a rule exists,
  what was tried, and the saga section that settled it. The prose is the point: it
  is the only place a decision's cost is written down. Never suggest trimming a
  comment block for brevity.
- **Backgrounds run past the recommended four lines.** Every line is real
  configuration against a live Nextcloud and a live Grafana; there is no `Given I
  am logged in` shortcut to hide them behind.
- **`Rule:` is not used, and this is verified, not preference.** Behat's parser
  rejects the keyword and there is no newer Behat to upgrade to. Business rules are
  comment banners (`# ── RULE: … ──`). **Never suggest converting them.**

## Layout — one behaviour, one file

Feature files are organised by **behaviour**, never by the kind of thing acted on.
The owners are listed in `features/README.md`. **Flag a scenario that describes a
behaviour another file owns**, even when it passes — the failure mode is silent
drift, and nobody reads two files to answer one question.

The pair most likely to drift here is `delete.feature` and `move.feature`: moving a
file out of every mapping and trashing it are the *same* Grafana operation, chosen
by the same setting. They stay in separate files because the gestures differ, so
check that their rules still agree whenever either changes.

## The traps this repo has actually fallen into

Each of these cost something real. Worth checking on every PR that touches a
feature file.

**A file-level status tag.** See above — the single biggest source of noise in
this repo's specs, and the reason the work queue was unusable.

**A comment that diagnoses a bug and then leaves it tagged.** *A comment that says
"likely cause" is an open investigation, not a status.* Flag one that has stopped
moving.

**A tag floating above a comment block.** Gherkin binds a tag to the next
*scenario*, across any number of comment lines. **A status tag must be directly
adjacent to its `Scenario:` line.**

**A Background whose steps do not exist.** Harmless while every scenario in the
file is tagged, and an instant `--strict` failure the moment one goes live. **If a
PR promotes a scenario, verify the Background is real.**

**A scenario borrowing another file's setup habits.** Files differ in what their
Background maps. Copying a scenario between them silently breaks it — check the
file's own Background before assuming a path resolves.

**An absence assertion that passes for the wrong reason.** A command that exits
non-zero for *both* "no such thing" and "could not connect" makes the test go green
precisely when its fixture breaks. **Absence assertions must match the specific
failure.** In this app the sharpest case is *"the dashboard still exists in
Grafana"* — assert the uid resolves, not merely that no error was raised.

**The same sentence under `@Given` and `@Then`.** Keywords are ignored in matching, so
one phrase registered twice is a DUPLICATE DEFINITION, not two steps — Behat refuses the
second and **every scenario in the suite fails**, including ones that never mention it.
The failure reads as "the app is broken", not "your step is wrong", which is why it costs
a whole cycle to place. An arrange and an assertion need different sentences: *"the tag
state **starts as** …"* vs *"the tag state **is** …"*.

**A `Then` that only asks this app.** See the observable-outcome rule above.

## Scenario Outline: an input, or a different rule?

`Examples` is right when the rows are **one rule over different inputs** and the
outcome is identical for every row. It is wrong when the rows are **different rules
sharing a shape** — that hides asymmetries, and asymmetries are where the bugs are.

The test: can you write the rows as a list of *values*, or only as a list of
*sentences*? Values → `Examples`. Sentences → separate scenarios.

## Wording is an API

Every step line is a function signature, so the vocabulary is small and
parameterised. **Flag a new step phrasing that duplicates an existing one** — two
wordings for one idea are two functions to maintain and two ways for the same
assertion to drift. Read `tests/integration/bootstrap/Steps/` before accepting one.

**Setup says what IS TRUE, not who did what to make it true.** `Given the admin
runs a pull` reads as though an admin were permanently on call before every gesture
a user makes. That is not the system being described.
