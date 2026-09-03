<!--
SPDX-FileCopyrightText: 2026 kubed-io
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Chapter 3 — The Menu and the Deep Clean

> **Prerequisite:** Chapter 2 (*Service for a King*) closed **complete**, and it
> closed on two sentences that are this chapter's whole brief: *nobody has walked
> the tree asking what is duplicated and what is dead*, and *nobody outside this
> kitchen could find the place*.
>
> **The food is good.** 129 scenarios, 110 running against a real Nextcloud and a
> real Grafana on every pull request, 542 unit tests behind them. Every dashboard
> verb, every folder verb, both directions of sync, both storage kinds, both
> modes. The Grafana recycle bin — a trash for a service that has none — is a dish
> the master never had on his menu.
>
> **And the room has been cooked in for nine rounds.** Eighty-nine classes in
> `lib/`, several grown a listener at a time under service pressure, which is
> exactly how a kitchen ends up with three whisks in three drawers and a stockpot
> nobody has opened since Round 4. Nothing in Chapter 2 was wrong to do. It is
> simply that *finishing a dish* and *putting the kitchen back* are different jobs,
> and only one of them has been done.
>
> **There is also a menu, and nobody has printed it.** The README opened with a
> status blockquote and 383 lines of design rationale. Three of its claims were
> false — it called tags *planned* while `tags.feature` ran green in CI. The store
> listing had no `<documentation>` block and no screenshots. And 170 files carried
> a real person's name in their copyright header, in an org whose sibling app
> locked `kubed-io` as the only name that appears anywhere.
>
> Chapter 1 was mise en place. Chapter 2 was service. **Chapter 3 is the deep
> clean and the printed menu** — the two jobs you do between the last cover and
> opening the doors to the street.

---

## Status: **CLOSING** — 2026-09-03

