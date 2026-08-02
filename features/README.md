<!--
  SPDX-FileCopyrightText: 2026 Kelly Ferrone
  SPDX-License-Identifier: AGPL-3.0-or-later
-->
# How the feature files are organised

`features/*.feature` is this app's **specification**. It is written before the code
and kept true after it — documentation that happens to execute, not a test-naming
convention.

This file is the authority on layout and tags. The review checklist that follows
from it lives in [`.github/instructions/gherkin.instructions.md`](../.github/instructions/gherkin.instructions.md);
where the two disagree, this file wins.

## The organising rule: a feature is a BEHAVIOUR, not a mechanism

Files are named for **what a person did**, never for the kind of thing they did it
to. Renaming a dashboard file and renaming a mapped folder are both *renames* and
belong in one file, so a reader comparing them sees one table instead of hunting
two.

The failure this prevents is silent: two files describing one behaviour drift
apart, and nobody reads two files to answer one question.

| File | Owns |
|---|---|
| `create-dashboard.feature` | A dashboard coming into existence, on either side |
| `copy.feature` | Duplicating a dashboard file, and what the copy is *not* |
| `move.feature` | A file changing folder — into a mapping, out of one, between two |
| `rename.feature` | A file or dashboard changing name, and the filename↔`title` reconcile |
| `delete.feature` | Everything that removes a dashboard: both trash steps, the recycle-bin folder, restore |
| `purge.feature` | The admin's deliberate wipe of the Nextcloud side |
| `tag-sync.feature` | A dashboard's tags, across all three surfaces |
| `reserved-tags.feature` | The `nextcloud:*` / `grafana:*` control plane — `ignore` and the mode pills |
| `mapping-membership.feature` | Which files a mapping owns, and what "unmapped" means |
| `file-type.feature` | A mirror as a first-class file type: mimetype, icon, DAV props |
| `open-with.feature` | What clicking a mirror does |
| `admin-connection.feature` | Reaching Grafana at all: URL, token, and how failure reads |
| `admin-mapping.feature` | Creating and configuring a mapping |
| `remove-mapping.feature` | Tearing a mapping down, and what happens to what it owned |
| `reconcile.feature` | What a sync run does *as a run*: completeness, idempotency, what it reports |
| `lifecycle.feature` | Install and enable |
| `uninstall.feature` | Removal, and what survives it |

**A scenario describing a behaviour another file owns is a defect**, even when it
passes. Move it.

## What makes THIS app different from its siblings

The n8n and Penpot integrations are the same shape, and three Grafana facts bend
the specs away from theirs. Every one of them shows up as scenarios that have no
counterpart in the other two repos.

### 1. Grafana organises by FOLDER, not by tag

n8n maps a *tag* to a Nextcloud folder. Grafana has real folders, so a mapping is a
plain folder-to-folder mirror. There is no tagging scheme to maintain for
*placement* — which is why `tag-sync.feature` here is purely about labels, and why
"which mapping owns this file" is answered by the folder tree alone
(`mapping-membership.feature`).

### 2. Grafana has NO undo, and that is proven, not assumed

A service account cannot reach any soft-delete or trash: `DELETE
/api/dashboards/uid/{uid}` is **permanent**. The master recipe — trash = archive,
purge = delete, restore = unarchive — does not translate, because there is no
archive to fall back to.

So Nextcloud's recycle bin *is* the feature this app adds to Grafana, and the
`bin_enabled` / `bin_folder` settings select between two whole delete models:

| | trashing a sync file | restore | emptying the NC trash |
|---|---|---|---|
| **bin OFF** (default) | true Grafana delete, **uid stripped** | create-on-land → **NEW uid** | Nextcloud-only; Grafana already empty |
| **bin ON** (opt-in) | dashboard **moved** to the bin folder, uid kept | moved back, **same uid** | the one irreversible step — permanent delete |

**Every delete scenario must say which model it is in.** A delete scenario that
does not is ambiguous by construction, because the two models disagree about
everything: what happens to the dashboard, what happens to the uid, and which step
is the point of no return. This is why `delete.feature` is banner-grouped by model
rather than by gesture.

The second-order rule: the bin folder **is not a mapping**. It holds dashboards
Nextcloud does not manage, so no operation may ever clear it wholesale — only the
specific items being purged.

### 3. `version` is volatile, and `uid` is the identity

Grafana bumps `dashboard.version` on **every** save, so the stored body strips it
(it lives in metadata instead) — otherwise a push→pull round-trip would see a
changed body and churn forever. `dashboard.id` is a per-instance numeric key and is
stripped for the same reason. The stable `uid` is the identity thread, and the
reason renaming, moving and restoring never break the link.

## Tags are an index, not decoration

A scenario carries tags on **one line, directly above `Scenario:`** — axis tags first,
status last: `@user @in-nextcloud @gesture @unbuilt`. A tag on its own line separated
by comments binds to the wrong scenario, so keep them together.

The point is that `behat --tags` becomes a query. *"Everything a user can do from the
Files app"*, *"everything that starts in Grafana"*, *"everything the recycle bin
changes"* — each is one filter rather than a grep and a guess.