Round 1 is landed, the shopfront is built, and **the certificate request is
filed** ([PR #1220](https://github.com/nextcloud/app-certificate-requests/pull/1220)).
Both gates this chapter said it could not close alone are closed. What is left is
a countersign nobody here controls, and then two commands. See *§Reaching the
store* below.

---

## The doctrine — a clean kitchen and a legible menu are the same discipline

Chapter 2's standard was *parity with the master*. This chapter's is its sequel,
and it applies in two directions at once:

**Nobody sees the kitchen, and everybody sees the menu — so both have to be true.**

The two halves look unrelated and are the same defect wearing two coats:

- The **code** accreted under service pressure, so it says the same thing in
  several places and still contains things it no longer says at all.
- The **documentation** accreted the same way, so it says things the code stopped
  doing and describes a build rather than a product.

In both cases the fault is not carelessness. It is that **an artefact drifts toward
its author**, and the author of every one of these was someone who already knew the
answer.

### The rule that comes out of it

> **A second copy of a fact is a second place for it to rot.** That is true of a
> helper duplicated across two services and it is true of a sentence duplicated
> across the README and the store listing. The fix is the same both times: one
> home, and a pointer from everywhere else.

`nextcloud-penpot` reached this a week earlier and wrote it down as its
[Chapter 4 §D4.2, the documentation cascade](https://github.com/kubed-io/nextcloud-penpot/blob/main/saga/Chapter_4_Open_For_Business.md).
This chapter adopts that decision wholesale rather than re-deriving it, and extends
it to the code — which is the part penpot's chapter did not have to do.

---

## The decisions

Numbered `§D3.n`. Chapter 1 used `§n`, Chapter 2 used rounds and courses.

### §D3.1 — Decision (locked): the README is an advertisement

**The call:** `README.md` sells the app. It says what someone can do, brags a
little about the most interesting behaviour, and stops. It carries no status, no
roadmap, no rationale, and no argument with a previous version of itself.

Adopted from penpot §D4.1 unchanged. `kubed-io/nextcloud-n8n`'s README is the
reference implementation and the bar — and the bar is a floor, not a ceiling.

**What this ruled out**, because every one of these was in the file:

| Removed | Why |
|---|---|
| A six-line `Status: active development` blockquote, first thing after the badges | A reader deciding whether to install does not want the build's self-assessment first |
| `## How it works (the design)` and its three subsections | Design deliberation, not a feature |
| `### Tags — one searchable set *(planned)*` | **It was not planned. It was built and green in CI.** |
| `(planned) bidirectional sync back to Grafana` in the opening sentence | Same defect, in the first line a visitor reads |
| Sibling-app comparisons | The reader has not read the siblings |
| `## Development` | That is `CONTRIBUTING.md`'s job |

**The test:** if a section's subject never appears in the sibling's README, it is
probably the author talking to themselves.

**What replaced it:** the sibling shape, section for section — *the whole idea in
one breath*, the CRUD table, the smug section, move/copy, delete/restore/purge,
tags, the file type, the openers, the modes, setup in three moves, the `occ`
block, the specs, the licence. 383 lines → 230.

### §D3.2 — Decision (locked): the two brags are folders and the bin

**The call:** every sibling README has one section it is *smug* about. n8n's is
tags; penpot's is *the folder is the project*. This app has two, and both are
things the siblings cannot say:

1. **Grafana has real, nested folders**, so the mirror is the whole tree in both
   directions and there is no tagging scheme to fake. n8n had to invent one;
   penpot had to teach Penpot a hierarchy it does not have.
2. **Grafana has no trash, so this app built one.** A folder delete there cascades
   through an arbitrarily deep subtree in a single request with nothing to undo it.
   The recycle-bin folder converts that into a move.

The second is the more interesting and had **no section at all** in the old README
— it was three lines inside a `### Deleting` subsection. It is now the longest
section in the file.

### §D3.3 — Decision (locked): admin copy describes the field, not the code

**The call:** one sentence per field, matching the sibling's density. A tooltip
says what the field is and what it costs to change.

Adopted from penpot §D4.3. What it cost here:

| Field | Was | Now |
|---|---|---|
| Recycle bin, the checkbox | 340 characters, one paragraph, both modes' full mechanics | Two clauses, one per mode |
| Recycle bin, the folder name | Three sentences | One |
| Instance base URL | Included an in-cluster Kubernetes service URL | Just the example — see §D3.4 |
| Scheduled pull | Named *"the manual Sync from Grafana button"* | Names **Sync Actions**, the panel it is actually in |

The last one is worth its own line: the old copy pointed an admin at a button by
description rather than by the name printed on the panel below it.

### §D3.4 — Decision (locked): user-facing docs make no infrastructure assumptions

Adopted from penpot §D4.4. The base-URL help offered
`http://grafana-service.observe.svc:3000` as an example. That is this homelab's
Kubernetes service address. It is a correct URL and a wrong example: it tells a
reader running Grafana on a VM that they are holding the tool wrong.

### §D3.5 — Decision (locked): the maintainer is **kubed-io**, and no real name appears anywhere

**The call:** every `SPDX-FileCopyrightText` header, the `info.xml` `<author>`,
and every other attribution reads `kubed-io`.

Adopted from penpot §D4.12, where it was already locked and already applied — 42
of penpot's service classes say `kubed-io`, and **170 of this app's files said a
real person's name.** The sibling had solved this and the fix had never crossed
over.

**Why it is a decision and not a find-and-replace.** A copyright header is
outward-facing: it ships in the app store tarball, it is in every file a
contributor opens, and it is the one piece of prose nobody re-reads. That makes it
the ideal carrier for exactly this leak — it rides in on a template, it is correct
the day it is written, and it is invisible from then on.

### §D3.6 — Decision (locked): the deep clean is a chapter goal, not a background intention

**The call:** a **thorough refactor pass over `lib/`** is a named deliverable of
this chapter, with its own rounds, and it is not allowed to be the thing that
happens if there is time left over.

The mandate, in the order the passes run:

1. **Duplication.** Two services computing the same thing, two listeners with the
   same walk, the same guard written twice. The house rule is the doctrine above:
   one home, a pointer from everywhere else.
2. **Dead code.** Methods nothing calls, branches nothing reaches, config nothing
   reads, and the classes left standing after a decision was reversed. Chapter 2
   reversed several — the whole-entry purge model, the retired uid scenarios, the
   second file extension — and a reversal that leaves its code behind is a trap for
   whoever proposes it next.
3. **DRY, honestly applied.** Not extracting every three-line repetition. Merging
   the ones where the *rule* is shared, so a change to the rule cannot be made in
   one place and missed in the other.
4. **Altitude.** Several classes grew a method at a time and now answer questions
   at three different levels. `TrashRestoreHook` is the worked example: it called
   `restoreOne()` when the branch it needed lived one level up in `restoreTree()`,
   and that single altitude error was the entire *Restore a folder in a Team
   Folder* defect — carried for two rounds behind a theory about groupfolder trash
   internals that turned out to be fiction.

**The guard rail, and it is not negotiable:** the specs do not move. 110 running
scenarios are the definition of *unchanged behaviour*, and a refactor round that
needs a `.feature` edited is not a refactor — it is a behaviour change wearing a
refactor's clothes, and it goes back to the Gherkin-first process.

### §D3.7 — Decision (locked): `info.xml` is a store listing

Adopted from penpot §D4.6. The description is the shopfront and had been written
as an essay with two paragraphs saying the same thing. It now carries a
`## ✨ What you can do` list, the two brags from §D3.2, the mode table and the
honest-dates close — and the `<documentation>` block it had been missing entirely.

**Screenshots are the one thing this chapter cannot finish alone.** penpot §D4.5
locked them as a shared convention: five images in `screenshots/`, thumbnails
beside them, listed in `<screenshot>` in carousel order. This app has none, and no
agent can take them. It is a gate below, not a task.

---

## The plan — the rounds

### Round 1 — the menu is printed *(landed)*

README rewritten to the sibling shape. Admin copy trimmed to the sibling density.
`info.xml` description and `<documentation>` block. The name sweep. One stale
claim corrected in the root `AGENTS.md` — it said *"no tag scheme"*, which was
true about mappings and read as false about tags.

### Round 2 — the cascade

Adopt penpot §D4.2 properly: `saga:` pointers at the top of each `features/AGENTS.md`
section, and every note that opens *"this used to…"* moved down a level. This
file's own §D3.6 list is the inventory of what has already been reversed and may
still have prose describing it as live.

### Round 3 — the dead-code sweep

The first half of §D3.6, and deliberately first because it is the cheapest and it
shrinks what rounds 4–5 have to read. Unreferenced methods, unreachable branches,
config keys nothing reads, and the residue of Chapter 2's reversals.

### Round 4 — the duplication pass

The second half. `lib/` walked service by service against the question *does this
fact have two homes?* Expected candidates, named now so the round is not a
fishing trip: the trash walks (`reap` / `restore` / the two hooks), the mapping
resolution paths, and the metadata read-modify-write shape.

### Round 5 — the two missing harness tools

Not documentation and not refactor, but the honest reading of Chapter 2's queue:
**7 of the 12 `@blocked` scenarios are the same sentence** — *"while Grafana is
unreachable"* — and 3 more are `open-with`. Twelve blocked scenarios are two
missing tools. Building them turns most of the queue green without writing a line
of `lib/`.

### Round 6 — the shopfront *(landed)*

`screenshots/`, the `<screenshot>` carousel, and the store submission path.

**Eight screenshots, where penpot §D4.5 asked for five** — the two folder views
side by side, the JSON editor, the context menu, the connection card, the
mappings panel, the recycle-bin setting and the Sync Actions panel. Each is
committed at full size with a 600px-wide thumbnail beside it, and every
`<screenshot>` element pairs a full image with the thumbnail of the *same* name.
All sixteen URLs are live on `raw.githubusercontent.com`, which is what the store
actually fetches — a listing whose images 404 is a listing nobody scrolls.

The two extra over penpot's five are the ones this app has and penpot cannot: the
JSON editor (§D3.2's first brag — a dashboard here is editable text, where a
`.penpot` is an opaque archive) and the recycle-bin setting (the second).

---

## The gates — what this chapter cannot do alone

| Gate | Why it needs Dr K | State |
|---|---|---|
| **Screenshots** | Nobody can take them but a person at a browser | ✅ **closed** — eight, not the five §D4.5 asked for |
| **The store listing** | An app-id registration and a signing identity | ✅ **closed on our side** — CSR filed, everything else waits on the countersign |
| **Any behaviour change the deep clean uncovers** | §D3.6's guard rail: if a refactor wants a `.feature` edited, it stops and asks | open, and stays open — it is a standing rule, not a task |

---

## Reaching the store

The store's one hard requirement is that **every release is signed by a
certificate Nextcloud themselves countersigned**. That countersign is the only
step here nobody controls, so it is filed first and everything else overlaps the
wait.

| # | Gate | Owner | State |
|---|------|-------|-------|
| 1 | App id `grafana_sync` consistent across `info.xml`, `package.json`, namespace | — | ✅ |
| 2 | Signing keypair + CSR minted, gitignored, verified | agent | ✅ RSA 4096, `CN=grafana_sync`, self-signature verifies |
| 3 | Release pipeline carries sign + upload | agent | ✅ `publish.yml`, same shape as the siblings |
| 4 | **CSR filed** with `nextcloud/app-certificate-requests` | Dr K | ✅ [PR #1220](https://github.com/nextcloud/app-certificate-requests/pull/1220), from `kubed-io` |
| 5 | **Countersigned `.crt` committed back** | Nextcloud | ⬜ **the wait** — n8n took ~2 days, penpot 4 |
| 6 | Private key in `NEXTCLOUD_STORE_KEY` | Dr K | ✅ |
| 7 | Durable backup of the private key | Dr K | ✅ GCP `nextcloud-grafana`, round-tripped by modulus |
| 8 | `NEXTCLOUD_STORE_TOKEN` set | Dr K | ✅ |
| 9 | Register the app id | either | ⬜ needs gate 5 |
| 10 | Cut the release | either | ⬜ needs 9 |

### The three things the CSR PR needed, and this one got right first try

Both siblings tripped here, in different places, and #1220 is the first of the
three to pass on the first push. Recorded as a checklist because the failures are
not guessable:

1. **Signed.** n8n's landed unsigned and needed an amend and a force-push.
   Verified by reading the raw object for its `gpgsig` header — `git log
   --show-signature` reports *"No signature"* on a perfectly signed commit
   whenever `gpg.ssh.allowedSignersFile` is unset, so **the obvious check looks
   exactly like the failure**.
2. **`Signed-off-by:`, matching the author byte for byte.** Penpot's first push
   was rejected by the DCO bot for missing it, and the guidance describing that
   gotcha did not mention it. The trailer must carry the *commit author's* email
   — `4399427+kferrone@users.noreply.github.com`, not the `kellyferrone@gmail.com`
   this repo's `user.email` is normally set to.
3. **From `kubed-io`, on a branch off `upstream/master`.** n8n's went from a
   personal account and could not be corrected afterwards. The kubed-io fork was
   24 commits behind, so branching off the fork's own tip would have dragged two
   dozen unrelated commits into the diff.

The general lesson is penpot's and it holds: **read the artefact, not the
description of the artefact.** One `gh api …/pulls/1213/commits` call gave the
exact shape to copy, including the two things the prose had left out.

### One thing grafana already has that penpot did not

Penpot's first release attempt died with *"Changes must be made through a pull
request"*: `publish.yml` pushes the version bump straight to `main` as the
**`kubed-io` GitHub App**, and penpot's `default` ruleset had no bypass actor for
it. n8n had one from the start, so it was invisible — **workflow parity is not
release parity, and the gap was in repo settings, which no diff of the tree can
see.**

This repository's `default` ruleset already carries the `Integration` bypass, so
gate 10 will not hit that wall. Checked rather than assumed.

### What is deliberately still not done

**`appinfo/signature.json`** — Nextcloud's *in-tarball* integrity manifest, a
different thing from the tarball signature the store checks. Optional for
acceptance, and generating it faithfully needs `occ integrity:sign-app` against a
real Nextcloud. Deferred exactly as it is in both siblings.

---

## Carried — findings recorded rather than acted on

### A mapped folder's name is ambiguous when two Grafana folders share a title

Raised by Copilot on #77 against `sealCard()`, and declined **there** for a
specific reason: the card's `data-grafana-folder` holds the title alone while the
visible field reads `title (uid)`, and that asymmetry is the SERVER's convention
(`templates/mapping_settings.php` lines 86 and 95). `sealCard()` copies it
deliberately, because a card the JS builds has to be the one the template would
have built — changing only the JS would reintroduce the drift the function exists
to close.

**The underlying point survives the decline.** The delete confirmation reads
*"Remove the mapping from observe to Observe?"*, and with two Grafana folders
sharing a title that names neither of them. It is equally ambiguous on a
server-rendered card, so a fix belongs on the template and the JS together.

Not done, because it is a trade rather than a win: `observe (af397c9y8enswf)`
buys clarity in a rare case by making every common case uglier. It wants a
decision, not a patch — which is why it is written here instead of left in a
review thread nobody will read again.

### An arrange that resolved the wrong file, carried back from n8n

The `link` arrange in `LifecycleSteps::aManagedDashboardFile()` seeded a dashboard
through Grafana, pulled, and then took **`$files[0]`** from the folder listing.

`nextcloud-n8n` ported that arrange, Copilot caught it there on #88, and the
finding comes home: one pull can bring down more than one file — a folder that
already held dashboards, or a mirror the previous pull never finished — and the
listing has no meaningful order. So `currentFilePath` and `lastUid` could point at
**different files**: green, and testing two different things, which is the worst
outcome available to a harness.

`seedGrafanaDashboard()` already returned the uid it minted; the arrange threw it
away. Resolving by that uid also asserts the thing worth asserting — that the pull
stamped the mirror at all — where `assertNotNull($uid)` only proved SOME file in
the folder carried SOME uid.

**The doctrine in both directions.** Chapter 6 of the n8n saga opens on *a fix that
stays in the app that found it is half a fix*; this is the same sentence with the
apps swapped. The defect travelled to n8n with the port, was found there, and came
back.

**The other `$files[0]` in this suite is fine** and was checked rather than
assumed: `the dashboard inside "…" holds:` asserts `count($files) === 1` first, so
the index is unambiguous by construction.

## What this chapter is not

It is not a rewrite. Chapter 2's code works, is covered, and is in daily use; the
deep clean is a tidy of a working kitchen, not a new one.

It is not a feature chapter. Nothing here adds a verb. The one thing that looks
like a feature — Round 5's harness tools — adds no behaviour at all; it lets
already-written specs finally run.

~~And it is not the store.~~ **It became the store.** This bullet said reaching it
was Round 6's problem and gated on a human, and both halves stopped being true on
2026-09-03: the shopfront is built and the request is filed. The bullet is struck
rather than deleted, because a chapter that outgrew its own scope is more useful
on the page than off it. The job it named — making the app *worth arriving at* —
is the part that got done first, and that ordering was right.

---

Sources / cross-links:
- [Chapter 2 — Service for a King](Chapter_2_Service_for_a_King.md) — the service this clean follows.
- [`nextcloud-penpot` saga, Chapter 4 — Open for Business](https://github.com/kubed-io/nextcloud-penpot/blob/main/saga/Chapter_4_Open_For_Business.md) — the tandem chapter; §D4.1–§D4.14 are adopted here rather than re-derived.
- [`nextcloud-n8n` README](https://github.com/kubed-io/nextcloud-n8n) — the reference implementation for §D3.1.
- The `features/` Gherkin specs — the guard rail the deep clean is not allowed to move.