### Actor — who initiates, in the UML sense

Every scenario is a use case, and a use case has a **primary actor**: the stick figure
who starts it. Exactly one per scenario.

| Tag | Actor | Starts the behaviour by |
|---|---|---|
| `@user` | An ordinary Nextcloud user | working in the Files app |
| `@admin` | An administrator | the settings panel or an admin-only `occ` command |
| `@grafana` | A person or client acting **in Grafana** | changing a dashboard, mirrored by a reconcile |
| `@time` | The clock | the scheduled job firing, with no human present |

`@user` and `@grafana` are strictly derivable from origin (`@in-nextcloud` minus
`@admin`, and `@in-grafana`), and they are tagged anyway — deliberately. *"Everything
an end user can do"* is a question worth one filter rather than a boolean expression,
and an actor is the first thing a reader of a use-case model looks for. Redundancy that
answers the primary question is not redundancy.

**`@time` is currently zero, and that is a real gap rather than a tagging oversight.**
The scheduled pull is the one actor with no scenario of its own: everything it does is
exercised through a manual `occ` reconcile, which is not the same thing — a job that
self-gates on `schedule_enabled` and re-reads its interval on every instantiation has
behaviour a manual invocation never reaches.

Where the actor genuinely *varies* across otherwise identical scenarios, prefer an
`Examples` column or a step parameter over writing the scenario twice.

### Origin — where the action happened

| Tag | Meaning |
|---|---|
| `@in-nextcloud` | Someone acted in Nextcloud. The payoff is what reached Grafana. |
| `@in-grafana` | The dashboard changed in Grafana (a human, a provisioner, an operator). The payoff is what reached Nextcloud, and a sync is implied. |

**Exactly one, or neither. Never both.** Origin is decided by the `When`, not by
whichever systems the scenario happens to mention. A `Given` that arranges a dashboard
in Grafana does not make the scenario `@in-grafana`; read who performed the action
under test.

The giveaway is the title. *"A tag added **in Nextcloud** since the last sync is added
in Grafana"* is `@in-nextcloud` however much Grafana appears in its steps.

`@in-grafana` additionally means **the reconcile mirrors it** — the change happened
there and the payoff is what the pull brings back. That is narrower than "the scenario
mentions Grafana". Nextcloud drives; Grafana does not drive back except through a
reconcile.

A scenario with **neither** never crosses the boundary: configuration, a refusal, or a
local-only surface like the mimetype or the opener menu. That absence is information —
do not invent an origin to fill the column.

### Channel — how it was triggered

| Tag | Meaning |
|---|---|
| `@ui` | The behaviour has a user-interface surface at all. |
| `@gesture` | Specifically a Files-app action: create, rename, move, copy, delete, restore, upload, toggling a pill. Driven over WebDAV, which is what a browser sends. Always also `@ui`. |
| `@occ` | Reachable from the CLI. |
| `@admin` | Needs the admin settings panel or an admin-only command. |
| `@scheduled` | The timed job, with no human present. |

**`@ui` and `@occ` are not exclusive, and the overlap is the point.** Most of this app
is reachable both ways, and the interesting queries are the edges:

```
--tags '@ui&&@occ'    both surfaces — changing one means changing the other
--tags '@occ&&~@ui'   CLI only      — no button exists; scriptable, undiscoverable
--tags '@ui&&~@occ'   UI only       — cannot be automated or done headlessly
```

These describe the FEATURE's surfaces, not how the harness drove it. A scenario the
test runs via `occ` is still `@ui` if the admin panel has a button for it — otherwise
the index answers "how do we test this", which nobody needs to ask.

### Subject — the Grafana-only axis

Because the two delete models disagree about everything, and because both are
configuration rather than gesture, they need a filter of their own.

| Tag | Meaning |
|---|---|
| `@recycle-bin` | The outcome depends on `bin_enabled` / `bin_folder`. Any scenario whose result would differ under the other setting. |

`--tags '@recycle-bin'` is the one query that answers *"everything the bin setting
changes"*, which is the question asked before touching `DeleteService`. It spans
`delete.feature`, `move.feature` and `remove-mapping.feature` — three files, one
setting, and that is precisely why grepping does not work.

### `sync` vs `link` is NOT an axis

The tempting move is to write every behaviour twice, once per mode. Don't — the modes
only diverge in one direction. An `@in-grafana` scenario is mode-agnostic: a dashboard
renamed or deleted in Grafana reaches Nextcloud the same way either way, and a `link`
simply has no bytes to update. Only `@in-nextcloud` scenarios branch, because a link is
a read-only projection.

The test: can you write the restriction as a sentence starting *"A link…"*? If yes it
is a rule and deserves its own scenario. If the mode makes no difference to the
outcome, leave it out.

## Status tags — four of them, and only one is a backlog

The most useful question you can ask a spec is **"what is built but untested?"**.
One tag cannot answer it, and for a long time this repo had one — `@todo`, applied
at the `Feature:` level, on eleven of seventeen files. That is not a status; it is
a shrug, and it made the question unanswerable.

| Tag | Means | What to do about it |
|---|---|---|
| *(none)* | Runs in CI. | Keep it green. |
| `@todo` | **The code exists; only the test is missing.** | Write the test. |
| `@unbuilt` | A spec awaiting code. | Build the feature. |
| `@blocked` | Real behaviour this harness cannot reach. | Extend the harness — or accept it. |
| `@decision` | Records a deliberate absence. There is no operation. | Nothing, ever. |

**`behat --tags @todo` is the work queue.** Anything else in that bucket is noise,
so the rules below are about keeping it honest.

### A `Feature:`-level status tag hides everything under it

Gherkin applies a tag above `Feature:` to every scenario in the file. That is
occasionally what you want (`lifecycle.feature` genuinely is all-or-nothing) and
usually a way to avoid deciding — it buries provable scenarios next to unbuilt
ones and reports both as the same kind of missing.

**Prefer per-scenario status tags.** A file-level tag needs a comment saying why
every scenario in the file shares one fate.

### `@blocked` must NAME the missing capability

A `@blocked` that does not say what is missing is a `@todo` nobody checked. The
ones that exist here are: **no browser** (the Files-app menu surface), **no way to
make Grafana unreachable mid-request**, **no app remove/reinstall in CI** (the
harness can only disable and enable), and **no proven DAV REPORT search over
`nc:metadata-*`**.

If the stated reason stops being true, the tag is stale and the scenario is
probably promotable.

### `@unbuilt` vs `@todo` is about the CODE, not the test

If `lib/` cannot do the thing, it is `@unbuilt` — no matter how well specified it
is. Marking unbuilt work `@todo` inflates the backlog with items no test could
ever pass, which is exactly what makes the queue worth ignoring.

### `@decision` is a permanent absence, not "nothing happened"

`@decision` records that a capability **does not and will not exist** ("there is no
scheduled Nextcloud→Grafana sweep"). It is *not* for an operation whose outcome is
that nothing was sent — "Grafana is not contacted" is ordinary behaviour, testable
by absence, and several such scenarios run in CI today.

Read the `Then`, not the `When`: the outcome of an operation, or the absence of the
operation itself?

### A `@todo` that FAILS is a finding, not a status

Legitimate — but it must say so in a comment, or it is indistinguishable from one
nobody has written yet.

## `Rule:` is NOT available — verified, not assumed

Behat's parser rejects the keyword (`Expected Step, but got text: "  Rule: …"`) and
there is no newer Behat to move to. Business rules are comment banners:

```gherkin
  # ── RULE: a link is never pushed ──────────────────────────────────────────
```

**Never suggest converting these to `Rule:` blocks.**

## Scenario Outline: an input, or a different rule?

`Examples` is right when the rows are **one rule over different inputs** and the
outcome is identical for every row. It is wrong when the rows are **different rules
sharing a shape** — that hides asymmetries, which is where bugs live.

The test: can you write the rows as a list of *values*, or only as a list of
*sentences*? Values → `Examples`. Sentences → separate scenarios.

The bin-on/bin-off pair is the standing example of the wrong use: the rows differ in
what happens to the dashboard, to the uid, and to which step is irreversible. Those
are sentences, so they are separate scenarios.

## Wording is an API

Every step line is a function signature, so the vocabulary is deliberately small
and parameterised. Read `tests/integration/bootstrap/Steps/` before inventing a
phrasing — **two wordings for one idea are two functions to maintain and two ways
for the same assertion to drift.**

The reverse is fine and intentional: one function may answer to several phrasings,
because Gherkin ignores the keyword when matching.

**That same rule is a trap.** Keywords being ignored means the *same sentence* under
`@Given` and under `@Then` is a DUPLICATE DEFINITION, not two steps — Behat refuses
the second and **every scenario in the suite fails**, including ones that never
mention it. The failure reads as "the app is broken", not "your step is wrong". An
arrange and an assertion need different sentences: *"the tag state **starts as** …"*
vs *"the tag state **is** …"*.

**Setup says what IS TRUE, not who did what to make it true.** `Given the admin
runs a pull` reads as though an admin were permanently on call before every gesture
a user makes. That is not the system being described.

## Where the binding lives

A scenario is only real if a step definition matches every one of its lines.

| What | Where |
|---|---|
| The scenarios | `features/*.feature` (repo root — they are docs) |
| The step definitions | `tests/integration/bootstrap/Steps/*.php` |
| The context that composes them | `tests/integration/bootstrap/FeatureContext.php` |
| Transports (occ, WebDAV, the Grafana API) | `tests/integration/bootstrap/Support/` |
| What CI actually runs | `tests/integration/behat.dist.yml` |

CI runs `--strict`, so **an undefined step in an untagged scenario fails the
build.** That is the safety net: a scenario with no status tag is claiming to be
live, and CI enforces the claim.

A new `*Steps.php` that nobody `use`d in `FeatureContext` is silently dead.
