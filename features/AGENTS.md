<!--
SPDX-FileCopyrightText: 2026 Kelly Ferrone
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Feature notes

The reasoning behind `features/*.feature` — why a scenario exists, what it
replaced, which decision it encodes, and what was deliberately left out.

It lives here rather than in the feature files because Gherkin is meant to be
read as specification: a scenario should be legible at a glance, and a comment
should add scope or a tidbit, not carry an essay. The essays are here, one
section per feature file, and a feature file links to its section on line 1.

For how the suite is organised — the one-verb-one-noun rule, tags, suites, and
which scenarios CI runs — see [README.md](README.md).

> Written for whoever picks this up next, human or agent. If you change a
> behaviour, change the note that explains it in the same commit; a note that
> describes the old behaviour is worse than no note.

## One section per feature file

Every `features/*.feature` links here from its first line, and every scenario
whose reasoning did not fit in a sentence carries a `# notes: AGENTS.md#anchor`
breadcrumb.

Short comments stay in the feature files on purpose. A remark that adds scope to
a step is meant to be read next to the step; only the essays are the problem,
because a scenario buried under twenty lines of history stops being legible as
specification.

**Keep them in step.** If you change a behaviour, change the note that explains
it in the same commit. A note describing the old behaviour is worse than no note,
because it will be believed.

Ported from `kubed-io/nextcloud-penpot`, where this layout was worked out.

## mapping/create

`features/mapping/create.feature`

"Admin makes a mapping" — the folder-mapping list in admin settings, driven over
the CLI (the same operations the Settings panel performs).

**THE KEY DIFFERENCE FROM THE n8n TEMPLATE: Grafana has real folders.** Where the
n8n app binds a *tag* to a Nextcloud folder because n8n has no folder concept at
all, a Grafana mapping binds a Grafana **folder** (by uid) to a Nextcloud folder —
a plain folder-to-folder mirror with no tagging scheme to maintain. The dashboards
inside that Grafana folder become the `.grafana` files in the Nextcloud
folder.

A mapping stores a folder **uid**, which in real Grafana is opaque. This feature
is config-only — a mapping is pure config until the sync chapter — so the steps
use the folder name as its own uid and title. The mapping CRUD and its validation
is what is under test.

### The preconditions

ONE SENTENCE PER FACT, AND A MAPPING IS ONE FACT.

    Given a mapping with the following values:
      | grafana folder | observe |
      | nc folder      | observe |

The table carries the full state of one mapping, and the fields are exactly the
ones the creation form takes. That matters more than it looks: the pre-state and
the action are then described in **one vocabulary**, so a scenario can put a
mapping in place and then perform the very action that would have created it,
with the difference visible in the table rather than hidden between two
differently-worded steps.

`the admin maps the Grafana folder "X" with:` is the same table as a `When`.

**A BLANK CELL MEANS "THE ADMIN LEFT IT ALONE", NOT "EMPTY".** Blank values are
dropped from the payload entirely, so the app applies its own default.

This replaced `When the admin adds these mappings:` taking a table of whole
mappings. That form could only pass or fail as a whole, naming none of its rows
as the thing that broke — and being a `When`, it could not state a mapping that
already existed, so the uniqueness scenarios had to add two mappings and assert
on the second.

### Creating a mapping saves the form

The mode × format matrix, one Examples row per combination.

**THE NEXTCLOUD FOLDER IS OPTIONAL, and that is this app's own rule.** Grafana has
real folders, so "same name on both sides" is the common case. Left blank it is
materialised from the Grafana folder's TITLE **at create and stored** — not
resolved lazily on every read — so the saved mapping and the admin list both show
two populated fields, and it is visible at a glance that they match because the
name was left blank. Mappings are immutable, so resolving once is enough.

**Mode defaults to `link`, and it did not used to** — omitting it refused the
whole mapping, so the shortest useful call (a Grafana folder and nothing else)
could not be written and every caller had to name a mode it had no opinion
about. `format`, two lines away in the same method, had always defaulted; mode
was simply the odd one out.

**Writing this table is what found it.** Declaring what every unset field becomes
forces a value for each, and there was none to put in the `mode` row. That is the
argument for the table, and it caught the identical gap in the n8n sibling.

`link` is the conservative choice: it downloads nothing and pushes nothing back,
so a mapping made without an opinion about mode cannot cost anything. An
*unknown* mode is still refused — saying nothing and saying nonsense are
different inputs and get different answers.

**`use_team_folder` defaults to false**, matching Penpot and n8n. A Team Folder
needs groupfolders, an OPTIONAL app absent from a stock Nextcloud, so defaulting
to it made the default mapping the one that could not be provisioned: an admin
who filled in the required fields and touched nothing else got a refusal. A
default must be the safe choice, not the preferred one. A Team Folder is opted
into, by naming `| storage | team folder |`.

**This note previously argued the opposite, and that is the lesson.** It recorded
the divergence from Penpot as "real rather than accidental", conceded in the same
breath that the sibling had changed it because such a default "cannot be
provisioned on a stock Nextcloud", and declined to follow. Writing the reason
down is not the same as having one — a documented defect reads as a decision, and
is much harder to see afterwards than an undocumented one. The inversion came
from the n8n master and was fixed in both apps in one pass.

`MappingTest::testStorageDefaultsToAdminOwned` pins it, and asserts the OMITTED
flag rather than an explicit `false` — the whole defect lived in what happens
when nobody says anything.

### A mapping the app cannot honour is refused, and says why

One scenario, not four, because the behaviour is identical every time: refused,
nothing stored, and the message names the field at fault. The rules are the
Examples.

**`nc_folder is required` has no row, and that is deliberate.** It is unreachable
from this form: a blank Nextcloud folder defaults from the Grafana folder's
title, and the step supplies a title whenever it supplies a uid. It is reachable
only by an API caller sending a uid with no title at all. **A refusal earns a row
only when someone can provoke it**; a validator no input can reach is not a
behaviour, and writing one up as if it were invents an actor to do it.

`the refusal explains "<fragment>"` matches a FRAGMENT. Pinning the exact
sentence would make every wording improvement a test failure.

### A Grafana folder may only be mapped once

A Grafana folder is what a mapping IS, so mapping it twice would make two
mappings mean the same thing and every dashboard inside it would belong to both.
Enforced by `MappingService::assertFolderUnique()` — which, despite the name,
checks the **Grafana** uid.

### Two mappings may not target the same Nextcloud folder

`@unbuilt`, **and the gap is real.** `assertFolderUnique()` checks the Grafana
uid and says nothing whatsoever about the Nextcloud folder, so today the second
mapping is accepted. Two Grafana folders mirroring into one Nextcloud folder
interleave their dashboard files, and each mapping-scoped sync prunes what the
other just wrote — the folder never settles.

The n8n sibling has the identical gap, written in the identical shape.

### The Grafana root can be mapped via the reserved "/" folder

The Grafana root ("General") holds dashboards that are in no folder. It has no
real uid, so the folder picker offers a reserved `/` entry for it. Mapping `/`
pulls the no-folder dashboards; `/` with subfolder sync on mirrors the whole
instance (see `sync-now.feature`).

### There is no way to change a mapping except its groups

`@decision`, not `@unbuilt`: **there is no operation to test, and that is the
design.**

Immutability is enforced by the API SHAPE rather than by guards.
`MappingService::updateGroups()` takes an id and groups; the PUT endpoint takes
`nc_groups` and nothing else; there is no update command. A caller cannot
*express* a change to the Grafana folder, the Nextcloud folder, the storage
backend, subfolder-sync, the mode or the format — so there is no rejection to
observe. Guarding is weaker than not offering, because a guard has to enumerate
what it protects and this one left `mode` and `format` out.

Each field is fixed for the reason it always was — every change would force a
live migration:

- the **Grafana folder** and the **Nextcloud folder** — re-pointing either
  renames or moves a whole tree of already-synced files and re-stamps their
  metadata (doubly fiddly when both change at once);
- the **Team Folder** flag — switching backend migrates the provisioned folder
  and all of its shares;
- **subfolder-sync** — flipping it restructures the far side (on→off flattens
  mirrored Grafana subfolders and re-parents their dashboards; off→on lazily
  grows them);
- **mode** and **format** — both decided how every existing file under the
  mapping was written, so changing one silently invalidates what is on disk.

This replaced a scenario that performed four `When`s in a row against a
full-mapping `update()`. It read as a script rather than as instances of one
rule, could only ever report the first failure, and described a guard list that
was incomplete.

### The groups a mapped folder is shared with can be changed

The one edit a mapping has, and the only one it should ever have.

**NARROWING AND CLEARING ARE THE POINT.** The old `syncGroupShares()` wrote the
listed groups and left the rest alone, so a group could be granted and never
revoked, and "set the groups to nothing" silently did nothing at all. It could
only prune safely once the sync stopped re-asserting a stored list — a sync that
pruned from a stored list would have been silently revoking access an admin
granted by hand.

The folder name differs per storage kind deliberately: removing a mapping deletes
nothing, so a folder outlives the mapping that made it, and a later Examples row
reusing the name would inherit a folder of the wrong kind.

### Groups are read from the folder, not from the mapping

**The scenario that explains why the whole change exists.** Three apps in this
family — this one, n8n and Penpot — can map to the same folder. While each stored
its own group list, every sync stamped that list over the others', so all three
fought for control of one folder forever and none of them was wrong. Reading the
groups off the folder makes the folder the single answer.

The share is made through **groupfolders' own `occ` command**, so it comes from
something that is not this app — otherwise the scenario would prove only that the
app agrees with itself.

It is written on a Team Folder for a checked reason: **core ships no `occ` command
that creates a plain group share.** Verified against a live Nextcloud rather than
assumed — core has `sharing:cleanup-remote-storages`, `delete-orphan-shares`,
`expiration-notification` and `fix-share-owners`, and nothing that shares. A first
draft called `occ sharing:share`, which does not exist.

### The recycle-bin folder

Off by default, in which case a move-out or a delete is a true Grafana delete —
and Grafana has no undo. Turned on, the admin names an existing Grafana folder to
act as the bin: a delete MOVES the dashboard there with its uid intact, so a
restore returns the same dashboard rather than a lookalike. It is Grafana's
answer to n8n's archive.

It is a setting of the APP, not a property of a mapping, which is why the bin
folder may not itself be mapped — it holds dashboards Nextcloud does not manage,
so no operation may ever clear it wholesale.

**IT IS TWO SETTINGS, AND THE SPEC SAYS THEM SEPARATELY.** `bin_folder` names the
folder; `bin_enabled` turns the feature on. They are independent in the panel — an
admin can type a folder name and leave the checkbox alone — and independent in the
code, which has an explicit error for the combination the old phrasing could not
express: *"enabled but no folder name is set"* ({@see RecycleBin}).

So the name lives in the **Background**, where configuration belongs, and each
scenario says only `the Grafana recycle bin is on` / `off`. Before this, every
scenario carried `on and set to "nextcloud-trash"` — one sentence asserting two
facts, which meant a scenario that only wanted to flip the switch had to restate
the configuration, and the bin modes could not be an `Examples` column without the
folder name riding along in every row.

**The setter and the assertion are worded differently on purpose.** Behat ignores
the keyword when matching, so `Given the recycle bin is on` and `Then the recycle
bin is on` would be ONE step registered twice — which Behat refuses, failing every
scenario in the suite. Worse, whichever registration won would SET the value while
a `Then` was asking it to be checked, so the assertion could never fail. The old
`Then the Grafana recycle-bin folder is off` in `mapping/create.feature` had exactly
that shape and escaped notice only because the scenario is `@todo`. The assertion is
now `the Grafana recycle bin setting reads on|off`.

### The recycle bin is off by default, and naming a folder does not enable it

Off by default: a move-out or delete is a true Grafana delete. Turned on, the
admin names an existing Grafana folder to act as the bin, so a delete MOVES
the dashboard there with its uid intact and a restore returns the same
dashboard. It is a setting of the app, not a property of a mapping — which is
why the bin folder may not itself be mapped.

The scenario walks the two settings **one at a time**, because that is the order
the panel allows and because the middle state is the interesting one: after naming
the folder the bin is still off, and nothing has started moving dashboards into it.
Naming a folder is not consent.


## mapping/manage-groups

`features/mapping/manage-groups.feature`

THE ONE FIELD A MAPPING LETS YOU EDIT. Everything else — the Grafana folder, the
Nextcloud folder, the storage backend, subfolder sync, the mode, the format — is
fixed at creation, and not by a guard that rejects a change but by the API shape:
`updateGroups()` takes an id and groups, the PUT takes `nc_groups` and nothing
else, and there is no update command. A caller cannot express any other change.

Split out of `admin-mapping.feature` so the editable field is not buried among
the immutable ones — the same split `nextcloud-penpot` made.

Both storage backends get their own Examples block because the provisioning
differs and the behaviour must not.

## mapping/sync-now

`features/mapping/sync-now.feature`

THE CARD'S OWN BUTTON — one mapping, on demand.

### NO SYNC-NOW SCENARIO CARRIES AN ORIGIN TAG

A sync is a NEXTCLOUD action — an admin presses a button, or the schedule fires.
There is no "sync to Nextcloud" in Grafana and there never will be, so
`@in-grafana` is simply wrong here however much Grafana the scenario mentions.
`features/README.md` already says it: *origin is decided by the `When`, not by
whichever systems the scenario happens to mention.*

And `@in-nextcloud` does not fit either, because its meaning is "someone acted in
Nextcloud, and the payoff is what reached Grafana" — a sync's payoff is what
reached NEXTCLOUD. The tag rule allows exactly one or neither; a sync is the
"neither" case, which is also how `kubed-io/nextcloud-penpot` leaves its own.

All four scenarios carried `@in-grafana` because the dashboards being in Grafana
felt like the Grafana-ness of it. That is the `Given`.

### Syncing one mapping fills its folder

SPLIT OUT OF THE INSTANCE-WIDE OUTLINE, which carried it as a third Examples row
beside "every mapping" and "the schedule". The row was honest — same pre-state,
same post-state — but the SCOPE is the difference, and a mapping-scoped action
belongs with the mapping.

THE FIXTURE IS NOW IN THE SCENARIO. It used to say a file named "Alpha Demo"
appears, and where "Alpha Demo" came from was invisible — `preload-grafana.sh`
writes it, which a reader of the spec has no reason to know. `the Grafana folder
… already contains:` declares the pre-state and seeds it find-or-overwrite, so
the scenario is true whether or not the preload ran.

AND THE COUNT IS GONE. `holds exactly 1 dashboard file` is the weakest possible
statement about a tree: it passes whatever the file is called and wherever it
sits. `the mapped folder … holds:` is the tree, and the metadata table is what
the file arrived carrying — the shape `kubed-io/nextcloud-penpot` settled on.

The root mapping came with the split for the same reason the card did: `/` with
subfolder sync on is still ONE mapping, and syncing it is the card's button doing
the largest job it can do.

## connection/connection

`features/connection/connection.feature`

The "admin makes the Grafana connection" use case — the app's "I'm logged in"
gate, a prerequisite to every other feature. The admin points the app at Grafana
(base URL), provides a service-account token, and tests the connection to confirm
the URL + token are valid and Grafana is reachable.

The test deliberately hits an AUTHENTICATED Grafana endpoint (GET /api/folders),
not the unauthenticated /api/health, so a green result proves the token itself is
valid — not merely that the host is up.

(Obtaining the token is out of the app's scope — that's the Grafana admin's job;
in the tests it's minted as setup, see tests/integration/bin/mint-grafana-token.sh.)

### The connection test says which of the two token problems it is

A sensitive token field renders blank whether or not a token is stored, so the
Test connection result is the admin's diagnostic — and it must tell the two
failure modes apart: "you haven't added a token" vs "the token you added was
rejected". Same distinct messages on the button and the occ command.

## dashboards/copy

`features/dashboards/copy.feature`

Copying a dashboard file. Where a MOVE is "the same dashboard" (see move-dashboard.feature),
a COPY is ALWAYS a brand-new instance. A copy never inherits the original's Grafana
identity — its metadata (grafana_uid, version, mapping, mode) is stripped the moment
it is copied. Copy is therefore the single safest point to strip metadata:
whatever the source was (sync, link, unmapped), the copy starts clean.

Nextcloud distinguishes copy from move at the event layer (NodeCopiedEvent vs
NodeRenamedEvent), which is what lets us treat them oppositely.

COPYING A FOLDER is a different question with a different blast radius — one
gesture, N far-side creates, and a folder identity of its own to strip. It lives
in copy-folder.feature.

STATUS: the copy path is built (CopyListener + CopyService, unit-tested). These are
@todo — the code exists, the WebDAV COPY step definitions do not — except where
noted. The file-level @todo this used to carry could not say that.

### The pull's own writes are never treated as a copy

── the pull must never look like a copy ─────────────────────────────────────────
The reconciler writes files into mapped folders, which at the event layer is
indistinguishable from a user copying one in. If the pull's own writes took the
copy path, every pull would mint a duplicate dashboard for every file it wrote —
the single worst failure this listener could have.

### Copying a link never creates a second dashboard

A link is a pointer body, so a copy of one holds a pointer and no dashboard JSON.
It must not inherit the pointer's identity, and it cannot become a sync file by
accident — there are no bytes to create a dashboard from.

### Nextcloud names the copy, and the extension decides whether that hurts

A copy landing beside its source collides, and NEXTCLOUD picks the new name. It
counts before the LAST extension — which is the entire reason the app's file
extension is now one segment.

**Nextcloud's server does not name a copy at all.** WebDAV COPY means "copy to
exactly this path"; if something is already there and `Overwrite: F`, the answer is
a flat 412. Nothing on the copy path calls `Folder::getNonExistingName()`.

The name is chosen in the BROWSER, by `getUniqueName()` from `@nextcloud/files`:

```ts
let i = 1
while (otherNames.includes(newName)) {
  const ext = extname(name)         // the LAST extension only
  const base = basename(name, ext)
  newName = `${base} ${opts.suffix(i++)}${ext}`
}
```

There is a `suffix` option and no way for an app to pass one — the Files app calls
`getUniqueName()` internally. **The rule cannot be changed.** So the app agrees with
it instead: `FilenameCodec::format()` puts the counter in exactly that position, and
a copy is therefore born with the name the codec would itself have chosen.

    Fleet Health (1).grafana        <- what the client picks, and what we want

#### What it cost when the two disagreed

Under the retired `.grafana.json`, `extname()` answered `.json` and the basename was
`Fleet Health.grafana`, so the client produced:

    Fleet Health.grafana (1).json   <- does not end in the app's extension

Every predicate in the app answered "not ours". Measured on the live instance: no
metadata, no dashboard in Grafana, clicking it did nothing — and the file still
carried the ORIGINAL's uid in its body, which is the most dangerous shape a stray
copy can take, because it looks like a dashboard and points at somebody else's
underneath. Reading it took a `canonicalise()` fold in front of every predicate, and
un-writing it took a rename in `ReconcileNameJob`, one cron tick later.

**And the rename could not be pulled forward.** The chosen name arrives as the COPY's
`Destination` header and Sabre fires `beforeMethod:COPY` while it is still a string,
so a plugin rewriting it there really does make the file be born correctly named. It
shipped, and the Files app answered *"The file does not exist anymore"*:

```js
await client.copyFile(source, destination)
if (node.dirname === target.path) {                 // copying into the SAME folder
    const { data } = await client.stat(destination) // the path IT chose
    emit('files:node:created', ...)
}
...
if (404 === e.response?.status) throw new Error('The file does not exist anymore')
```

The client stats the destination it picked, and **only when the copy landed in the
folder it came from** — precisely and only the case that collides, so the plugin
fired exactly when the stat would notice. Measured both ways on a live instance:

    intercepting   COPY 201 → STAT 404 → error dialog, no file until a refresh
    deferring      COPY 201 → STAT 207 → correct name one tick later

Neither branch of that trade-off exists now. Nothing intercepts the copy and nothing
renames it afterwards, because the client's name is already right.

#### The same one segment is what gives the file its icon

Nextcloud's detector reads only the last extension (`Detection::detectPath()`,
`strrchr`). `detectPath('Fleet Health.grafana.json')` answered `application/json`
and always did — the custom type came from `updateFilecache('grafana.json')`, a
table-wide `LIKE '%.grafana.json'` UPDATE that had to re-run on **every write**,
from `NodeWrittenListener`, the pull, and the create. `detectPath('Fleet Health.grafana')`
answers `application/grafana+json`, so the registration in `RegisterMimetype` is the
whole story and those three call sites are gone.

The single-extension sibling (penpot) never had either problem; the compound-extension
ones (grafana, and n8n until it follows) had both.

### A copy's clocks are its own

`copy.feature` pins `Created` and `Modified` in the post-state because a copy is a
BIRTH: a new dashboard exists in Grafana that did not before. Inheriting the
original's dates would date the copy before it existed, and the app already makes
a point of a mirror wearing the dashboard's clock rather than the sync's — so the
copy has to wear its OWN.

Adding those two rows caught a real defect, and not the one anyone was looking
for. **Grafana has no `meta.updated` to give in the instant after a create.** Read
the dashboard straight back from `POST /api/dashboards/db` and the answer carries
`meta.created` and an EMPTY `meta.updated`; a second later the same read carries
`updated`, equal to `created`. `MirrorTimes` reads an absent clock as "leave that
one alone" — correctly — so the file was given a creation date and kept whatever
mtime it already had.

That is why it survived every other feature file. On a NEW file the leftover mtime
is roughly right. On a COPY the file arrives wearing the SOURCE file's mtime: a
real, plausible timestamp belonging to a different dashboard. Nothing looks wrong
unless you compare the two sides, which is what the table now does.

Worth keeping for the next clock bug: the failure message prints Grafana's own
version history (`grafanaDashboardWrites()`), because "the file and the dashboard
disagree" has two causes that read identically — the stamp never happened, or it
happened and a second write moved the clock afterwards. One line of version
history separates them, and a CI run is gone by the time you could ask Grafana.

### The modified clock a copy cannot keep until the next sync

`Created` is asserted on a copy and `Modified` is not, and the asymmetry is
Nextcloud's rather than ours.

Both are written by one call, from one read, and the app gets it right — probed
inside a failing CI run:

    apply    wantMtime=1786771450  currentMtime=1786771449
    touched  to=1786771450         mtimeNow=1786771450

The stamp lands. Nextcloud then puts the modification time back to 1786771449
before the copy request ends, which is what a PROPFIND a moment later reports.
The creation time, written straight to the filecache, survives the same request.

So the file wears its dashboard's modified date only from the next sync onward,
and asserting it in the moment after the gesture is asserting something the
platform will not hold. Version-dependent, too: it reproduces on NC 33.0.8.2 and
does not on 33.0.4.1, so the row would be a coin flip across supported versions
even if it were worth keeping.

Worth revisiting when the cause is known — it is somewhere in the post-copy hook
chain, after `NodeCopiedEvent` and before the request ends. A deferred re-stamp
(the shape {@see ReconcileNameJob} already uses for the same class of problem)
would hold it, at the cost of a job per copy.

### The copy belongs to where it lands

A copy is never the original's identity — that is the whole feature. What decides
what the copy IS, though, is the folder it landed in, not the folder it came from:
a file copied out of a mapping into `Scratch` is a plain document, and a file
copied out of `Scratch` into a mapping is a new dashboard. So the source is an
Examples column and the destination is the scenario.

That is why there is one arrange for every source. `a dashboard file in "<folder>"`
makes a file wherever the scenario says, and the Background says what that folder
is; the arrange asserts nothing about a uid, because outside a mapping there
isn't one and that is the point rather than a failure.

The original is checked as pre/post state — the uid it had, it still has, and its
dashboard is still in Grafana. That single sentence replaced three separate claims
(it kept its uid, it was not restored, it was not duplicated), which were three
wordings of "the original did not move".

### A copy made in Nextcloud is named by Nextcloud

**Who performed the gesture decides who names the result.** A copy made in the
Files app is named by Nextcloud — it has to be, because the bytes it copied would
otherwise collide with the file they came from — and that name is then the copy's
real name in all three places: the filename, the JSON `title`, and the dashboard in
Grafana.

Left to itself, none of that happened. Copying `Fleet Health.grafana` beside
itself produced, on the live instance:

    file    Fleet Health (1).grafana   <- the only place the copy's name appeared
    JSON    "title": "Fleet Health"    <- the ORIGINAL's name, copied verbatim
    Grafana  Fleet Health              <- a second dashboard, same title

Three places, two answers, and a Grafana folder holding two dashboards nothing could
tell apart. The body cannot be blamed for it: a copy's bytes ARE the original's, so
of course its `title` says the original's name. The only party that knows a copy
happened is the file's name.

So the filename is the authority, for exactly the reason it is on a rename: it is
the thing that just changed. `CreateService` reads the display name off the filename
and puts it on the spec before the upsert, so Grafana is right inside the request.
Only the file's own JSON `title` lags, by a tick, fixed by `ReconcileNameJob` —
the copy hook cannot write the file it is holding locks on (saga §2, round 6). The
NAME on disk does not lag at all any more; see the extension note above.

**The name it reads is `display`, not `name`.** `FilenameCodec::parse()` returns
both, and they differ by exactly the collision counter. Taking the counter-stripped
one — the field a PULL wants, so a mirror already wearing `(1)` is matched to its
dashboard next time — is what let the copy reach Grafana under the original's title.
`FilenameCodec::displayName()` exists so that mistake has to be made deliberately.

### A copy made in Grafana is named by Grafana

The mirror image, and the one exception to *a name is one value living in three
places*.

Grafana permits two dashboards in one folder to share a title; Nextcloud cannot
permit two files in one folder to share a name. Both constraints are real, and only
one of them is Nextcloud's business. So the counter goes on the FILENAME and stops
there — the JSON `title` and the Grafana dashboard keep saying the duplicated name,
because that is what their owner called them.

    Fleet Health.grafana      title "Fleet Health"   Grafana: Fleet Health
    Fleet Health (1).grafana  title "Fleet Health"   Grafana: Fleet Health

Same filename shape as a Nextcloud-side copy, deliberately: Nextcloud's constraint
is satisfied identically either way. What differs is whether the counter is allowed
to travel. Outward it must — it is the real name. Inward it must not — renaming
someone's dashboard because our filesystem is fussy is overreach.

`copy.feature` covers this in one Scenario Outline, and the arrange is the whole
reason it works now: the "copy in Grafana" step used to append " Copy" to the title,
which is what Grafana's dialog PRE-FILLS and not what it enforces. A renamed copy
cannot collide, so the rule the scenario exists to pin was never once exercised.

### The second suffix, and the pull that used to fight it

Three dashboards, not two, because **two is the case that passes by accident**: a
counter that only ever reaches `(1)` is indistinguishable from an app that appends a
fixed string, and the second suffix is where an off-by-one would live.

**The word "still" in the last line is doing real work.** Landing three names is the
easy half; KEEPING them is where `SyncService` was wrong. For an existing mirror it
asked for `FilenameCodec::format($displayName, $uid, false, 0)` — collision index
zero, unconditionally — so on every single tick both duplicates were told to go and
take the name the first mirror was sitting on.

It "worked" because the move threw and the catch logged `rename skipped
(collision?)`. An exception is not a naming policy, and that log line's own question
mark says nobody was sure it was one. Anything that made the move succeed — the
incumbent deleted in Grafana, a differently-ordered pull — and the mirrors would
start swapping names underneath the user.

`desiredMirrorName()` replaces it: prefer the plain name, else the first free
counter, and count the file's OWN current name as free. That last clause is what
lets a legitimate duplicate keep the suffix it has, and it also means a mirror whose
twin was deleted gets its unsuffixed name back instead of wearing a counter forever.

**The second sync that proves it does not get a line of its own.** It is a
mechanism — how the claim is checked, not what the user ends up with — so it lives
inside the `are still titled` step, which reads the folder, syncs once more, and
checks that neither the titles nor the names moved. A scenario saying "and sync
again" out loud would be narrating the app's plumbing; `still` already means the
state held.

### A folder copied in Grafana is indistinguishable from a new one

`folders/copy.feature` has no ordinary Grafana-side scenario, and that is a
`@decision` rather than a gap.

Grafana has **no duplicate-folder call**. Someone "copying" a folder there creates a
folder and creates dashboards in it, and from this app's side that is exactly what
arrives: a new folder with new uids and new dashboards, reaching Nextcloud through
`folders/create.feature`. There is no signal that says *this one is a copy of that
one* — the resemblance is in the titles and the panels, which is far too arbitrary
for code to act on.

So the app does not try. A duplicated folder is mirrored as the new folder it is,
and the scenario records the absence so the asymmetry with the Nextcloud side reads
as a decision instead of an oversight.

### A folder moved in Grafana is recognised by its uid

The Nextcloud half of a folder move was the defect the mapping re-key fixed; the
Grafana half is the mirror of it, and it needs nothing new to be correct — a uid does
not change when a folder is re-parented, so the reconcile sees the same folder in a
new place and moves the Nextcloud one to match.

Read by NAME this is one folder vanishing and another appearing, and a name-keyed
mirror would delete three dashboards and re-create them under new uids. Read by UID
it is one folder with a new parent.

### A move and a rename at once is just a move

**Grafana can do both in one request where a Files gesture cannot.** `POST
/api/folders/{uid}/move` re-parents and `PUT /api/folders/{uid}` renames, and nothing
stops a client doing them back to back before the next reconcile — so the app can
observe a folder whose parent AND title both changed since it last looked.

That is not a race to defend against, and it does not need a rule of its own: the uid
identifies the folder, so the two changes are just the new pre-state. It is a MOVE to
a new place under a new name, applied in one step. Treating it as anything more
elaborate — a delete plus a create, or a rename that must be sequenced after a move —
would be inventing a problem the id already solved.

The scenario exists to pin that, because it is the case a name-keyed implementation
gets catastrophically wrong and an id-keyed one gets right without trying.

### A copied folder is a new folder

A folder copy creates a second Grafana folder holding second dashboards, each with
its own uid. Nothing is ever claimed twice — which is the whole reason the feature
needs stating at all.

**The file used to hold two mutually exclusive specs.** Four scenarios described
the copy creating new dashboards; a fifth said a folder copy is REFUSED rather than
bulk-duplicating them, on the grounds that forty dashboards is a lot to make by
accident. Both cannot be the behaviour, and a file that says both says nothing.

Creating wins, for the same reason the forty-dashboard warning was dropped from
`folders/move.feature`: a copy destroys nothing. The warning argument is about
irreversibility, and there is none here — the user can delete what they did not
want, and the originals were never touched.

### A folder scenario is about the folder

What happens to each file INSIDE a copied folder is `dashboards/copy.feature`'s
subject, and it says it once there. A folder scenario says what the folder holds
afterwards, on both sides — the same files it had, and a Grafana folder with the
dashboards to match.

That is what collapsed four scenarios into one: "dashboards never inherit the
originals' uids" and "copying a folder creates new dashboards" are one gesture with
one end state, and "the copy does not inherit the folder uid" and "…does not
inherit the `grafana` tag" were the same again for the folder itself. The tag half
is gone entirely — see the nesting model, which retired it.

### A copy never changes a file's mode

A `link` folder is a read-only projection of Grafana. That is the ONLY thing a
link is for, so a copy may not cross the boundary in either direction: not into a
link folder (there is nothing a local file could become there) and not out of one
into a mapping (that would promote a read-only pointer into an authored
dashboard). Both directions end in the same refusal, so they are two rows of one
scenario rather than two scenarios.

The sibling n8n and penpot apps support no such crossing either. Copying a link
file OUT to an unmapped folder is still fine — the result is a plain document,
not a mode change.

### RETIRED — a copy whose dashboard cannot be created

The scenario opened `Given Grafana will reject the creation`, which is not a
pre-state at all: it is a premonition, an action the server has not taken yet,
written as though it were something already true. It also never said WHY the
rejection happened or what came back, so nothing about it could be implemented
against.

It is gone rather than fixed, because for a COPY it describes something that can
barely occur: the copy's body is the original's body, and the original was already
accepted by Grafana. A rejection at copy time would mean Grafana changed its mind
about bytes it had taken. The real failure — a body Grafana will not accept — is
specified once, in `create.feature`, where a body first meets Grafana, and it is
stated as a property of the FILE rather than a mood of the server.

### A dashboard copied in Grafana arrives as its own file

The mirror image: someone duplicates a dashboard in Grafana. The pull sees a new
uid in the mapped folder and mirrors it like any other new dashboard — the copy
has no special status on the way in, which is the point.

### A duplicate made in Grafana takes the mapping's mode, not the original's

A duplicate made in Grafana belongs to the mapping it landed in, so it takes THAT
mapping's mode — not whatever mode the dashboard it was copied from happened to
have. Mode is a property of the mapping, never of the dashboard.

### A copy whose dashboard cannot be created stays a plain file and says so

The copy already exists in Nextcloud by the time we call Grafana, so a failure
cannot un-copy it. It must be left as a plain untracked file rather than one
carrying a uid that names nothing — and the user has to be told, or they are
holding a file that looks managed and is not.

## folders/copy

`features/folders/copy.feature`

Copying a FOLDER — the folder half of copy-dashboard.feature.

── THE RULE COPY ALWAYS FOLLOWS ─────────────────────────────────────────────────

A copy is ALWAYS a new instance, never a second claim on an existing one. For a
dashboard file that means the copy's `grafana_uid` is stripped and a fresh
dashboard is created (copy-dashboard.feature). For a folder the same rule has to
hold twice over: the copied folder must not inherit the original's
`grafana_folder_uid`, and neither must any dashboard file inside it.

The failure this prevents is the one that has no clean repair: two Nextcloud
folders both stamped with one Grafana folder uid, or two files both claiming one
dashboard. The next pull then has two candidates for one uid and no way to choose,
and whichever it writes, the other silently diverges.

── COPY, DO NOT REFUSE — SETTLED ────────────────────────────────────────────────

This file used to carry both readings at once: four scenarios creating new
dashboards, and a fifth refusing the copy outright the way the penpot sibling
refuses a project-folder copy. Creating won — a copy destroys nothing, so the
irreversibility argument behind refusing does not apply, and the same reasoning
retired the forty-dashboard warning from `folders/move.feature`. The reasoning is
in [A copied folder is a new folder](#a-copied-folder-is-a-new-folder).

There is no tag to worry about copying. The `grafana` opt-in tag a previous cut of
this section described was retired with the nesting model, so a copied folder is
plain by construction rather than by being stripped.

── STATUS ───────────────────────────────────────────────────────────────────────

Nextcloud fires `NodeCopiedEvent` per node, and `CopyListener` acts on Files only,
so the FILES inside a copied folder already take the normal copy path. Everything
about the folder's own identity is @unbuilt: nothing reads or strips
`grafana_folder_uid`, and although `GrafanaClient::createFolder` now exists, no
listener calls it.

### The pull's own folder writes are never treated as a copy

The reconciler writes files into mapped folders, which is indistinguishable from
a user copy at the event layer. If the pull's own writes triggered the copy path,
every pull would mint duplicate dashboards.

## dashboards/create

`features/dashboards/create.feature`

Creating dashboards from Nextcloud. These scenarios are the human-readable spec
for the "author in NC, live in Grafana" flow: a .grafana written over WebDAV
into a mapped folder fires NodeWrittenEvent → the create listener → the dashboard
appears in Grafana. The Grafana side is asserted over its REST API; the NC stamp over
DAV PROPFIND of nc:metadata-grafana_uid.

CREATING A FOLDER is the other half, and it works the opposite way round: a new
folder is inert until it is TAGGED, because a mapped folder must stay usable for
ordinary things. See create-folder.feature — the asymmetry is deliberate and
explained there.

STATUS: create-on-land is built (CreateService + CreateInGrafanaListener,
unit-tested and live-verified). @todo means the WebDAV step definitions are
missing, not the code.

### The mappings in the Background

Two mappings, declared as tables — the Grafana folder, the Nextcloud folder and
the mode spelled out per line — plus one folder that is mapped to nothing. Every
scenario then names the folder it acts in, and the Background is what says what
that folder IS. Nothing restates "sync" or "unmapped" in a step.

The pair is the whole input space for a create: a `sync` mapping, a `link`
mapping, and outside every mapping. The mode is not a scenario, it is a property
of the folder the file landed in.

**Declaring a second mapping used to wipe the first, silently.** `a mapping with
the following values:` reset the store on every call, so a Background could only
ever describe ONE mapping and nothing said so — `tags.feature` had been carrying
two since the tag work, of which only the second existed at runtime. The reset now
happens once per scenario, on first use, which is all the isolation was ever for.
The sibling n8n app hit this first and was fixed the same way.

### A link mapping authors nothing

A link folder is a read-only projection of Grafana. A file appearing in one is a
local file, and it can never become the dashboard it looks like — so the app
should refuse the write rather than leave a `.grafana` sitting there looking
managed. Creating the dashboard in Grafana is how a link folder gains a file.

The scenario is @unbuilt: today the app accepts the file and leaves it unmanaged,
which is the "creates no dashboard" claim the old scenario made. That claim was
true and useless — it described what did not happen rather than what should.

### RETIRED — the uid scenarios (a file carrying a uid, live or dead)

Two scenarios were removed rather than converted:

- *a file that already carries a uid re-adopts its dashboard instead of creating one*
- *a file carrying a uid that no longer exists is created fresh*

Both described a file ARRIVING somewhere with an identity already on it, which is
a move, not a create — and both are already covered by `move.feature`, where the
gesture belongs. Read as creates they describe something improbable: a file
authored fresh has no uid to carry, so the premise only holds if someone hand-wrote
one, or if a duplicate was dragged in. The uid is a post-condition on a structure,
not an action.

### RETIRED — two dashboards with the same title arrive as two distinct files

Two files being two files is not a behaviour. What the scenario was reaching for —
that a filename collision is resolved rather than one dashboard overwriting
another — is a property of the naming rule, not of creation, and a count of files
is the weakest possible statement about a tree.

### RETIRED — a new dashboard is named after the file

The name is end state, asserted where it is produced: the create scenario now says
the dashboard is named after the file, in the mapped Grafana folder, as part of
what that gesture leaves behind. A value on an object is not a behaviour.

### A file that already carries a uid re-adopts its dashboard instead of creating one

A file arriving with a uid already in its JSON is a re-adoption, not a create —
a dashboard exported from Grafana and dropped back in, or a file restored from a
backup. Minting a second dashboard for it would fork the two copies apart.

### A file carrying a uid that no longer exists is created fresh

…and one carrying a uid that names nothing is a create, not a failure. The uid is
stale — from a deleted dashboard or another instance — and the file's content is
the thing worth keeping.

### A failed creation leaves an unstamped file, not a half-managed one

The file exists in Nextcloud before Grafana is called, so a failed create cannot
be rolled back into "nothing happened". Leaving it unstamped is what lets a later
save or pull retry it, rather than leaving a file that claims a dashboard it does
not have.

## folders/create

`features/folders/create.feature`

HOW A FOLDER BECOMES A GRAFANA FOLDER, AND HOW YOU CAN TELL THAT IT IS ONE.

── WHY FOLDERS GET THEIR OWN FEATURE FILES AT ALL ───────────────────────────────

In the n8n sibling a "folder" is a Nextcloud convenience: n8n maps by TAG and has
no folder concept, so there is nothing on the far side for a folder gesture to
mean. Grafana has REAL folders, so every folder gesture is a question about two
systems, exactly as a file gesture is. Splitting `<action>-dashboard` from
`<action>-folder` is what makes the two readable side by side — and it is what
made the state of this surface legible at all (see STATUS below).

── THE ASYMMETRY ────────────────────────────────────────────────────────────────

    every mirrored Grafana folder            →  a folder in Nextcloud   (automatic)
    a Nextcloud folder with a dashboard in it →  a folder in Grafana    (automatic)
    a Nextcloud folder with anything else     →  nothing

A folder created inside a mapped folder is an ORDINARY FOLDER until a dashboard
lands somewhere beneath it. Nothing is sent, nothing is inferred, and it can hold
anything the user likes — notes, exports, screenshots, a subfolder of references.
**A mapped folder is a real folder and must stay usable for ordinary things.** A
mapping that turned every subfolder into a Grafana folder on sight would make the
mapped folder unusable for anything but dashboards, which is not a trade a user
agreed to.

The rule deciding which is which is the nesting model, in
[A subfolder is in Grafana when a dashboard is in it](#a-subfolder-is-in-grafana-when-a-dashboard-is-in-it)
— which also records why the `grafana` opt-in tag this section used to describe was
retired, and why the per-mapping "Sync subfolders" flag it would have replaced is
dead weight rather than a fallback.

── THE ID DECIDES, AND IT IS THE ONLY MARKER ────────────────────────────────────

`grafana_folder_uid` on the folder is what the app acts on: a folder carrying one
is a Grafana folder, whoever made it one. There is no second, visible badge to keep
in step with it — one fewer thing that can disagree with itself, and the same
reason dashboards are tracked by `grafana_uid` rather than by filename. Names
change; ids should not.

── STATUS: THE CLIENT CAN WRITE FOLDERS; NOTHING CALLS IT YET ───────────────────

`GrafanaClient` now has `createFolder`, `renameFolder`, `moveFolder` and
`deleteFolder` alongside the reads, each measured against a live Grafana 13.0.2
rather than taken from the docs — the surprises are written on the methods (a
rename is refused without `overwrite`; a delete cascades through every descendant).

**That is the API layer and nothing above it.** No listener subscribes to a folder
event, and nothing resolves a Nextcloud folder to a Grafana one. So every scenario
here is still @unbuilt: the call exists, but nothing reaches it. The gap is
deliberate — the mapping is still keyed by PATH STRING, so a folder rename or move
orphans it, and building folder gestures on that is building on the defect.

The scenarios that ARE testable today are the ones whose correct outcome is that
nothing happens in Grafana — which is also the default path, and the one that keeps
a mapped folder usable.

NOTE ON REQUIREMENTS: the nesting model needs no new Nextcloud event, because a
dashboard being created is already observed. The `TagAssignedEvent` floor of
Nextcloud 32 that the tag model would have forced is one of the things retiring it
bought back — `appinfo/info.xml` can stay where it is.

### The recycle-bin folder's name is reserved

The recycle-bin folder holds parked dashboards and dashboards Nextcloud does not
manage (see `dashboards/delete.feature`). Letting a user's folder resolve to it
would put the app's own scratch space under user control.

## dashboards/delete

`features/dashboards/delete.feature`

Deletion semantics — the highest-stakes surface in the app, because Grafana has NO undo.

THE FINDING THAT SHAPES THIS FILE: we proved on live Grafana that the service account
cannot reach any soft-delete/trash. A `DELETE /api/dashboards/uid/{uid}` is PERMANENT.
So the master's (n8n) recipe — trash=archive, purge=delete, restore=unarchive — does not
translate: Grafana has no archive to fall back to.

THE RE-PLATED MODEL — Nextcloud's recycle bin IS the feature we're adding to Grafana.
Grafana can only ever *delete*; Nextcloud has a real trash you can restore from, so
deleting a dashboard file is native NC trash on the Nextcloud side + a Grafana action
that depends on ONE optional setting — the **Grafana recycle-bin folder**.

── THE BIN IS A SHIM WE BUILT, NOT A FEATURE GRAFANA HAS ────────────────────────

This is the single most important sentence in the file. **Grafana has no trashbin at
all.** Not a hidden one, not an admin-only one — a service account cannot reach any
soft-delete, and `DELETE /api/dashboards/uid/{uid}` is final. The n8n sibling has
archive/unarchive and Penpot has its own bin; Grafana has nothing, so we could not
port either recipe.

What the "Grafana recycle-bin folder" setting does is EMULATE one, by designating an
ordinary Grafana folder as a parking space. Everything follows from the word
*ordinary*:

  - It is **visible in Grafana's own UI.** Anyone browsing folders sees it and the
    dashboards in it. There is no "deleted items" chrome telling them what it means.
  - **Anyone can move things out of it** — a colleague spotting a dashboard they still
    need can simply drag it back. That is a rescue, and it must be respected.
  - **Anyone can delete things in it,** or delete the folder itself.
  - **It can hold dashboards Nextcloud never managed.** It is a folder; people put
    things in folders.
  - **It outlives this app.** Uninstalling does not empty it.

A real trashbin is privileged storage the owning system controls. This is a shared
folder with a name we agreed on. Every rule below that looks paranoid is paranoid for
that reason, and the sharpest one is on the purge: we delete a parked dashboard only
while it is **still in the bin**. If it has been moved out, emptying a Nextcloud trash
is not authority to chase it down and destroy it.

WITH THE SHIM OFF — the default — none of that exists and the model is simply: trash
the file, the dashboard is gone, and the file's JSON in the Nextcloud trash is the only
copy left. Every scenario below states which world it is in, because they share almost
no behaviour.

── RULE: TWO DELETE MODELS, AND THEY AGREE ABOUT NOTHING ────────────────────────

  |                        | bin OFF (default)          | bin ON (opt-in)              |
  |------------------------|----------------------------|------------------------------|
  | trash a sync file      | true Grafana DELETE, now   | dashboard MOVED to the bin   |
  | the file's uid         | STRIPPED (the id is dead)  | KEPT                         |
  | restore                | create-on-land → NEW uid   | moved back, SAME uid         |
  | empty the NC trash     | Nextcloud-only no-op       | THE irreversible step        |
  | point of no return     | the trash gesture          | emptying the trash           |

Read that table as two columns, never as one story. The bin setting does not tune the
behaviour, it *replaces* it — including which gesture is the one you cannot undo. Every
scenario below therefore states its model in a `Given`, and the two are never rows of a
single Scenario Outline (features/README.md explains why that would hide the asymmetry).

THE BIN FOLDER IS NOT A MAPPING. It may hold dashboards Nextcloud does not manage, so no
operation here ever clears it wholesale — only the specific items being purged.

── RULE: THE TWO STEPS ARRIVE THROUGH TWO DIFFERENT DOORS ───────────────────────

  - trash-move (soft) → `BeforeNodeDeletedEvent`, a typed event → DeleteToGrafanaListener
  - purge     (hard)  → the legacy `\OCP\Trashbin` `preDelete` hook → TrashPurgeHook

Nextcloud dispatches NO typed event for a purge. That hook only fires if the app is
LOADED on a WebDAV request, which needs `<types><filesystem/></types>` in info.xml —
missing until this PR, so with the bin on, emptying the trash silently left every parked
dashboard alive in Grafana forever. A perfectly correct hook in a method that never ran.

── RULE: NEXTCLOUD DRIVES; GRAFANA DOES NOT DRIVE BACK ──────────────────────────

Nextcloud's trash is the user's own undo history, and this app does not reach into it in
either direction. A dashboard deleted in Grafana does not empty a Nextcloud trash, and
the pull cannot see into the trash at all (`SyncService` indexes the folder listing, and
a trashed file is not in the folder). Sometimes that blindness is right and sometimes it
is a gap — the `@unbuilt` scenarios at the bottom are the same blindness, benign in one
case and harmful in the other. Worth reading them as a pair.

STATUS: the delete engine IS cooked (Course 4 · Slice 3) — DeleteService +
DeleteToGrafanaListener + RestoreFromTrashListener + TrashPurgeHook. The rule table is
unit-tested (failed-delete-never-strips, bin-ON-parks-not-deletes, bin-OFF-restore-
recreates) and verified live on the pod. The scenarios below are tagged per-scenario:
live where the WebDAV trash/restore/purge steps now exist, @todo where they do not
yet, @unbuilt where there is genuinely no code, @blocked where the harness cannot
reach it. The file-level @todo this used to carry hid that distinction entirely.

THE PURGE SCENARIOS ARE THE REGRESSION TEST FOR THIS PR'S <types> FIX. A purge fires
no typed Nextcloud event — only the legacy `\OCP\Trashbin` `preDelete` hook, which
runs only if the app is LOADED on a WebDAV request. Before `<types><filesystem/></types>`
they would have failed; that they can pass at all is the proof.

### Emptying the trash for a bin-off file touches nothing in Grafana

The other half of the rule — that the dead uid is STRIPPED — cannot be observed
here: Nextcloud's trashbin DAV endpoint serves no `nc:metadata-*`, so every key
reads null for a trashed file whether it was stripped or not. Asserting it here
passed vacuously until its bin-on counterpart proved the surface was dead.

It is observable one step later, and restore-dashboard.feature asserts it there:
a stripped file restores as a NEW dashboard with a NEW uid, a kept one restores
to the same dashboard. Identity is proven by what comes back, not by reading a
property off a file in the bin.

### A purge that cannot reach Grafana clears the file without deleting anything

Cannot prove it is still parked → do not delete. Leaving a dashboard alive that
could have gone is a recoverable leak; deleting one that should have lived is not.
The Nextcloud side of the purge still completes, because the user's gesture was to
empty their trash and that much is always safe.

### RETIRED — the admin purge

`purge.feature` used to describe an admin button that removed every dashboard file
the app had created, across every mapping, on the promise that a later sync would
bring them back. That promise only held for files that were faithful mirrors, and
the ones that were not are exactly the ones you would miss.

It was never built here — all three scenarios were @todo — so retiring it is a
matter of deleting the spec rather than the code. The sibling n8n app HAD built it
and removed it for the same reason. Purge now means one thing in both apps:
emptying the Nextcloud trash, which finishes the delete the trash gesture started.

### Purging never deletes a dashboard someone rescued out of the bin

Purging one of several parked dashboards must not sweep the rest. Same rule as
above, stated for the case people actually hit — a trash holding several of OUR
files, only one of which is being cleared.
── THE RESCUE: the shim is a folder, so people take things back out of it ───────
A colleague browsing Grafana sees a dashboard they still need sitting in
"nextcloud-trash" and drags it back where it belongs. Weeks later the original user
empties their Nextcloud trash. The purge must NOT chase the rescued dashboard down.
Deleting by uid alone would destroy a live, in-use dashboard, and Grafana has no undo.

### The Grafana bin needs the Nextcloud trash

The recycle-bin folder is the Grafana half of the Nextcloud trash: trashing parks
the dashboard, and emptying the trash is what finally deletes it. Turn the
Nextcloud trash off and the second half never comes — the dashboard would sit in
the bin folder forever, with nothing left in Nextcloud that could ever purge it.

So the bin does not operate without the trash. A delete with the Nextcloud trash
disabled deletes the dashboard outright, whatever the bin setting says.

`files_trashbin` is a shipped Nextcloud app and CAN be disabled, so this is a real
configuration rather than a hypothetical — it is simply one the app declines to
support in bin mode. Neither n8n nor penpot models a disabled trash at all.

### RETIRED — deleting a dashboard in Grafana whose file is already trashed

Reaching it requires a mapped file to be in the Nextcloud trash while Grafana was
never told, which the trash gesture does synchronously. It is therefore only
reachable when a mapping is ALREADY out of sync — a bug state, not a behaviour, and
a spec written against it would be a spec for broken code.

The sibling n8n app still carries the equivalent (`purge.feature`, @unbuilt), and
it states the OPPOSITE outcome — the trashed file is left alone. The two apps
should not disagree; resolving that is open.

### Restoring a sync file re-creates the dashboard with a new id (bin off)

With the recycle bin off, the dashboard was destroyed when the file was trashed —
Grafana has no undelete, so there is nothing to bring back. A restore therefore
builds a NEW dashboard from the file's body: same content, new uid, new URL, empty
version history.

That is stated as `its own, not the one it arrived with`, the same expectation the
copy and move specs use, because the failure it guards against is the same one:
silently reusing an id that names something else, or nothing.

### Emptying the bin in Grafana finishes the delete

The bin folder is an ordinary Grafana folder, so anyone may clear it — and clearing
it is the same gesture as emptying the Nextcloud trash, performed from the other
side. A dashboard that was parked and is now permanently gone leaves a trashed file
with nothing to be restored to, so that file is purged too.

This is the legitimate twin of a scenario that was REMOVED from `delete.feature`
("a dashboard deleted in Grafana whose file is already in the trash"). That one was
only reachable when a mapping was already out of sync — a bug state. This one is
reachable by design, because parking a dashboard is exactly what bin mode does and
the bin is a folder like any other.

OPEN — the trashed file's body still holds the full dashboard JSON, so purging it
destroys the last copy. That is the same bargain the Nextcloud trash already makes
when the user empties it themselves; whether the app should make it on their behalf,
from a gesture performed in Grafana, is worth a second look before this is built.

### The purge deletes what is in the bin, and only that

Emptying the trash deletes the dashboard **if it is still parked in the bin folder**
and does nothing to Grafana otherwise. Two situations reach "otherwise", and the app
cannot tell them apart at purge time: someone rescued the dashboard back into its
mapped folder, or someone deleted it outright.

**Both are races that would not exist if the mirror were instantaneous.** A purge
racing a rescue is a purge that ran before the rescue was visible; a purge racing a
delete is one that ran before the delete was. Because the mirror is not instant, both
are reachable, and both must end the same way — which is why they are two rows of
one scenario rather than two scenarios.

The end state is stated as a pre/post comparison — *the dashboard is as it was before
the purge* — because "nothing was deleted" cannot be asserted directly, and the two
rows have different dashboards to compare (one alive, one already gone).

Leaving a dashboard alive that could have gone is a recoverable leak; deleting one
that should have lived is not.

### The suffix is Nextcloud's alone

**Grafana permits two dashboards with the same title in one folder.** Verified
against Grafana 13.0.2: two `POST /api/dashboards/db` with the same title, the same
`folderUid` and `overwrite:false` both return 200, with different uids and the same
slug. Nextcloud cannot do that — two files in a folder cannot share a name.

So the app forces Nextcloud's rule onto the Nextcloud side ONLY. The second file
takes a numbered suffix in its FILENAME; the JSON `title` and the Grafana title are
left saying the real, duplicated name. Nothing is renamed in Grafana, and nothing is
rewritten inside the file — the body already carries the true title and the metadata
already carries the uid, so the filename is free to be a local display concern.

**Which one takes the suffix is knowable, not arbitrary.** The app holds the prior
state: the file already carrying that name had it first, so the arriving dashboard
is the one that gets suffixed. Renaming the incumbent instead would move a file the
user never touched.

This is the one exception to *a name is one value living in three places*: the
filename may carry a suffix the other two do not. That is a Nextcloud constraint
leaking into a Nextcloud-only field, which is the cheapest place for it to leak.

### A subfolder shares its name with Grafana exactly

A subfolder under a mapping has the same name as its Grafana folder — the same
string, case and all. `demo` is not `Demo`.

There is nowhere to record a difference. A MAPPING can pair two unlike names because
the mapping row holds both; a subfolder has only itself, so its name IS the Grafana
folder's name and a rename on either side is a rename on both.

A folder under a mapping that carries no `grafana_folder_uid` is not a subfolder in
this sense at all — it is an ordinary Nextcloud folder that happens to sit inside a
mapped tree, and it needs no scenarios, because the app does nothing to it.

### A Nextcloud folder carries its Grafana folder uid

A folder mirrored into Grafana stores that Grafana folder's uid in its own
Nextcloud metadata, exactly as penpot stores `penpot_project_id` on a project
folder. Penpot's `ProjectTags` states the principle outright: *the tag is not
authoritative, the id is.* The same holds here, and it is what lets the folder
gestures be specified at all.

**Without the id, a rename is indistinguishable from a delete plus a create.** A
reconciler seeing "a folder named X is gone and a folder named Y is here" has two
readings and no way to choose: delete X's dashboards and re-create them under Y, or
rename. The first loses uids, history and URLs. With the id on the folder, the same
observation reads unambiguously as "the folder holding uid U is now called Y", and
the fix is a rename on either side. Moving a folder is the same argument.

It is PRE-STATE AND POST-STATE, not a mechanism: a scenario says a folder carries a
uid the way a file says it carries one, in a table.

**Naming.** `grafana_folderUid` already exists as a FILE key — "the Grafana folder
this dashboard is in" — and is banked, never written. It should be retired rather
than sat beside a folder key differing from it by one character: with the folder
carrying its own uid, a dashboard's Grafana folder is simply its parent's, and a
second copy on every file is a denormalisation that can go stale. The folder key is
`grafana_folder_uid`.

### When a folder deleted in Grafana may delete the Nextcloud folder

Deleting a Grafana folder deletes the dashboards in it. What Nextcloud does depends
on what else is in the mirrored folder:

- **Only dashboards** → delete the Nextcloud folder too. Everything in it came from
  the folder that was just deleted, so nothing of the user's is lost, and Nextcloud's
  trash makes it reversible anyway.
- **Anything else** → delete only the dashboard files, leave every other file where
  it is, and remove `grafana_folder_uid` from the folder. The folder stops being a
  mirror and goes back to being an ordinary folder. Deleting a user's spreadsheets
  because a Grafana folder went away is not a trade the app gets to make.

The reverse direction needs no such care. **Deleting a folder in Nextcloud is
always safe to honour**: it is an ordinary Nextcloud delete, everything lands in the
trash, and Nextcloud is better at restoring than this app would be.

### A folder the user made for something else stays theirs

A pull claims the folders it mirrors and no others. Under the tag model this needed
saying twice — once as "do not tag what you did not make", once as "do not stamp an
id on it". With the tag gone there is one marker left, so there is one rule: a
folder holding no `grafana_folder_uid` is a folder the app has never had anything
to do with, and a pull leaves it exactly so.

### A subfolder shares its name with Grafana exactly, case included

A mapped folder may pair two different names — that is the mapping's whole job, and
the one place a differing pair is legitimate. **A subfolder has nowhere to keep such
a record**, so it is matched by name, and the match is exact: same string, same case.
`Team` is not `team`.

**The specs used to obscure this by pairing `demo` with `Demo`.** Twenty files
mapped a Grafana folder to a Nextcloud one that differed only in capitalisation,
directly above subfolders that had to agree exactly — so a reader could not tell
which difference was deliberate and which was a slip, and neither could a reviewer.
The mapped pairs are now identical strings (`Demo` ↔ `Demo`), which leaves exactly
one differing pair in the suite — `links` ↔ `Pointers` — where the difference is
obvious, intentional, and impossible to read as a typo.

The rule to keep: if two names in a scenario differ, the difference must MEAN
something. Case is not a meaning.

### Both ways a Grafana folder is born from Nextcloud live in folders/create

There are exactly two: CREATE a dashboard in a new subfolder, or MOVE one into it.
They are the same rule — a folder is in Grafana when a dashboard is in it — reached
by two gestures, so they sit together in `folders/create.feature` rather than being
split by the verb that happened to trigger them.

The move one used to live in `dashboards/move.feature`. Filed by verb it read as a
move rule, which is the wrong emphasis: nothing about the DASHBOARD is interesting
there (it keeps its uid, its mapping and its mode), and the whole point is what
happens to the FOLDER. `dashboards/move.feature` keeps the gestures where the
dashboard is the subject — leaving a mapping, crossing between them, the bin.

### A subfolder is in Grafana when a dashboard is in it

**The `grafana` tag on subfolders is retired.** A folder under a mapping exists in
Grafana exactly when a dashboard lives somewhere beneath it — parents included. No
tag, no opt-in.

The tag model was borrowed from penpot, where it fits: penpot has teams → projects
and nothing deeper, so "which projects are real" is a flat question. Grafana nests
arbitrarily, like Nextcloud, and the tag then has to answer questions it cannot:
`foo(mapping)/bar(tagged)/baz(untagged)` holding a dashboard in `baz` would leave
Grafana with nowhere to put it, and `bar(untagged)/baz(tagged)` is the same problem
upside down.

Following the dashboards answers all of them at once. A leaf holding spreadsheets
and no dashboards simply never appears; a dashboard three folders deep creates all
three. Grafana 13 has native nested folders (`/api/folders` carries `parentUid`),
so the shape is expressible on the far side.

### A Grafana folder outlives its last dashboard

When the last dashboard leaves a folder — trashed, moved out, or deleted in Grafana
— the folder stays on BOTH sides, mapped and holding nothing. **Emptying is not
deleting.**

Deleting it is destructive and racy: the app would have to decide on every single
dashboard delete whether the folder is now empty, and it cannot know whether
someone is mid-write into it. Leaving it is untidy and safe, and the untidiness is
SELF-CORRECTING — the next dashboard created there lands in the folder both sides
already agree on, instead of minting a second one beside it.

**The GESTURE decides, which is what keeps this from contradicting the delete
rules.** An explicit folder delete in Nextcloud is unambiguous intent and
propagates to Grafana whatever the folder held; a folder that merely became empty
was never deleted by anyone. Read together:

| what happened | the Grafana folder |
|---|---|
| its last dashboard left | stays |
| the Nextcloud folder was trashed | deleted, whatever else it held |
| someone deleted it in Grafana | gone, and the mirror keeps any non-dashboard files |

This is the asymmetry `folders/delete.feature` spells out. It is deliberate: a
Nextcloud folder delete acts on the thing that IS the folder, so it carries; a
Grafana folder delete must not destroy a user's unrelated files, so it only takes
the dashboards.

### A link cannot be deleted from Nextcloud

A `link` is a read-only pointer at a dashboard Grafana owns. Deleting the pointer
does not delete anything — it only makes the mapped folder disagree with the
Grafana folder it mirrors, and the next pull writes the file straight back. So the
gesture is refused rather than half-honoured.

This is the same rule that blocks a copy from crossing into or out of a link
folder, and the same one the sibling n8n app settled on: a link is Grafana's copy
to remove.

### A link folder cannot be trashed either

The same refusal, one level up. Under a link mapping **the tree is Grafana's** —
the dashboards, the folders, and the shape of both — and Nextcloud is a read-only
mirror of it, with the one addition that other file types may be created alongside.
If a link says a folder exists, then it exists, and the mirror has no standing to
remove it.

`folders/delete.feature` used to permit this and merely decline to propagate it:
trashing a link folder was allowed, and Grafana was left alone. That is the
half-honoured shape the single-file rule above already rejects — the folder leaves
the mirror, the next pull writes it straight back, and in between the two sides
disagree for no reason anyone chose.

**Which is why `folders/purge.feature` has no link scenario.** A purge can only act
on something that reached the trash, and a link folder cannot get there. Writing the
scenario anyway would specify an end state for a state the app must never be in — and
an earlier cut of that file did exactly that, describing what a purge "would" do to a
link folder as though the trash step in front of it were permitted.

### A dashboard deleted in Grafana loses its mirror in Nextcloud

MOVED HERE FROM `reconcile.feature`, where it was phrased "Sync from Grafana
prunes a file whose dashboard left the folder" — named after the mechanism that
carries the news, with the actual event (someone deleted a dashboard in Grafana)
buried in a `When`. A delete that starts in Grafana is a delete, and deletes live
here.

The old notes argued it should stay in the sync file, because "this app has no
delete-dashboard reconcile path, so a dashboard vanishing from a Grafana folder
is only ever noticed BY a sync". That is true and it is not the point: being the
only mechanism that notices a change does not make the mechanism the subject.
This file already had an "everything above starts in Nextcloud; these start in
Grafana" section for exactly this shape of scenario.

THE SECOND `Then` IS THE POINT AS MUCH AS THE FIRST. A prune that took the whole
folder with it would satisfy "no file named Ephemeral remains" on its own, and
that is precisely the failure worth guarding against: only the dashboard that
went is the file that goes.

### Deleting a dashboard in Grafana leaves an already-trashed file where it is

This one the app gets RIGHT — but for a weak reason, which is why it is worth
pinning. Nothing DECIDES to leave the file alone: the pull simply cannot see
into the trash. A trash-aware reconcile must keep this behaviour deliberately
rather than lose it, because Nextcloud's trash is the user's undo history and a
Grafana-side delete is not permission to empty it.

### A trash-bypassed delete leaves the dashboard parked forever (bin on)

…and under bin ON it is a LEAK, which is the point of writing both down together.
The soft step parks the dashboard in the bin folder expecting a later purge to
finish the job, but no trash entry was ever created, so no purge can ever fire.
The dashboard sits in the bin forever with no file anywhere naming it.

@unbuilt, not @todo: nothing in the code notices. The fix has to be a decision
first — either the soft step detects the bypass and does a true delete (losing
the id preservation the admin opted into, with no undo), or bin mode declines to
park when there will be no trash entry. Not a bug to fix quietly.

### The Grafana delete is aborted if Grafana is unreachable

@blocked, not @todo: the code exists (the exception propagates and aborts the NC
delete), but this harness has no way to make Grafana unreachable for the
duration of one request — that is the missing capability, and naming it is what
keeps this out of the @todo work queue. The unit suite covers the rule against a
mocked GrafanaClient (testSoftDeleteBinOffFailedDeleteNeverStrips).

### Trashing a sync file deletes it in Grafana and strips ALL its metadata (bin off)

The content is safe in the file (now in the NC trash), so the dashboard goes
immediately and the file's uid is stripped — the id is dead and must not be
reused. Restore therefore cannot "put it back"; it re-creates.

### A failed Grafana delete never strips the file's identity (bin off)

The safety rule that makes bin-off survivable: the dashboard is deleted FIRST and
the uid is stripped only on success. Strip-then-delete would, on a failed call,
leave a live dashboard nothing in Nextcloud can still name — unreachable and
invisible to every future reconcile. Unit-tested (failed-delete-never-strips).

### Trashing a sync file parks its dashboard in the bin, keeping the id (bin on)

The dashboard is parked in the designated folder with its uid intact, so a
restore is a move back rather than a re-creation — id and version history
survive the whole round trip.

That the file KEEPS its uid is asserted in restore-dashboard.feature, where it
is observable: the parked dashboard comes back with the same id. See the bin-off
scenario above for why it cannot be read off the trashed file directly.

### Purging a parked dashboard that has already been deleted in Grafana just clears the file

…and the same rule from the other direction: if the dashboard is simply GONE — a
Grafana admin deleted it out of the bin by hand — the purge is a local matter. No
error, nothing to chase; the Nextcloud file just goes.

### Bin mode with an unusable bin folder aborts the delete rather than deleting

Bin mode is a promise the admin opted into, so an unusable bin must FAIL LOUD
rather than fall back. Silently doing a true delete because the folder was
renamed in Grafana would destroy exactly the id preservation they asked for —
and Grafana has no undo. RecycleBin::activeFolderUid throws; the delete aborts.

### A purge never clears the bin folder wholesale

The shim folder is shared space. Nothing in this app may ever treat "empty the
Nextcloud trash" as "empty the bin folder" — it holds dashboards we never managed,
and dashboards belonging to other users' trashes.

### A trash-bypassed delete still deletes the dashboard (bin off)

With the trashbin app disabled (or an `X-NC-Skip-Trashbin` header) only the soft
step ever fires — there is no trash for a purge to empty. Under bin OFF that is
harmless: the soft step already IS the true delete, so the outcome is correct.

## folders/delete

`features/folders/delete.feature`

Deleting a FOLDER — the folder half of delete-dashboard.feature, and the highest
blast radius in the app.

── WHY THIS IS NOT JUST "DELETE, N TIMES" ───────────────────────────────────────

Deleting one dashboard file is a decision the user makes about one dashboard.
Deleting a folder is one gesture that reaches every dashboard inside it — and
under the DEFAULT setting (recycle bin OFF), every one of those is a **permanent
Grafana delete**, because Grafana has no undo. A user dragging a folder to the
trash is unlikely to have priced that in.

The Nextcloud side stays honest either way: the files land in the trash with their
JSON intact, so nothing is *lost*. What is gone is the dashboards' uids and their
Grafana version history, for as many dashboards as the folder held.

  | folder holds N sync files | bin OFF                    | bin ON                     |
  |---------------------------|----------------------------|----------------------------|
  | trash the folder          | N permanent Grafana deletes | N dashboards parked        |
  | restore the folder        | N NEW uids (create-on-land) | N restored, uids preserved |
  | empty the trash           | nothing left to do          | N permanent deletes        |

── CORRECTED: NEXTCLOUD DOES NOT DECOMPOSE A FOLDER GESTURE ─────────────────────

This section used to say that Nextcloud fires `BeforeNodeDeletedEvent` per node, so
a folder delete arrived as N file deletes and "mostly worked". **That was wrong**,
and every conclusion drawn from it was wrong with it. Verified against the running
instance:

- `View::unlink()` on a directory runs ONE `basicOperation('rmdir', …, ['delete'])`.
  One event fires, for the folder. The storage layer removes everything inside it
  with no hook at all.
- `Trashbin::delete()` emits one legacy `preDelete` for the trashed entry, then
  `$node->delete()` takes the whole subtree silently. `deleteAll()` emits one per
  TOP-LEVEL entry and none deeper.
- `Trashbin::restore()` dispatches one `NodeRestoredEvent`, for the restored node.

All three of the app's per-file handlers require a `File`, so all three did nothing
at all for a folder. Trashing a folder of dashboards left every one of them live in
Grafana; emptying the trash left every parked one there forever.

The subtree walk Nextcloud does not do is now `FolderCascade`, driven by
`FolderDeleteListener`, `TrashPurgeHook` and `RestoreFromTrashListener`.

── THE ORDERING QUESTION, WHICH IS REAL BUT SMALLER THAN IT LOOKED ──────────────

With the gesture handled as one event the partial-failure story is mostly gone: the
bin-OFF case is a SINGLE `DELETE /api/folders` that cascades, so it either happens
or it does not. The bin-ON case is still N upserts followed by one folder delete,
and a failure partway leaves some dashboards parked and some not — but the folder
delete is last, so nothing is destroyed by a half-finished park.

### RETIRED — warning that forty dashboards are about to be deleted

`Scenario: Trash a folder of many dashboards` is gone, and so is the "one gesture,
many dashboards — say so before and after" rule it was the only member of.

**The trashbin already is the confirmation, and the purge is the second one.**
Deleting in Nextcloud is always safe: it goes to the trash, whatever it held. The
irreversible step is emptying the trash, and by then the user has performed two
separate destructive gestures on the same thing — that is already "are you really,
absolutely sure", expressed as a workflow rather than a dialog. Bolting a count
warning onto the first, safest step adds friction where nothing is at stake.

It is also **not Nextcloud behaviour**. Nextcloud does not warn you for deleting a
folder of forty of anything, and an app inventing a confirmation the platform does
not have makes this app feel unlike the rest of the Files experience.

The only thing that would bring it back: Nextcloud growing such a rule itself, at
which point the app would follow the platform rather than lead it.

`FolderCascade::countDashboardsIn()` existed solely for this and was removed with
it. Counting the subtree is one line if it is ever wanted again.

── STATUS ───────────────────────────────────────────────────────────────────────

The Nextcloud-side gesture is built and unit-tested: trash (both bin modes), the
link refusal, purge-from-trash, and restore. `@unbuilt` is now only the two
`@in-grafana` scenarios, which belong to the pull.

### Trashing the mapped folder itself deletes its dashboards but keeps the mapping

── the mapped folder itself ─────────────────────────────────────────────────────
Deleting the folder a mapping points at is the widest gesture available to a
non-admin. The mapping survives — it is configuration, not a file — so the next
pull re-creates the folder and everything in it. Whether the dashboards should
have been deleted in the meantime is the whole question.

### A pull after the mapped folder was trashed re-creates it empty

The consequence, stated separately because it is the part that surprises people:
the mapping outlives the folder, so a pull rebuilds an empty folder rather than
restoring what was there.

## dashboards/view

`features/dashboards/view.feature`

LOOKING AT A DASHBOARD FILE — the only part of "it is a real file type" that
anyone actually performs.

### view-dashboard

**This replaced `file-type.feature`, which described a CONSTRUCT.** "Grafana
dashboard is a first-class file type" was about a mimetype, a property set and an
index — none of which anyone does. Each turned out to be the end state of
something else:

| it described | whose end state it is | where it went |
|---|---|---|
| the mimetype is registered | **enabling the app** | `lifecycle.feature` |
| a file carries this metadata | **creating** or **syncing** a dashboard | asserted by those, shown here |
| the mode property's wire value | what the metadata says | the DAV view outline |
| the metadata cannot be edited | a refusal anyone can provoke | stayed, as a scenario |

Nobody registers a mimetype; they install an app. Nobody sets metadata; they make
a dashboard and the app stamps it. Once each end state sits with the behaviour
that produces it, what remains is looking — and that is a real thing to do.

Ported from `kubed-io/nextcloud-n8n`, where the split landed first.

### A mapped folder shows its dashboards as dashboards

**ONE SCENARIO, DELIBERATELY.** Behat cannot read rendered pixels, so the icon is
proven the only way it can be: the file carries the app's own mimetype rather
than `application/json`, and Nextcloud maps that mimetype to the app's glyph.
Elaborating past that would be testing Nextcloud's icon renderer, which is not
this app's to prove.

This is the app's only genuinely UI-only surface, which is why it is one small
scenario rather than a file.

### Viewing the DAV properties on a file shows Grafana specific details

**This is the one scenario that spells the properties out**, and everywhere else
the same fact is one sentence — `the file carries its Grafana metadata`. The two
are not in tension, they are the difference between a subject and an end state.
Sync, create and rename all *produce* a mirror; which keys that mirror carries is
the app's business, and listing them there would make every one of those
scenarios look like a metadata test. Here the properties genuinely are what is
under test, so the table is the specification.

THE KEYS ARE THE FIVE `stampSynced` WRITES when a file lands: `grafana_uid`,
`grafana_mapping`, `grafana_mode`, `grafana_version`, `grafana_syncedHash`. The
metadata store registers two more — `grafana_folderUid` (the source Grafana
folder, for the nested-folder cascade) and `grafana_apiVersion` (classic JSON vs
v2 YAML, so a file self-describes how to be read back) — but **nothing writes
either one yet**; they are banked for the subfolder and YAML courses. The old
`file-type.feature` listed both in its PROPFIND table, which asserted values the
app has never set.

Isolation is free, and worth knowing while reading the table: NC Files-Metadata
keys are a flat string-keyed namespace, so a `grafana_*` key can never surface in
an `n8n_*` query and vice-versa. (The shared-module neutral-key question is
deferred — saga Ch2 Round 2, fork B.)

The value column takes three forms and no more, because a table that can say
anything stops being readable: `set` (present and non-empty), `the dashboard's
uid` / `the mapping's id` (resolved against what the arrange recorded), or an
exact literal. The two id forms exist because presence is too weak for them — a
uid that is merely non-empty could be any dashboard's, and the whole point of
publishing it is that it names *this* one. A Grafana version int and a body hash
are the sync engine's own bookkeeping; pinning literals there would assert its
internals instead of the fact under test.

`link` stores as `reference`. The literal string `link` is `is_callable()`, which
crashes core's PROPFIND — the only place in this app where a wire value differs
from the name of the thing it carries, so it is an Examples column rather than a
footnote, and the row shows both what the admin chose and what a client reads.

**The table says nothing about storage or format.** Naming a field is a claim
that it matters, and what a mirror publishes over DAV is identical on an
admin-owned folder and a Team Folder. So the mapping takes the app's own
defaults, which is the one shape that exists on every install. `storage` and
`format` are named where provisioning IS the behaviour, in
`admin-mapping.feature`, and a scenario that wants a Team Folder or the YAML cut
asks for one there.

**The outline lost two rows** (`unmapped`, `ignored`) when it was reshaped around
a mapping. That is deliberate and not a coverage regression: a mapping only ever
produces `sync` or `link`. The other two are what a file *becomes* — moved out of
its folder, or hand-tagged `grafana:ignore` — so they cannot be reached from a
mapping form at all, and their modes are asserted where those behaviours live, in
`move-dashboard.feature` and `reserved-tags.feature`.

### What the app manages, only the app changes

A REFUSAL SOMEONE CAN PROVOKE, so it earns a scenario: any DAV client can attempt
a PROPPATCH. The identity of a mirror is the app's to write — a client that could
edit `grafana_uid` could silently re-point a file at a different dashboard.

The load-bearing assertion is that the VALUE did not change, not that a
particular status came back.

### Finding dashboards by their mode

`@blocked`, and the missing capability is named: there is no proven DAV REPORT
search over `nc:metadata-*` in this harness to drive it against. The index itself
is real — `grafana_mode` is registered as an indexed metadata key precisely so
"find every unmapped file" is a query rather than a folder walk — but nothing
here can issue that query. Confirm the search surface exists and this becomes an
ordinary `@todo`.

## lifecycle

`features/lifecycle.feature`

Stage 0 (saga §5): the app installs and uninstalls cleanly on a real Nextcloud.
A clean uninstall is also an app-store rule. No Grafana contact.

### Enabling the app

**THE MIMETYPE IS WHAT ENABLING LEFT BEHIND.** It used to head a file called
"Grafana dashboard is a first-class file type", which described the registration
as though someone had gone and done it. Nobody registers a mimetype; they install
an app, and the registration is the consequence — so it is asserted here, on the
install.

Proven by uploading a plain file rather than by reading the app's own metadata: a
file this app has never touched, with nothing but the extension going for it,
comes back typed as the app's own mimetype. That is what registration means and
the only part of it a client can observe.

Its visible consequence (a mapped folder that looks like dashboards) belongs to
`view-dashboard.feature`; its removal belongs to `uninstall.feature`.


### Removing the app

FOLDED IN FROM `uninstall.feature`, which is retired. Enabling, disabling and
removing are three points on one lifecycle; they were two files because the
removal had grown an essay.

`@blocked` — **no app removal**: `occ` enables and disables, and removing an app
and reinstalling it is a store operation this suite cannot perform. What it
asserts is our work, not the framework's — `UnregisterMimetype` reverts what the
install wrote into the Nextcloud core tree. The second `Then` is the data-orphan
promise stated once, at the only moment anyone would doubt it.

Two scenarios did NOT come with it. "Disabling the app leaves the files in place"
asserted Nextcloud's behaviour rather than this app's — nothing runs on disable,
so there is no code to write and none to break. "Re-enabling and syncing
reconciles without duplicates" is `connection/sync-now.feature`'s id-matching
guarantee; a disable/enable changes nothing about how a sync matches on uid.

## mapping-membership

`features/mapping-membership.feature`

Folder mappings are metadata on the folder, so membership is resolved by where a
file lives. (How the app reacts when you MOVE a file across that boundary is in
move-dashboard.feature — a sync file moved out becomes "unmapped"; a link can't leave.)

The resolver matches the deepest mapped folder that encloses a file, so nested
mappings work and the nearest enclosing one wins. Each scenario lands a real file
over WebDAV and reads the resulting grafana_mapping stamp back, so these are
server-observable assertions of the mapping resolver.

@todo — the mapping engine lands with the sync chapter; executable spec only.

## dashboards/move

`features/dashboards/move.feature`

How the app reacts to every move a Nextcloud user can make on a dashboard file.
The stable thread is the dashboard UID **plus the full JSON we hold in the file**.
(COPY is the opposite — always a new instance; see copy-dashboard.feature.)

THE CORE NUANCE (resolved with Dr K) — MOVE and DELETE are NOT the same, and where
a file moves decides everything. Grafana has NO soft-delete/archive (proven live: a
DELETE is permanent), and — unlike n8n — we CANNOT keep an id "parked" for free,
because there is nothing to un-archive. So the id-preservation story depends on where
the file lands and on ONE optional setting:

  1. MOVE WITHIN THE SAME MAPPING (rename) — nothing in Grafana but the name.

  2. MOVE FROM ONE MAPPED FOLDER TO ANOTHER — a genuine Grafana **folder move**: the
     dashboard's folderUid updates to the destination mapping's folder, the **UID is
     always preserved** (both sides are real Grafana folders — no delete involved),
     and the file re-stamps grafana_mapping + grafana_folderUid. Independent of the
     recycle-bin setting below.

  3. MOVE OUT OF EVERY MAPPED FOLDER (to an unmapped location) — this is where the two
     strategies diverge, selected by the optional **Grafana recycle-bin folder**:
       • BIN OFF (default, aggressive): the content is already safe in the Nextcloud
         file, so we **DELETE the dashboard in Grafana** and **strip the file's Grafana
         identity** (uid/mapping/folderUid/version/hash). The file becomes a plain,
         untracked .grafana that still holds the full JSON. Moving it back into a
         mapping is then just **create-on-land** — a brand-new dashboard, same content,
         a **NEW uid** (the old one is gone forever). "It just works", id not preserved.
       • BIN ON: we **MOVE the Grafana dashboard into the designated recycle-bin folder**
         (uid **preserved**) — the analogue of n8n's archive, done with a real folder.
         Moving the file back into a mapping **moves the dashboard back** out of the bin
         to the destination folder, **same uid**. (See delete-dashboard.feature — trash uses the
         exact same bin machinery.)

THE ID-STRIP RULE (precise): we strip the file's grafana_uid **only when we are about
to do a TRUE delete in Grafana while the file survives in Nextcloud** — because the
dashboard is gone, so its uid is dead. With the recycle-bin folder ON, a "delete/move-out"
is a **move into the bin, not a true delete**, so the dashboard still exists and the file
**keeps its uid** (it becomes an `unmapped` file that can restore to the SAME dashboard —
the n8n-style parked state, which Grafana can only offer via the bin).

THE SAFETY RULE (never lose data): a sync file always holds the full dashboard JSON, so
the content is safe in Nextcloud BEFORE we touch Grafana. We only ever DELETE from
Grafana once we have confirmed the file holds what it needs to rebuild.

STATUS: the move engine IS cooked (Course 4 · Slice 2b) — MotionService + MoveGuardListener,
unit-tested for the invariants (uid kept on a re-parent, a failed delete never strips, a link
move-out refused) and verified live on the pod (create → re-parent uid-kept → move-out delete
→ move-back new-uid, all confirmed against Grafana's API).
  SCOPE — same-storage moves only: Nextcloud fires NodeRenamedEvent for a move within one
  storage (regular folder ↔ regular folder, rename, subfolder). A move into/out of a TEAM
  FOLDER crosses a storage boundary and is a copy+delete under the hood (NodeDeletedEvent, not
  NodeRenamedEvent), so team-folder re-homing rides the delete/create lifecycle — a fast-follow
  — NOT this engine. The bin-ON parking rows here also stay design-only (fast-follow).
Scenarios are tagged per-scenario, not per-file: @todo where the engine is built and only the
occ+WebDAV move steps are missing (the unit suite + the live smoke carry the proof meanwhile),
@unbuilt where there is genuinely no code. A @todo above `Feature:` used to hide that split.

MOVE AND DELETE ARE THE SAME GRAFANA OPERATION, chosen by the same setting — moving a file out
of every mapping and trashing it both run through the bin decision. They live in separate files
because the gestures differ, so whenever either changes, check the rules still agree.
Every scenario whose outcome depends on that setting carries @recycle-bin.

### Moving a dashboard into a tagged subfolder re-parents it in Grafana

── into a TAGGED subfolder — a real Grafana folder ───────────────────────────────
A subfolder that carries the `grafana` tag is a Grafana folder in its own right
(create-folder.feature owns how it gets there). Moving a dashboard into one is
therefore a re-parent, not local organisation. @unbuilt: GrafanaClient has no folder
write operations, so there is nothing to re-parent into yet.

### Moving into an untagged subfolder is local-only (stays bound to the parent)

An UNTAGGED subfolder is ordinary local NC organisation, invisible to Grafana — the
dashboard stays bound to the PARENT mapped folder and keeps all its metadata. A file
only leaves the mapping when it leaves every mapped folder. A subfolder becomes a
Grafana folder by carrying the `grafana` tag; see create-folder.feature.

### Moving a link from one mapped folder to another only re-homes the pointer

Unit-tested (testALinkMoveIntoADifferentMappingOnlyRehomesThePointer) and never
written down. A link owns no dashboard, so a mapped→mapped move re-stamps which
mapping the pointer belongs to and stops there — Grafana is not called at all.

### A failed Grafana delete on move-out never strips the file's identity

Both of these are unit-tested invariants that no scenario stated. They are the
rules that make "move out = delete" survivable, and Grafana has no undo, so they
are worth reading as specification rather than as implementation detail.

### A move to a destination the app cannot classify never deletes anything

A destination the app cannot classify — outside the user's file tree, or a path
it cannot resolve to "mapped" or "unmapped" — must NOT be read as "left every
mapping". Treating unknown as unmapped would turn an unreadable path into a
permanent Grafana delete. Unknown means do nothing.

### A dashboard moved to another mapped folder in Grafana relocates its mirror

Both folders are mapped, so the dashboard should end up in the other mapping's
Nextcloud folder. Today the pull prunes it from the source folder
(sync-now.feature covers that leg) and writes it fresh into the destination —
correct end state, reached as a delete-and-recreate rather than as a move.

### A pull never relocates a file the user filed into a subfolder

The rule that makes subfolders usable: a pull must never yank a file back to the
mapping root because that is where it would have created it. Where the user filed
it is theirs to decide. SyncService renames in place within the file's own folder;
this is that promise, stated as behaviour.

## folders/move

`features/folders/move.feature`

Moving a FOLDER — the folder half of move-dashboard.feature.

── THE RULE THAT MAKES THIS DANGEROUS ───────────────────────────────────────────

Mapping membership is decided by PATH (`MappingService::resolveForPath` uses
`str_starts_with`), so moving a folder changes the mapping of every dashboard
inside it — in one gesture, without touching any of those files individually.

And "left every mapping" is the branch that DELETES in Grafana (see
move-dashboard.feature §3). So dragging a subfolder out of a mapped folder is,
under the default setting, a permanent multi-dashboard delete. The gesture looks
like tidying; the consequence is the same as emptying the trash on all of them.

  | move a subfolder of "alpha" holding N sync files | outcome                    |
  |--------------------------------------------------|----------------------------|
  | …to elsewhere inside "alpha"                      | nothing (same mapping)     |
  | …into the "beta" mapped folder                    | N Grafana folder moves     |
  | …out of every mapping, bin OFF                    | N PERMANENT deletes        |
  | …out of every mapping, bin ON                     | N parked in the bin        |
  | …in from outside, into a mapping                  | N creates (new uids)       |

── STATUS ───────────────────────────────────────────────────────────────────────

Nextcloud fires `NodeRenamedEvent` for the FOLDER, not for each file inside it, so
`MotionListener` sees one event about a node that is not a File and does nothing.
That means **none of the per-file consequences above actually fire today**: moving
a folder full of dashboards silently changes their mapping membership without
telling Grafana anything, and the two sides drift until the next pull.

So the delete rows in that table are not "what happens", they are "what the
per-file rules imply should happen". Everything here is @unbuilt: there is no
folder-move handling, and `GrafanaClient` has no folder write operations to call.

Whether the right answer is "apply the per-file rule to each file", "refuse the
move", or "re-home without deleting" is a DECISION, not an implementation detail —
and the middle rows below are deliberately written as alternatives so it can be
made once, in writing, rather than discovered.

## dashboards/open-with

`features/dashboards/open-with.feature`

"Open with" — the openers offered for a managed dashboard file, and which one is
the default click. RELATED to the file type (file-type.feature: it's *because*
`.grafana` is a first-class type that we get custom openers) but a distinct
concern, because the opener set + default depend on the file's MODE, not its type.

Two openers:
  - "Open in Grafana"          — jumps to the live dashboard in Grafana. Only meaningful for
                             sync/link; hidden for unmapped/ignored (nothing to open).
  - "Open with text editor" — edits the raw JSON. ALWAYS available on any dashboard
                             file; it's the default for unmapped/ignored.
Default click: sync/link → Open in Grafana; unmapped/ignored → text editor.
(Whether editing+saving pushes to Grafana follows the file's mode — see
create-dashboard.feature / rename-dashboard.feature / the bidirectional sync, not here.)

STATUS: the openers ARE cooked (Course 5) — src/files.js registers "Open in Grafana" +
"Open with text editor" (+ the "Grafana dashboard" New-menu item), loaded by
LoadFilesScriptListener. Behat can't click the Files-app JS, so the opener DECISION logic
is unit-tested in tests/js/files-helpers.test.js (30 cases); the integration steps here
assert the server-observable state the front-end keys off (the grafana_mode DAV value + the
live dashboard + raw-JSON readability). The whole feature stays @todo — CI skips it — until
those occ+WebDAV step definitions are written; the JS unit suite carries the decision proof.
── STATUS: @blocked THROUGHOUT, AND THE CAPABILITY IS NAMED ─────────────────────

Every scenario here describes what the Files-app context menu SHOWS — which entry
is offered, which is hidden, what the default click does. That is browser
behaviour, and this harness has **no browser**: it drives Nextcloud over WebDAV
and occ, neither of which renders a menu. No step could assert any of it.

@blocked, not @todo: the code exists and ships (the openers are registered in the
Files UI), and the JS unit suite covers the entry-visibility logic
(tests/js/files-helpers.test.js). What is missing is a capability in the harness,
not a test someone forgot — which is exactly the distinction that keeps these out
of the @todo work queue.

## dashboards/purge

`features/dashboards/purge.feature`

PURGE MEANS EMPTYING THE NEXTCLOUD TRASH, and nothing else. It is the second half
of a delete the user already started: the trash gesture archived or parked the
dashboard, and this is the moment they say they meant it.

THIS SECTION USED TO DESCRIBE A DIFFERENT FEATURE ENTIRELY — an admin button (and
an `occ grafana_sync:purge` command) that swept every managed file across every
mapping. That feature is retired and was never built; see *RETIRED — the admin
purge* above for why the promise it made could not hold. The description outlived
the retirement and sat here pointing at a `Command\Purge` class that does not
exist, which mattered because this heading is the anchor `purge.feature` itself
links to: every reader of the real feature was being handed the wrong one.

The scenarios turn on ONE question — where the dashboard is when the file is
purged — because that is what decides whether Grafana is touched at all:

  - **bin off** — the dashboard was hard-deleted when the file was trashed, so
    emptying the trash has nothing left to do in Grafana and must not invent work.
  - **bin on** — the dashboard is parked in the recycle-bin folder, and the purge
    is what finally deletes it. The file leaving the trash is the signal.
  - **not in the bin** — somebody moved it back, or it never arrived. The purge
    leaves it alone: a dashboard sitting in a live folder is not ours to delete
    just because a stale mirror was thrown away.
  - **one file while others are parked** — the purge is scoped to the file being
    purged, so parked siblings survive. This is the scenario that catches a
    delete written against the bin FOLDER rather than the one dashboard.
  - **Grafana unreachable** — the Nextcloud side still completes. The trash is
    the user's, and stranding a file in it because a remote system is down would
    make the app's failure the user's problem.

Both trashes are exercised for the same reason as every other delete leg: the
home trash and a Team Folder's trash emit different signals, and only one of them
is a typed event (see `TrashPurgeHook`).

## connection/sync-now

`features/connection/sync-now.feature`

THE FIRST SYNC, AND ONLY THAT.

### sync-now scope

**There is no `reconcile.feature`, and there must never be one.** Reconciling is
a MECHANISM — the thing that carries a Grafana-side change into Nextcloud — and a
mechanism does not get a feature file. What a person does gets a feature file.

This file replaced one called "Manual per-mapping sync (Sync from / Sync to
Grafana)", which was named after two buttons. Its six scenarios turned out to be
four different behaviours wearing one coat, and most of them belonged somewhere
else:

| it said | it meant | where it went |
|---|---|---|
| the button pulls the folder's dashboards in | the FIRST sync | here |
| a second sync updates in place | a sync over a folder that already holds mirrors | here, renamed |
| …and prunes a file whose dashboard left the folder | a dashboard deleted **in Grafana** | `delete-dashboard.feature` |
| a run that changed nothing rewrote nothing | an mtime, and the reconciler | deleted — see below |
| the button pushes a local edit up | **editing** a dashboard file | `edit-dashboard.feature` |
| a root mapping mirrors the whole instance | the first sync of a root mapping | here |

**Why the first sync is genuinely its own thing.** Nothing is tracked yet, so
whatever sits in Grafana is simply a Given. Every LATER run only has work because
something changed upstream — and each of those is a scenario about the change:
renamed in Grafana is `rename-dashboard.feature`, deleted in Grafana is
`delete-dashboard.feature`, moved to another folder in Grafana is
`move-dashboard.feature`. The sync is how the news arrives, not what happened.

**This reverses two decisions recorded in this file.** The old notes argued the
prune should stay ("this app has no delete-dashboard reconcile path, so a
dashboard vanishing is only ever noticed BY a sync") and that the unchanged-run
scenario should stay ("a regression guard for a defect that was actually fixed").
Both arguments are about the reconciler, which is what gave them away: being the
only mechanism that notices a change does not make the mechanism the subject.
`delete-dashboard.feature` already had an "in Grafana" section waiting for the
first one.

**The trigger is data.** Three ways to start one sync — the card's button, the
section's button, the schedule — same pre-state, same post-state. Columns, not
scenarios. Whether a run is synchronous or queued is a mechanism and is asserted
nowhere; a scenario that pinned it would break the moment the dispatch changed,
without any user-visible behaviour having changed at all.

**The schedule row is why this app has a scheduled pull at all.** Writing the
table forced the question "what are the ways a sync starts?", and the honest
answer was that `schedule_enabled` and `schedule_interval` had been in the Sync
Settings card since it was written with **no reader anywhere in the app**. An
admin could switch the schedule on, save it, watch it persist across reloads —
and nothing would ever happen.

The step drives the REAL job: it enables the setting, finds the registered
`ScheduledPullJob` by class, and runs it with `background-job:execute
--force-execute`. Two safety floors stand between a test and a timed job — the
job's own 60s minimum interval and the worker's last-run gate — and neither can
be waited out in CI, so forcing it is the only honest option. Asserting that a
row exists in `oc_jobs` would prove the job is *registered* and nothing about
whether it *works*, which is exactly the gap that let the settings sit unread.

A distinct Nextcloud folder per row, deliberately: all three map the same Grafana
folder and a mapping is unique on the Grafana uid, so each row clears the store
anyway — distinct folders stop one row's leftovers reading as the next row's
result.

**"A run that changed nothing rewrote nothing and says so" was deleted, not
moved.** It asserted an mtime — a result — about the reconciler — a mechanism —
and neither gets a scenario. Same call the n8n sibling made. The defect it once
guarded is real and worth remembering: a pull that rewrote every mirror on every
run left the whole folder reading "Modified a few seconds ago", so a file you had
really touched was impossible to spot. That is recorded in the CHANGELOG, and the
step definitions are kept with their docblocks, so re-adding it is one line if it
ever earns a home. The behaviours that DO rewrite a mirror assert their own end
states, which is where the guarantee belongs.

### carries its Grafana dates

AN END STATE, NOT A FEATURE OF ITS OWN. A mirror wears the dashboard's clocks
rather than the sync's, and that is true however the sync started. Written as two
`Then`s it read like two behaviours; as one reusable sentence it is a single fact
that any later behaviour producing a mirror can assert the same way.

### A folder that already holds a mirror is filled in place, not doubled

This was "A second sync updates in place, never duplicating" — named after
running the reconciler twice, which is the mechanism again. What makes it worth a
scenario is the *situation*, not the repetition: a first sync over a tree that
already has files in it. A restored backup, a re-mapped folder, a re-enabled app.

The uid is what identifies a dashboard, not the filename, so the sync fills the
existing file rather than leaving an `Alpha Demo (2).grafana` beside it. Kept
out of the outline above because it asks a different question, and folding it in
would prove the same thing three times, once per actor, for no extra information.

### A sync fills the mapped folder, however it was started

actor        | scope
-------------+---------------------
the admin    | one mapping        the card's "Sync now"
the admin    | every mapping      the section's "Sync from Grafana"
the schedule | every mapping      time as the actor

Same pre-state, same post-state. The actor and the scope are the only things
that differ, so they are COLUMNS rather than three scenarios. Whether a run is
synchronous or queued is a mechanism, and is asserted nowhere.

THIS FILE IS THE FIRST SYNC, AND ONLY THAT. Nothing is tracked yet, so whatever
is in Grafana is simply a Given. A LATER run only has work to do because
something changed in Grafana — and every one of those is a scenario about the
change, not about the sync: a dashboard renamed upstream belongs to
rename-dashboard.feature, one deleted upstream to delete-dashboard.feature, one
moved to another folder upstream to move-dashboard.feature. The sync is how
those arrive, not what they are.

A FOLDER PER ROW, on purpose. All three map the same Grafana folder, and a
mapping is unique on the Grafana uid, so each row clears the store first
anyway — distinct Nextcloud folders keep one row's leftovers from being read
as the next row's result.

THE DATES ARE AN END STATE, not a feature of their own: a mirror carries the
dashboard's clocks rather than the sync's, and that is true however the sync
started. So it is one reusable sentence rather than two `Then`s spelled out
here, and any later behaviour that produces a mirror can assert it the same
way.

### A root mapping mirrors the whole instance

The Grafana root "/" mapped to a Nextcloud folder. The root encloses every folder,
so the sync walks the entire Grafana folder tree — a one-to-one mirror. Lands with
the subfolder course.

**There is no longer a toggle in this sentence.** It used to read "with Sync
subfolders on", naming a per-mapping flag that was stored, validated, and read by
no code path. Subfolders are not opt-in: a folder is in Grafana exactly when a
dashboard lives beneath it. So a root mapping mirroring everything is not a mode
someone selects, it is what mapping the root MEANS.

**Why the flag could not earn its place: the mapping already is the switch.** The
only thing "subfolders off" could express is *mirror this folder but nothing under
it* — and anyone who wants a flat mirror can already have one by mapping a LEAF in
Grafana to a leaf in Nextcloud. The depth of the mirror is chosen by which folder you
map, which is a decision the admin makes anyway and can see. A second control that
re-decides it from the other end is a way for the two to disagree, and it costs a
branch in every folder path in the app for a case the mapping already covers.

## dashboards/edit

`features/dashboards/edit.feature`

EDITING IS THE BEHAVIOUR; THE PUSH IS HOW IT TRAVELS.

### A local edit reaches its dashboard in Grafana

This was "Sync to Grafana pushes a local edit up to its dashboard", a scenario in
a file named after two buttons. Nobody edits a dashboard in order to press a
button — they edit it so Grafana gets the change, and the app offers three ways
for that to happen (on save, on the button, on the schedule). Those are
mechanisms; this is what they are for.

The uid is the thread, so the edit lands on the dashboard the file already names
— same dashboard, same folder, never a duplicate.

The bravo folder rather than alpha, so an edit here never mutates the fixture
`sync-now.feature` asserts an untouched mirror against.



### An edit in Grafana reaches the mirrored file

THE OTHER HALF OF EDITING, and it had no home. It sat in `tag-sync.feature` as "A
change in Grafana pulls the new body and reconciles the pills" — filed under tags
because the tags were the interesting part there, and named after the pull.

The behaviour is that someone edited a dashboard. The body arriving and the pills
matching are both end states of that, which is why they are `Then`s here rather
than a scenario in a file about tags.

### Removing a mapping removes only the mapping

**Nothing is trashed, nothing is deleted, nothing is sent to Grafana.** Removing a
mapping deletes one row of configuration; the files stay exactly where they are and
become unmapped, and every dashboard in Grafana is untouched.

The feature used to describe the opposite — the connected files were trashed, their
dashboards deleted or parked depending on the recycle-bin setting, and re-mapping
plus restoring from the trash was how you got back. Six scenarios, most of them
about undoing the damage the first one did.

That was a lot of destruction in service of a gesture that means "stop syncing
these". An unmapped file is already a well-defined thing everywhere else in this
app: it keeps its `grafana_uid`, loses its `grafana_mapping`, and reads as
`unmapped`. Removing a mapping simply produces that state for everything under the
folder, which is the smallest thing the gesture could possibly mean.

**Links are the one exception, and only because they hold nothing.** A link file is
a pointer at a dashboard Grafana owns; with no mapping it points nowhere and there
is no body to preserve, so it goes. Nothing is lost that a later re-map would not
rebuild on the next sync.

Re-connecting is therefore not this feature's problem. Adding a mapping over a
folder of unmapped files is `mapping/create.feature`'s business, and it adopts them
by uid like any other sync.

OPEN — whether a removed link file lands in the Nextcloud trash on its way out.
Nextcloud's own delete path would put it there; "as if the mapping was never there"
argues it should not clutter the trash with pointers. Not decided.

## mapping/rename

`features/mapping/rename.feature`

Renaming either side of a MAPPING. Split out of `folders/rename.feature`, which is
about subfolders: the two obey opposite rules, and having them in one file was what
made the subfolder scenarios look like they could carry a name Grafana did not
share.

### A mapping is a pair of ids, so a rename is a no-op

A mapping records a Grafana folder UID and the Nextcloud folder it pairs with.
Neither is a name, so renaming either side changes nothing that the mapping is built
on — there is no reconnect to perform and nothing to send to Grafana.

**This is the ONLY place two different names are legitimate.** `demo` in Grafana may
pair with `Dashboards` in Nextcloud, because the mapping is the thing that holds the
pair. A subfolder has nowhere to keep such a record, which is why it must share its
name exactly.

The safety of the whole gesture rests on the Nextcloud side being held by id too. If
the mapping stores a PATH, a rename orphans it and every dashboard under it silently
stops resolving — which is what the old `folders/rename.feature` recorded as a known
defect. Holding the folder id instead removes the defect rather than warning about it.

**BUILT.** `Mapping::ncFolderId` is the Nextcloud file id of the mapped folder and
`MappingService::resolveForPath()` looks the folder up by it, so the resolver follows
a rename or a move without anything having to observe either. The stored `ncFolder`
is now only a label, and it is caught up whenever the lookup finds it stale — so the
admin panel cannot keep showing a folder name that no longer exists.

Two things it deliberately does NOT do. It never falls back to the name once an id is
known: a folder that was deleted matches nothing, because a new folder reusing the old
name is a different folder and adopting it would point the mapping somewhere nobody
chose. And it never overwrites a real id with 0 — a backend that cannot answer leaves
the mapping to self-heal on the next resolve instead.

Old rows, and mappings saved before their folder was provisioned, carry id 0 and heal
on first use: the name is resolved once, the id is banked, and the name is never used
for lookup again.

## mapping/delete

`features/mapping/delete.feature`

Removing a folder mapping — the admin deletes a mapping from the list (or
`occ grafana_sync:remove-mapping <id>`). This is NOT the "Purge Nextcloud files"
button (that keeps the mapping + never touches Grafana — see purge.feature). Removing
a MAPPING tears down the connection, and the question is: what happens to the files
and dashboards that were connected through it?

THE CONTRACT (resolved with Dr K) — trash the connected, leave the rest, lose nothing:
  • Every file ACTIVELY CONNECTED to the mapping (a managed sync/link file whose
    grafana_mapping is this mapping) is moved to the **Nextcloud trash** — it becomes
    unmapped and goes to the bin. Because a trash move rides the delete contract
    (delete-dashboard.feature), the Grafana side follows automatically:
      - recycle-bin OFF → the connected dashboard is deleted in Grafana at trash-time
        and the file's metadata is stripped (restore re-creates with a new uid);
      - recycle-bin ON  → the connected dashboard is MOVED into the bin folder, uid
        kept (restore moves it back, same uid).
  • Files that are NOT connected are LEFT ALONE, untouched: an `unmapped`/`untracked`
    standalone `.grafana` only ever existed in Nextcloud, so removing a mapping
    it was never part of must never move or delete it — no data loss.
  • The Nextcloud trash is the safety net: we don't surgically decide what to keep —
    we trash exactly the connected files, and the trash is fully recoverable. Fully
    **emptying the trash** then does the permanent clean-up (recycle-bin ON → the
    matching dashboards are deleted from the Grafana bin; OFF → already gone).
  • RECONNECTION: if a new mapping to the same Grafana/Nextcloud folder is created
    later, the trashed files can simply be **restored (untrashed)** to reconnect —
    cleanest with the recycle bin ON (the dashboards were only parked, so restore
    re-links the SAME uids); with the bin OFF, a restore is a re-create (new uids).

STATUS: the tear-down cascade IS cooked (Course 4 · Slice 3) — MappingTeardownService trashes
the mapping's connected files (their delete rides the recycle-bin setting via the delete
listener) and leaves standalone files alone, wired to both `occ remove-mapping` and the admin
panel. The whole feature stays @todo — CI skips it — until the occ+WebDAV step definitions are
written; until then the delete-engine unit suite + the live smoke carry the proof.

### Re-mapping and restoring reconnects by re-creating the dashboards (bin off)

With the bin OFF the reconnection still works, but the dashboards are re-created
(their originals were permanently deleted at trash-time), so the restored files come
back under NEW uids — same content, new identity. Pinned for live-verify.

## dashboards/rename

`features/dashboards/rename.feature`

Three-way name agreement in sync mode: filename stem ⇄ JSON "title" ⇄ Grafana title.

── THE THREE SURFACES, AND WHY THERE ARE THREE ──────────────────────────────────

  1. the FILENAME       — what the user sees and types in the Files app
  2. the JSON `title`   — what is inside the file, and what a push sends
  3. the Grafana title  — what the dashboard is called on the far side

Any one of them can be changed first, and the other two must follow. Two of the
three are in Nextcloud, which is why a rename is not simply "push the new name":
editing the JSON has to rename the FILE too, and renaming the file has to rewrite
the JSON, before either reaches Grafana.

── THE UID IS THE THREAD, NOT THE NAME ──────────────────────────────────────────

The link between a file and its dashboard is `grafana_uid` in the file's metadata.
No rename, on any surface, can break it — which is the whole reason names are free
to change at all. Every scenario here is really a restatement of that.

── RENAMING A FOLDER IS A DIFFERENT PROBLEM ─────────────────────────────────────

It has its own file (rename-folder.feature) and its own defect: a mapping is
stored as a PATH string, so renaming a mapped folder silently orphans it. Nothing
on this page has that problem, because nothing on this page is identified by name.

── STATUS ───────────────────────────────────────────────────────────────────────

The Nextcloud-side legs are cooked (Course 5) and now RUN IN CI — NameSyncListener
enqueues, ReconcileNameJob does the file-locked write/rename, and the writeback
carries the title to Grafana.

The deferral is not an optimisation: during a rename the file is LOCKED, so a
synchronous putContent throws. That is why every rename step drains
PushDashboardJob and then ReconcileNameJob before asserting — a test that checked
immediately after the MOVE would be racing a job that had not started.

The Grafana-side legs ride the ordinary pull and are still @todo. The refusals are
@unbuilt: NameSyncListener bails on an empty stem rather than reporting anything,
so nothing tells the user their rename went nowhere.

### A failed propagation never reverts the local rename

The local rename is the user's own gesture in their own file tree. A far-side
failure must not reach back and undo it — report the divergence and let the next
reconcile settle it. This is the deliberate asymmetry with delete, where a failed
far-side call DOES abort the local gesture: a rename is recoverable and a delete
is not.

### Renaming a link never renames the dashboard

A link is a read-only pointer with no dashboard JSON to rewrite and nothing to
push. Renaming the pointer file is a local act; the dashboard keeps its name and
the next pull re-derives the filename from Grafana.

### Renaming a dashboard in Grafana renames the mirrored file

The mirror image: the title changes on the far side and the pull carries it back.
Mode-agnostic — a link's filename follows a title change exactly as a sync file's
does, because in both cases the name is derived from Grafana, not pushed to it.

### Grafana will not let a dashboard be nameless, so that scenario is @blocked

"Rename a dashboard to nothing in Grafana" is tagged `@blocked` rather than left
live, because the WHEN cannot be performed: Grafana's own API rejects it outright
with `{"message":"Dashboard title cannot be empty","status":"empty-name"}`.

The behaviour it describes is still right, and still worth keeping written down —
a nameless dashboard can arrive by other roads (provisioning, an import, a record
older than the validation), and when one does the file falls back to the uid. What
is untestable is the arrange, not the rule.

`@blocked` is the honest tag here rather than `@decision`: nothing has been decided
against, the harness simply cannot reach the state.

### Three titles the filename grammar cannot read back

Found by adding awkward names to the rename outline, which is exactly what that
outline is for. The filename is the only carrier of a dashboard's title, and three
shapes are indistinguishable from the grammar's own markers:

| title | reads back as | why |
|---|---|---|
| `Board.af397c9y8enswf` | `Board` | the last segment matches `UID_RE` |
| `Report (1)` | `Report` | that is how a collision is spelled |
| `Board.grafana` | `Board` | `grafana` is 7 alphanumerics, so it matches `UID_RE` too |

**It is not cosmetic.** `NameSyncListener` keeps the filename, the JSON title and the
Grafana title in agreement, so a title that parses back short reads as a rename
nobody made — and the app renames the user's dashboard in Grafana to the truncated
form.

Not fixed here, and deliberately not papered over. `FilenameCodecTest` asserts the
WRONG values on purpose, under a name that says so, so the limit is documented and
the assertions flip the moment somebody fixes it. `rename.feature`'s Examples carry
only the shapes that do round-trip; adding these three would assert behaviour the
app does not have.

The fix is not a tighter regex — no regex separates a title ending in an id-shaped
word from an id. It needs the parse to be told the uid it is looking for, which the
app always knows from metadata, and that is a signature change across every caller.

### The app never invents a substitute name

A dashboard with no usable title must not produce ".grafana" with an empty
stem. FilenameCodec falls back to the uid — an ugly name is recoverable, a file
the app cannot round-trip is not.

### A rename to an empty or whitespace-only name is refused

NameSyncListener bails on an empty stem, so the JSON and Grafana keep the old
name while the file carries the new one — a silent three-way disagreement, which
is the one outcome this whole feature exists to prevent. @unbuilt: bailing is not
refusing, and nothing tells the user.

## folders/tags

`features/folders/tags.feature`

TAGGING A FOLDER — the folder half of `dashboards/tags.feature`, and the answer is
that there isn't one.

### A folder's tags are one set on both sides

Folder tags sync **both ways**, like everything else in this app. Change them in
Nextcloud and they appear on the Grafana folder; change them in Grafana and they
appear on the Nextcloud folder.

**THE STORE IS AN ANNOTATION, `nextcloud.kubed.io/tags`.** A folder has no tags
field of Grafana's own, but it is a Kubernetes object, so it has `metadata.labels`
and `metadata.annotations` and both accept our keys. Labels were the tempting choice
because they are INDEXED — `?labelSelector=` really filters server-side, verified
against the live instance. They are also unusable for this: k8s validation rejects
anything a real tag is likely to contain. Measured — `tag.kubed.io/Q3 Review` → 422,
`café` → 422, and a label VALUE of `Q3 Review` → 422, on both the key and the value.

Annotations have no such limit. `Q3 Review, café, ops/urgent, 日本語` round-tripped
exactly. So a Nextcloud tag maps to an annotation with **no escaping, no
normalisation and no reverse lookup table** — which is what makes the port between
the two sides a straight copy rather than a lossy encoding. The price is that
Grafana cannot search them, since annotations are not selectable (a `fieldSelector`
on one returns 400). That price is worth paying: an unsearchable tag that is still
the user's actual tag beats a searchable one that has been mangled into
`Q3-Review`, where `Q3 Review` and `Q3-Review` would collide.

### TWO CORRECTIONS THIS SECTION HAS ALREADY SURVIVED

Recorded because both were confident and both were wrong, in the same direction —
mistaking *a surface I could see* for *the whole surface*.

**"A folder's tags never arrive from Grafana."** This confused the UI having no
control with the thing being impossible. Grafana's folder screen offers no way to
set labels or annotations; the API does, and so does the MCP. A behaviour is not
defined by which surface reached it — everywhere else in this spec the medium goes
unmentioned unless the medium is the point.

**"A tag applied in Nextcloud stays in Nextcloud."** The argument was that pushing
outward would leak somebody's private filing into a shared Grafana, where no screen
could show it back to them. It reads plausibly and it is still wrong, because it
argues against the premise of the whole app: this is a bidirectional mirror, and
carving out one field as outbound-only makes it a mirror with a blind spot. The
same reasoning would have refused dashboard tags. A tag is a tag; it syncs.

### Tagging a folder in a link mapping does not reach Grafana

The mode rule holds for folder tags exactly as it does for a dashboard's — under a
link, Grafana owns the state and Nextcloud is a mirror of it, so a tag applied here
does not travel and settles back on the next sync. This is the folder-level twin of
"changing the tags on a link does not change them in Grafana".

### A folder tag does not move the clock, and that is measured

Grafana owns a mirrored folder's modified time exactly as it owns a dashboard's.
The surprise is what counts as a change. Measured on the live instance:

| write | `resourceVersion` | `generation` | `version` | `updated` |
|---|---|---|---|---|
| create | set | 1 | 1 | = created |
| annotation (i.e. a TAG) | advances | 1 | 1 | **unmoved** |
| `spec.description` | advances | 2 | 2 | **moves** |
| `spec.title` (legacy rename) | advances | — | 2 | **moves** |

Only a SPEC change is an update. Tags live in `metadata.annotations`, so by
Grafana's own reckoning tagging a folder is not a modification at all. Nextcloud
agrees from the other side: assigning a systemtag left the folder's mtime
identical (`1786735702` before and after).

So the end state is "the time did not move", and both sides arrive there on their
own. This is the OPPOSITE of a dashboard, where `tags` is a top-level key in the
spec body — so a dashboard tag change IS a save, and its `updated` moves, which is
why `dashboards/tags.feature` asserts the file carries Grafana's new time.

**The consequence for whoever builds the pull:** a folder tag change cannot be
detected by comparing timestamps, because there is no timestamp change to compare.
The annotation value itself has to be compared, or `resourceVersion` tracked. Do
not reach for the mtime here; it will always say nothing happened.

### A Grafana folder has nowhere to put a tag OF ITS OWN

Measured against the live instance, not assumed. A folder's entire spec is:

    FolderSpec:
      properties: [description, title]
      required:   [title]

There is no tags field. The `tags: []` that `/api/search?type=dash-folder` returns
for every folder is the shared search-result envelope — the same shape dashboards
come back in — and for a folder it is always empty and never writable. It is a
convincing decoy: anything reading it would look like folder tags worked.

**A folder IS a Kubernetes object**, so it has `metadata.labels` and
`metadata.annotations`, and both accept our keys. Probed and verified:

- labels are genuinely INDEXED — `?labelSelector=nextcloud.kubed.io/folder-id=12345`
  returned exactly the folder, and 0 for a miss
- `merge-patch+json` sets one key and deletes one with `null`, leaving
  `grafana.app/*` untouched — no wholesale map overwrite
- a legacy rename and a legacy move both PRESERVE labels, annotations and description
- annotations take arbitrary text: `Q3 Review, café, ops/urgent, 日本語` round-tripped

So a tag CAN be stored on a folder — it just has to be **an annotation, not a
label**. k8s validation is enforced on labels: `tag.kubed.io/Q3 Review` → 422,
`café` → 422, and a VALUE of `Q3 Review` → 422. Real tag text only survives in an
annotation, which is lossless but not selectable (a `fieldSelector` on one returns
400). So `nextcloud.kubed.io/tags` is the store, and the searchability labels would
have offered is not available for tags specifically.

What a folder does NOT have is a tags field of Grafana's own — nothing the Grafana
UI displays as a tag, and nothing the legacy API surfaces. The folder tags column in
the UI is fed by the search envelope and is permanently empty. So the annotation is
not a second-best place to put a tag; it is the ONLY place, and the sync is a real
sync into it rather than a note left in a drawer.

### NEVER write "when a sync runs"

An earlier cut of the inbound scenario said `When a sync from Grafana runs`. That is
not a behaviour — it is a function call with a space in it. Nobody performs a sync as
an act of intent; they change a tag, and the tag shows up.

The correct shape is the one `dashboards/tags.feature` already uses:

    Given the folder "Demo/Team" whose tags are "quarterly"
    When the folder's tags are changed to "quarterly, ops" in Grafana
    Then the folder's tags are "quarterly, ops" in Nextcloud

Pre-state, the gesture, the end state. Whether that end state is reached by a
scheduled pull, a manual sync or a webhook is a HOW, and a HOW never appears in a
`When`. The same applies to any phrasing of the form "when X runs", "when the
listener fires", or "when the reconciler notices".

### Tagging the mapped folder is an example, not a feature file

There is no `mapping/tags.feature`. Tagging the mapped folder itself and tagging a
subfolder end in exactly the same place — a Nextcloud tag and an untouched Grafana
— so the difference is a pre-state, which makes it an EXAMPLE ROW. A second file
would restate one behaviour twice.

The Team Folder question that prompted the idea turned out to be a dining-room
detail, not a kitchen one: at the storage layer `ISystemTagObjectMapper` assigned
and read back a tag on a Team Folder root exactly as it did on a plain folder, and
`haveTag` answered true for both. What differs is what the Files app offers, which
is not this app's behaviour to specify. Mappings now default to an admin-owned
folder anyway, so the Background's mapped folders are plain ones.

### If the engine is ever built, mind the event floor

`TagAssignedEvent` / `TagUnassignedEvent` are `@since 32`; `info.xml` still says
`min-version="31"`. The legacy `MapperEvent::EVENT_ASSIGN` / `EVENT_UNASSIGN`
works on both, so the floor only has to move if the typed events are wanted.

## folders/rename

`features/folders/rename.feature`

Renaming a FOLDER — the folder half of rename-dashboard.feature.

Two very different folders can be renamed, and conflating them is the trap this
file exists to prevent:

  1. A MAPPED folder — the Nextcloud folder an admin bound to a Grafana folder.
  2. A SUBFOLDER inside one — an ordinary folder, or a mirrored Grafana folder
     if it carries the `grafana` tag (create-folder.feature).

── THE FINDING: A MAPPING IS STORED AS A PATH STRING ────────────────────────────

`Mapping::$ncFolder` is a path (`nc_folder`), and `MappingService::resolveForPath`
decides membership with `str_starts_with($relative, $folder . '/')`. It is NOT a
Nextcloud file id.

So renaming a mapped folder does not "move" the mapping — it silently ORPHANS it.
The mapping still names a path that no longer exists, every file inside the
renamed folder resolves to no mapping, and nothing anywhere says so. The dashboards
are untouched in Grafana, so nothing is destroyed; the connection simply stops,
quietly, and the next pull re-creates the whole folder at the old path.

That is the opposite of the promise the rest of the app makes. A dashboard file
survives renaming, moving and restoring because it is tracked by a stable **uid**
in its metadata rather than by its name — and the mapping it belongs to is tracked
by a **string**. The one identifier that is not stable is the one the whole mapping
rests on.

`admin-mapping.feature` compounds it: a mapping's folders cannot be edited after
creation, so an admin who renames the folder cannot repoint the mapping — they
must remove it and add it back.

── STATUS ───────────────────────────────────────────────────────────────────────

@unbuilt throughout. There is no folder-rename handling of any kind: no listener
watches folder renames, and `GrafanaClient` has no renameFolder. The first
scenario documents what happens TODAY (the orphaning) so the gap is written down
rather than rediscovered; the rest specify what should happen instead.

### Renaming a mapped folder silently orphans its mapping

WHAT HAPPENS TODAY, recorded so it is a known defect rather than a surprise.
Nothing is lost — no dashboard is deleted — but the mapping stops matching and
the user is told nothing.

### Renaming a mapped folder keeps the mapping pointing at it

What it should do instead. Following the rename keeps the promise the file-level
metadata already makes, and needs the mapping to be keyed by something stable —
the folder's Nextcloud file id — rather than by its path.

### A failed subfolder rename leaves the local rename standing

A failed far-side rename must not roll back the local one. The user's gesture in
their own file tree is theirs; the app reports the divergence and lets the next
reconcile settle it. Same rule as rename-dashboard.feature.

### Renaming the mapped folder in Grafana does not break the mapping

A mapping names a Grafana folder by **uid**, not by title, so a title change in
Grafana does not break it. Whether the Nextcloud folder should follow is the open
question — it is the user's own file tree, and the mapping was created against a
folder the admin named.

## mapping/move

`features/mapping/move.feature`

Moving either side of a MAPPING. The sibling of `mapping/rename.feature`, and a
separate file because a move is a separate gesture: a rename gives the folder a new
NAME under the same parent, a move gives it a new PARENT under the same name. You
cannot do both at once from Nextcloud, and the two have different reasons for being
safe.

### A mapping is a pair of ids, so a move is a no-op

The same argument as the rename, arriving from the other direction — see
[A mapping is a pair of ids, so a rename is a no-op](#a-mapping-is-a-pair-of-ids-so-a-rename-is-a-no-op)
for the model. A Nextcloud file id is stable across a move as well as a rename, so
the resolver finds the folder wherever it now sits, and the stored `ncFolder` label
is caught up to the new path.

**The two halves are safe for different reasons, which is worth not blurring.** The
Nextcloud half needed the id: before it, a move orphaned the mapping exactly as a
rename did, because both change the path that was being matched. The Grafana half
never needed anything — a mapping has always named the Grafana folder by uid, and a
uid does not change when a folder is re-parented. So one of these scenarios records
a fix and the other records an invariant that already held.

### Moving the mapped folder in Grafana does not break the mapping

A mapping names a Grafana folder by **uid**, so re-parenting it in Grafana changes
nothing the mapping rests on, and the Nextcloud folder does not follow. Same rule as
the rename: the mapping is the one place a differing pair is legitimate, whether the
pair differs by name or by location.

### A mapped folder that was deleted is not re-adopted by name

The mapping holds the folder's id, so when the folder is trashed the mapping resolves
to nothing — correctly. A new folder created with the same name is a **different
folder** with a different id, and the mapping must not silently adopt it.

This is the failure mode a name-keyed mapping cannot avoid and an id-keyed one gets
for free: under the old model, deleting `Demo` and making a new `Demo` would have
re-bound the mapping to a folder nobody chose, and started writing dashboards into
it. `@unbuilt` because deciding what the admin should SEE in that state — a mapping
pointing at nothing — is its own question, and the app currently just resolves to
nothing quietly.

## dashboards/ignore — RETIRED (specified, never built, now removed)

Two exclude tags were specified here — `nextcloud:ignore` set on a dashboard in
Grafana, and `grafana:ignore` set on a file in Nextcloud — either of which would
leave one dashboard out of its mapping. Every scenario was `@unbuilt` or `@todo`.
No code was ever written for them, and now none will be.

WHY, and the n8n sibling is the evidence rather than the argument. It BUILT this
feature and then removed it whole: `ignored` invented a third state that belonged
to neither system properly — present in Nextcloud, excluded in the mirror, skipped
by every sync — and the value leaked into six places that had to know about a mode
whose only purpose was to opt out. A file in a mapped folder IS the mirrored
object; that is the premise the app is built on, and `ignored` contradicted it.

Here it cost nothing to remove because it was never built, which is the happy
version of the same lesson: the seams held open for it in `SyncService` (the
`$ignoredUids` index split, the `$effectiveMode` parameter that could only ever
be the mapping's mode) are closed, and the pull is a little shorter for it.

WHAT TO DO INSTEAD. Move the file out of the mapped folder — it stops being
mirrored and stays an ordinary Nextcloud document. That is `dashboards/move.feature`,
and it is the gesture people actually reach for.

NOT TO BE CONFUSED WITH the `grafana` FOLDER tag (`folders/create.feature`), which
is very much alive and is the opposite kind of thing: it marks a subfolder as one
that should exist in Grafana, and a user assigning it by hand is how a folder opts
IN. See `the grafana: namespace` note below for why one survived and four did not.

## dashboards/restore

`features/dashboards/restore.feature`

Restoring a dashboard file from the Nextcloud trash — the other half of
delete-dashboard.feature, and a behaviour in its own right rather than an appendix
to deleting.

── WHY THIS IS A SEPARATE FILE ──────────────────────────────────────────────────

A restore does not need to re-perform a delete to be specified. It starts from a
state — *"a trashed sync dashboard file whose dashboard is parked in the bin"* —
and the question it answers is what comes back. Folding that into the delete file
meant every restore scenario carried a delete it was not testing, and the reader
had to hold both halves in mind to find the one being asserted.

Delete owns "what leaves". Restore owns "what comes back, and as what".

── RESTORE IS WHERE THE TWO BIN MODELS DIVERGE MOST ─────────────────────────────

Trashing looks superficially similar either way — the file goes to the trash, and
something happens in Grafana. Restoring is where the difference becomes visible to
the user, because it decides whether they get their dashboard BACK or merely get
a new dashboard with the same content:

  | bin OFF (default)                    | bin ON (opt-in)                       |
  |--------------------------------------|---------------------------------------|
  | the uid was stripped at trash time   | the uid was kept                      |
  | create-on-land makes a NEW dashboard | the parked dashboard is MOVED back    |
  | **new uid**, history gone            | **same uid**, history intact          |
  | content preserved (it was in the file) | content never left Grafana          |

Nothing is ever lost either way — a sync file holds the whole dashboard JSON, which
is the invariant that makes bin-off survivable at all. What bin-on buys is
**identity**: the uid, the version history, and every Grafana-side reference to it.

── SAY IT PLAINLY: WITH THE SHIM OFF, A RESTORE MAKES A NEW DASHBOARD ───────────

It is not the old dashboard coming back. Grafana deleted that one permanently at
trash time and cannot return it. What a restore does is take the JSON out of the
Nextcloud file and CREATE a dashboard from it — the same panels, the same queries,
the same title, and a brand-new uid.

For most people that is indistinguishable from a restore, which is why it is a
reasonable default. What it costs is everything keyed to the old uid, and none of
it is recoverable:

  - the **URL**. `/d/<uid>/…` is the uid. Bookmarks, wiki links, runbooks, Slack
    messages and screenshots pointing at that dashboard all 404.
  - **version history.** Grafana's per-save revisions belonged to the deleted
    dashboard. The restored one starts at version 1.
  - **anything referencing it inside Grafana** — a panel link, a playlist entry, an
    annotation query, an alert's dashboardUID.

That is the trade the default makes: content is always safe, identity is not. An
admin who cannot afford to lose the identity turns the shim on, and that is the
whole reason the setting exists.

── THE HARD PART IS THAT THE WORLD MOVED ────────────────────────────────────────

A file can sit in the trash for weeks. By the time it comes back, its mapping may
be gone, its parked dashboard may have been deleted in Grafana by hand, or someone
may already have moved that dashboard back. A restore must succeed in all three
cases: Nextcloud's trash is the user's own undo history, and this app does not get
to veto it. The bottom section is those cases.

── STATUS ───────────────────────────────────────────────────────────────────────

RestoreFromTrashListener + DeleteService::restore are built and unit-tested
(bin-ON-moves-back-keeping-id, bin-OFF-recreates, no-recreate-outside-a-sync-
mapping, link-is-a-noop). The bin-on restore now runs in CI; the rest are @todo
for want of their step definitions, not for want of code.

### Restoring into a mapping that no longer exists leaves a plain file

The mapping was torn down while the file waited in the trash, so there is no
longer anywhere for it to belong. Restoring must still succeed as a Nextcloud
operation — the user's undo is not this app's to veto — and simply leave the
file unmanaged. RestoreFromTrashListener already handles the unresolvable
mapping; what is untested is that the file comes back at all.

### A bin-off restore cannot preserve the old dashboard's URL or history

The consequence of "a new dashboard", written down because it is the part users
discover the hard way and the part no amount of care on our side can prevent.
@decision, not @unbuilt: preserving the uid across a true Grafana delete is not a
feature we have declined to build, it is not possible — Grafana destroyed the
object. The recycle-bin shim exists precisely so an admin can opt out of this.

### Moving a dashboard out of the bin in Grafana brings its file back out of the trash

══ RESTORED IN GRAFANA ════════════════════════════════════════════════════════

Because the bin is a SHIM — an ordinary Grafana folder anyone can browse — a
restore can legitimately start on the Grafana side: someone drags a dashboard out
of "nextcloud-trash" and back where it belongs. That IS a restore, performed by a
person who never touched Nextcloud, and the mirror should follow.

THE DETECTION IS THE UID, NOT THE FILENAME. The reconcile already knows the uid of
every dashboard it sees. What it does not currently do is look in the Nextcloud
TRASH for a file carrying that uid — it indexes `$folder->getDirectoryListing()`,
and a trashed file is not in the folder. So today it writes a brand-new file and
leaves the trashed one orphaned; restore that copy later and two files claim one
dashboard, the exact duplicate the reconcile is otherwise careful to avoid.

The fix is a trash-aware reconcile: before creating a file for an unseen uid, look
for a trashed mirror carrying it and RESTORE that instead. The penpot sibling built
exactly this (penpot saga §6.37); neither n8n nor Grafana has it.

### A dashboard reappearing in Grafana never empties the Nextcloud trash

══ WHAT STILL DOES NOT RESTORE FROM THE GRAFANA SIDE ══════════════════════════

A dashboard merely EXISTING again is not a restore. Nextcloud's trash is the
user's own undo history, and a dashboard being re-created in Grafana by unrelated
means is not permission to empty it. The scenario above is narrow on purpose: it
is about a dashboard we ourselves parked, being taken back out of the bin we
ourselves put it in. Anything wider is the user's call.

### Restoring a parked file whose dashboard was deleted in Grafana re-creates it

Bin ON, but someone deleted the parked dashboard in Grafana directly. The kept
uid now names nothing. The restore is an idempotent upsert on that uid, so it
re-creates rather than failing — the file's JSON is the surviving copy.

### Restoring a file whose dashboard is already back in place is not a conflict

The race the scheduled pull makes easy to hit: someone moves the dashboard back
out of the bin in Grafana, then the user restores in Nextcloud. Moving an
already-in-place dashboard must be a no-op, not a conflict — the same
idempotency every other write in this app relies on.

## folders/restore

`features/folders/restore.feature`

Restoring a FOLDER from the Nextcloud trash — the folder half of
restore-dashboard.feature.

── ONE GESTURE, N IDENTITIES ────────────────────────────────────────────────────

Restoring a folder is restoring every dashboard file inside it, and under bin-off
every one of those gets a NEW uid. So a folder that held forty dashboards comes
back as forty dashboards that are, from Grafana's point of view, entirely new
objects — nothing that referenced them by uid still resolves.

Under bin-on they come back as themselves. That asymmetry is the whole reason the
recycle-bin setting exists, and it is at its most expensive here.

── STATUS ───────────────────────────────────────────────────────────────────────

The per-file restore path is built and unit-tested, so "does the per-file rule hold
when a folder is the gesture" is @todo. The aggregate behaviour — reporting what
came back, and what came back with a different identity — is @unbuilt: nothing in
`lib/` treats a folder restore as one event.

### Dashboards leaving the bin bring their folder out of the trash

The other door into a restore, and the mirror of the Grafana-side purge: someone
moves the parked dashboards out of the bin folder in Grafana, and the reconcile
notices they are live again. The trashed Nextcloud folder follows them out.

Bin-on only, for the same reason the Grafana-side purge is: with the bin off the
dashboards were destroyed at trash time, so there is nothing in Grafana to move and
no signal to observe.

The uids are what make this a restore rather than a second copy — they name files
that already exist in the Nextcloud trash, so those are brought back rather than
written fresh beside them.

**THE NEXTCLOUD TRASH IS WHAT MAKES THIS WORK, and it is worth being explicit about
why the Grafana bin does not need to be cleverer.** Nextcloud's trash keeps a
deleted folder as a whole subtree, with its original path, so the structure is never
lost on that side. The Grafana bin can therefore be a FLAT holding pen keyed by uid:
the dashboards sit in one folder with no hierarchy, and the restore rebuilds the
Grafana folders from the Nextcloud tree and re-files each dashboard by its uid. Two
sides, and only the one that already had the structure has to remember it.

### A restore out of the bin brings back whatever shared the folder

A folder comes out of the Nextcloud trash **whole**. A spreadsheet that rode into the
trash inside the folder rides back out with it, because Nextcloud restores the node
and everything under it — this app is not selectively reassembling a folder.

That is the restore being unsurprising rather than clever, and it is the counterpart
to the delete rule: a Grafana-side delete may not destroy a user's non-dashboard
files, and a Grafana-side restore does not leave them behind either.

### A folder restore reports which dashboards came back with new identities

The aggregate gap, same shape as the one in delete-folder.feature: each file is
restored correctly and nobody is told what the gesture cost. Under bin-off that
cost is every uid in the folder.

## folders/purge

`features/folders/purge.feature`

Emptying the Nextcloud trash of a trashed FOLDER — the folder half of
`dashboards/purge.feature`, and the second half of the gesture
`folders/delete.feature` starts.

The two files split the same way they do for a single dashboard: **delete** is the
trash step, **purge** is the one that cannot be undone. Keeping them apart is what
makes the bin argument below legible — the whole point of the recycle-bin folder is
that it puts a survivable step in front of an unsurvivable one.

### A purge reaches everything the trash gesture put there

A purge finishes whatever the trash started, so purging a trashed folder reaches
every dashboard that folder held.

**The two bin modes are two SCENARIOS, not two rows of one.** An earlier cut wrote
them as an `Examples` column on the grounds that the bin only decides where the
dashboards were waiting. That is wrong, and the suite's own test for an outline says
so: the rows can only be written as *sentences*, never as values, because the
PRE-STATE differs.

| | after the folder was trashed | what the purge does |
|---|---|---|
| bin **off** | the dashboards are already gone, and the files lost their uids with them | nothing — Grafana is never contacted |
| bin **on** | the dashboards are parked in the bin folder, and the files still carry the uids that match them | three deletes |

The end states read alike — no dashboards in Grafana either way — and that is exactly
the trap. It is true for opposite reasons, so a single outline would have asserted
"none exist" in a run where the app did nothing and in a run where it destroyed
three dashboards, and passed both. The asymmetry is where a bug in the bin-on path
would hide.

`dashboards/purge.feature` already made this split for a single file; the folder file
now matches it rather than compressing it.

**This is where the two bins earn their keep, and it is worth spelling out because
the far side is unforgiving.** A Grafana folder delete **cascades** — measured, not
assumed: deleting a parent removed its nested child in one request, with no
confirmation and nothing to undo it, because a service account has no trash to reach.
So a single mis-aimed folder delete can destroy an arbitrarily deep subtree.

Two separate safety nets sit in front of that, and they cover different halves:

| | what it protects | how you get back |
|---|---|---|
| the **Nextcloud trash** | the FILES, always, in both bin modes | restore the folder |
| the **Grafana recycle-bin folder** | the DASHBOARDS and their uids | restore re-files them, ids intact |

With the bin OFF, trashing a folder deletes its dashboards in Grafana immediately;
the files survive in the Nextcloud trash, so a restore rebuilds the dashboards from
their bodies but they come back with NEW uids. With the bin ON, the dashboards are
only parked, so a restore returns the SAME dashboards — the uids, the URLs and the
history survive.

That is the whole argument for the bin: it converts an irreversible cascade into a
move. The purge is the moment the user says they meant it, and only then does the
cascade become permanent.

### A purge in the Grafana bin reaches back into the Nextcloud trash

A purge has two doors, and the file previously had only one. Emptying the Nextcloud
trash is the obvious one; the other is someone deleting the parked dashboards in
Grafana, which the next reconcile notices — an item in our trash is no longer in
Grafana's.

**This door only exists when the recycle bin is ON**, and that is not a caveat but
the whole precondition. Grafana has no trash of its own; the bin folder IS the trash,
and it exists only because this app made it. With the bin off there is nothing parked
to delete and no signal to observe — the dashboards went at trash time, so the
Nextcloud trash is already the only copy.

The end state is the same as the Nextcloud-side purge, reached from the other side:
the dashboards are gone for good, so the trashed mirror has nothing left to be
restored to and follows them out.

### A Grafana purge may not destroy what was never Grafana's

The respectful half, and it splits into two scenarios for exactly the reason the
Grafana-side folder delete does — the contents change the END STATE:

| the trashed mirror held | what a Grafana-side purge leaves |
|---|---|
| only dashboards | nothing — the folder goes from the Nextcloud trash too |
| dashboards and other files | the folder stays in the trash, holding only the others |

A spreadsheet in a trashed folder has no far side. Nothing that happened in Grafana
can be a reason to destroy it, even though the gesture that triggered this was a
delete. So the purge takes the dashboard files and stops, and the folder remains in
the trash — still restorable, just no longer carrying anything Grafana knows about.

This is the same asymmetry recorded below for the Nextcloud-side purge: contents are
a ROW when the gesture starts in Nextcloud (everything goes either way) and a
SCENARIO when it starts in Grafana (the answer differs).

### What the folder held is an example, not a scenario

**Recursive is recursive.** A trashed folder is purged as one subtree because that is
what the trash held — Nextcloud trashes the whole thing as a unit and the purge
finishes exactly that unit. Whether the subtree happens to contain nested folders,
empty folders, or a spreadsheet does not change the rule, the pre-state, or the end
state, so those belong in an `Examples` column: one dashboard, three, a subfolder
holding more, a subfolder holding none, a folder with a `.xlsx` in it.

An earlier cut gave nesting its own scenario on the grounds that "the nesting is the
point". It is not — the *reaching* is the point, and depth is one of several ways a
folder can be shaped. Writing each shape as a scenario would state one rule five
times and invite the five to drift.

**This is the mirror image of the bin split above, and the pair is worth reading
together.** Both were decided by the same question — *do the rows differ only in
values, or in sentences?* The bin modes differ in sentences (the dashboards are
parked, or already gone), so they are scenarios. The contents differ only in values,
so they are rows. Getting either backwards costs something real: as an outline the
bin modes hid an asymmetry, and as scenarios the contents duplicated a rule.

**The Grafana side does NOT collapse this way**, which is the tell that the axis is
genuinely about outcome and not about tidiness. There, whether the Nextcloud mirror
holds non-dashboard files changes the END STATE — the folder is deleted outright, or
it survives stripped of its dashboards and its `grafana_folder_uid` — so those are two
scenarios in `folders/delete.feature`. Same input shape, different answer, because the
gesture arrives from the other side.

## the grafana: namespace — RETIRED ENTIRELY

The app wrote three pills on every managed file, one per mode: `grafana:sync`,
`grafana:link`, `grafana:unmapped`. They are gone, along with `grafana:ignore`
and `nextcloud:ignore`, which were specified and never built.

WHY. The MAPPING decides a file's mode and the file's own `grafana_mode` metadata
records it — which is what every code path actually reads. The pill was a second
copy of that truth: kept in lockstep by the app, editable by nobody, load-bearing
for nothing. `OwnershipTags` existed only to keep the copy honest.

AND IT WOULD HAVE BEEN THE EXPENSIVE COPY. Tag sync is not built here yet
(`dashboards/tags.feature` is entirely `@unbuilt`), so unlike the n8n sibling
there was no reserved-namespace filter to dismantle — but there would have been,
and the sibling shows exactly what it costs: an exclusion woven through
`contentTags()`, the merge inputs, the baseline stamp, the body writeback and a
listener gate, all of it there only because the app put its own tags on the same
files as the user's. Removing the pills BEFORE building tag sync means that
filter never gets written.

THE UPGRADE WAS A DELETION, AND THEN IT DID NOT NEED TO BE. Once dashboard tags
sync, a leftover `grafana:sync` pill is an ordinary tag on a mirrored file and
would be PUSHED TO GRAFANA — seeding every dashboard with a tag nobody chose. A
`Migration\RemoveModePills` repair step deleted the five retired DEFINITIONS once
to prevent exactly that. It has been REMOVED: this app has never been released,
so there is no instance anywhere carrying those pills, and a migration whose
whole job is cleaning up a past that never happened is code pretending to have
history. If the app ships and someone somehow has them, the step is in git.

Deleting a tag definition is something this app otherwise refuses to do: a catalog
is shared, and a definition may be pinned on files it knows nothing about. These
are the exception because the app minted them and no human chose them.

## THE `grafana` FOLDER TAG IS NOT ONE OF THESE, AND IT STAYS

It is worth being explicit, because they are all system tags and the distinction
is the whole point:

  · a MODE PILL echoes metadata the app already owns. Nobody can edit it, nothing
    reads it, and removing it loses nothing.
  · the `grafana` FOLDER TAG is the only record that a folder is MEANT to be a
    Grafana folder, and assigning it by hand is how a user opts a folder in. It
    starts things; the `grafana_folderUid` that follows is what lookups read.

The penpot sibling settled this shape first (`penpot` on a project folder, marker
AND opt-in, non-authoritative once the id lands) and it is the right one for us:
penpot has teams → projects, and Grafana is recursive, so every folder BELOW a
mapped root is the analogue of a project. The mapped root itself needs no tag —
it is the mapping. n8n has no analogue at all, because n8n has no folders: it
fakes structure with tags, which is why its mapping IS a tag.

## dashboards/tags

`features/dashboards/tags.feature`

Bidirectional dashboard-tag sync — a dashboard's tags and its Nextcloud system
tags are kept as ONE set, so the mirror is as searchable as Grafana.

Imported from the finalized n8n sibling (nextcloud-n8n Chapter 5 §5.6 — the
TagMerge / TagSyncService / ContentTagListener / ReconcileTagsJob engine + the
*_syncedTags baseline) and re-cut for Grafana's ingredient. DESIGN, NOT WIRED:
the whole feature is @unbuilt — CI skips it — until (1) the sibling's tag PR merges
(we port the engine as our base) and (2) the file-lifecycle mode machine (Course 4)
lands, since tag sync scopes itself to mapped files and no-ops on unmapped/ignored.

Two label systems, made equal (minus our control tags):

  • Grafana tags   — free-text strings that live INSIDE the dashboard object
                     (`dashboard.tags: ["dns","linux"]`). No tag-id API, no tag
                     catalog: a tag exists exactly when some dashboard carries the
                     string, and writing tags = upserting the dashboard. Folders
                     have no tags.
                     VERIFIED ON THE LIVE MIRRORS, not assumed (saga Course 9):
                     `tags` is a top-level key in the mirrored file, beside
                     `title`/`uid`/`panels`, in both a 143-byte dashboard and a
                     682 KB one. `DashboardBody::VOLATILE` strips `id` and
                     `version` and nothing else, so the file IS the spec entire.
  • Nextcloud tags — collaborative SYSTEM TAGS (the coloured pills in Files,
                     searchable via DAV REPORT).

THE RULE OF EQUALITY: after a reconcile a managed dashboard's Grafana tags and its
Nextcloud system tags hold the same strings, with ONE exclusion — the app's
reserved namespace `grafana:*` (`grafana:sync`, `grafana:link`, `grafana:unmapped`,
`grafana:ignore`, and any future control tag). Reserved tags are the app's control
plane: never pushed into Grafana, never imported from Grafana as content.

THREE EDIT SURFACES — the object body is the third: tags live INSIDE the dashboard,
so a sync file's on-disk JSON already has a `tags` array. Three editable places,
kept as one set:
  1. Grafana `dashboard.tags`   (edit in Grafana → pull)
  2. the file body `tags` array (edit the JSON → push)
  3. Nextcloud system-tag pills (edit the pills → push)
The FILE BODY is the canonical object; the PILLS are a listener-kept projection. In
`link` mode the body is a pointer, so only surfaces 1 and 3 exist and the pills are
a read-only projection of Grafana.

NO EXTRA BUTTON FOR TAGS (n8n "Slice A" — the reactive engine): a content-pill
add/remove on a managed `sync` file is caught by a dedicated ContentTagListener
(TagAssignedEvent/TagUnassignedEvent for CONTENT tags, distinct from the reserved
`grafana:ignore` the mode listener watches) and reconciled to Grafana ON ITS OWN —
no "Sync to Grafana" click. It honours the SAME `timing` knob as the body writeback:
  • `sync`  — reconcile inline during the request.
  • `async` — enqueue a per-file ReconcileTagsJob the cron worker runs next tick.

GRAFANA IS THE EASIER COOK — three backend knobs, all simpler than n8n:
  1. TAGS ARE BODY-NATIVE, so there is NO tags-only side-channel. n8n reconciles a
     pill to a DECOUPLED tag endpoint (setWorkflowTags → PUT /workflows/{id}/tags)
     and must re-stamp its body hash so the silent body-tags write isn't re-pushed.
     Grafana has no such endpoint: `dashboard.tags` IS the object, so a pill edit
     updates the body's `tags` and rides the EXISTING body upsert — one push path,
     no re-stamp dance. The loop guard is the SyncGuard + `grafana_syncedHash` we
     already ship (a real tag change is a real body change — correct, not a hazard).
  2. THE PROTECTED-TAGS SET IS EMPTY. n8n's sharpest hazard — the mapping tag is a
     content tag (n8n maps a folder BY TAG, so dropping that pill would unbind +
     prune) — DOES NOT EXIST here: we map by real Grafana folders, so no content tag
     is ever load-bearing. The whole force-keep-the-mapping-pill / eject-via-ignore /
     union-of-mapping-tags apparatus evaporates. Protected set = [].
  3. PULL CHANGE-DETECTION IS A BRANCH SHORTER. Because tags live in the body, a
     Grafana-side tag change IS a body change `grafana_syncedHash` catches — n8n's
     separate "tags-only changed in the source" branch collapses into the ordinary
     body-changed path. Detection is just: skip-if-unchanged vs body-changed → write
     + reconcile pills.

PROVENANCE — add-on-one-side vs remove-on-the-other: when the two sets differ on a
string you cannot tell an ADD from a REMOVE from the current sets alone. So the app
banks the reserved-stripped tag set as of the last successful sync in
`grafana_syncedTags` (the tag analogue of `grafana_syncedHash`) and three-way-merges
against it: add-on-either-side keeps the tag, remove-on-either-side drops it (those
are disjoint against a single baseline, so the merge is deterministic). The only
genuine conflict — same tag added on one side, removed on the other since baseline —
falls to the reconcile's direction of truth (pull → Grafana wins, push → NC wins).

PRUNING — edges swept, catalog definitions not. Assignment EDGES (tag-on-this-file)
are pruned both ways: remove-on-either-side drops the edge. Catalog DEFINITIONS are
NOT auto-pruned — an NC system tag may be pinned on unrelated files. Reconcile is
prune-free by construction (compute the merged set first, write once, never mint a
pill it's about to drop). An OPTIONAL, opt-in `occ` sweep (dry-run first, never on
the hot path) can GC NC definitions orphaned everywhere. GRAFANA SUBTRACTION: Grafana
has NO tag catalog — a tag is just a string that vanishes with its last dashboard —
so there are no Grafana-side definitions to sweep; the sweep is NC-side-only here.

SCOPE — a mapped-folder feature: every behaviour here applies ONLY to a file managed
by a mapping. An `unmapped` or `ignored` file is a plain Nextcloud file — its pills
are ordinary system tags with NO Grafana side effect — so the listener + reconcile
must no-op on it.

── STATUS: NONE OF THIS IS BUILT ────────────────────────────────────────────────

There is no content tag-sync code in `lib/` at all. `OwnershipTags` writes the
MODE pills (`grafana:sync`/`grafana:link`/`grafana:unmapped`) and nothing else —
no content-tag mirror, no pill listener, no tag reconcile, no body tags array.

So every scenario here is @unbuilt, not @todo. The file-level @todo it used to
carry was claiming twenty-five items in the work queue that no test could ever
pass, which is precisely what makes a work queue worth ignoring. This is the
single largest correction in the tag makeover.

The `grafana:*` MODE pills are a different thing and they ARE built — see
reserved-tags.feature for the boundary between the two.

### Applying a set of tags is one gesture

── RULE: A TAG CHANGE IS A NEW SET, NOT A POKE ─────────────────────────────

This file used to spell out a scenario per direction per operation: a pill
added, a pill removed, a body tag added, a body tag removed, a Grafana tag
added, a Grafana tag removed. They were six sentences for one rule. Nobody adds
a tag; a person edits a list and saves it, and whether that list gained or lost
an entry is a property of the VALUES, not of the behaviour. So the gesture is
"the tags are now THIS", the add/subtract cases are rows of an `Examples`
table, and the combinations that were never reachable before — replace the
whole set, empty it, tag a dashboard that had none — cost a row instead of a
scenario.

THE PURELY-NUMERIC TAG IS NOW A ROW, not a scenario. `2024` had one of its own
because the sibling was bitten by it (a numeric string silently cast to an int
array key by a merge that keys on tag names). That is a value, and values
belong in the Examples table; if the coercion came back the row's set assertion
fails, which is the whole point. The old scenario also carried "and the tag is
a string, not coerced to a number", which asserts a PHP type rather than
anything a person can see.

THE SURFACES ARE THREE SCENARIOS, NOT THREE ROWS, and that is a rule from
`.github/instructions/gherkin.instructions.md` rather than a preference: origin
is exclusive, so a scenario is `@in-nextcloud` or `@in-grafana` and never both,
and `Examples` rows must be one rule over different inputs. A pill edit and a
Grafana edit are different rules with different payoffs. The surface is the
scenario; the set is the input.

THREE SURFACES, THREE SENTENCES. The payoff is not one step asserting "in
Grafana and in Nextcloud" — that reads as one sentence containing an "and" and
is really three checks wearing one name. A settled tag change means the tags
are on the Nextcloud pills, in the file, and in Grafana, so every scenario says
that in three lines and a failure names the surface that drifted.

WHY NO `When the mapping is pulled` SURVIVES IN THIS FILE. Nobody changes a tag
in order to run a reconcile. Grafana emits no outbound event, so a pull is
simply HOW the news of a Grafana-side change arrives — mechanism, not
behaviour, and a spec written on it has to be rewritten every time the plumbing
moves. It belongs inside the gesture. The same reasoning retires every
`is pushed` / `is reconciled` step and the `catalog sweep` scenario, whose only
action was "the sweep ran".

AND NO TIMING. Whether the writeback runs during the request or on the worker's
next tick is a knob in our plumbing, not something a person does; both settings
end in the same place, which is the only thing the spec has an opinion about.
The `sync`/`async` scenarios and the queued-job assertions are gone with it.

### Changing the tags on a link does not change them in Grafana

A link is a READ-ONLY projection of Grafana's tags: the pills are there so you
can search, but Grafana is the only writer. A tag added on a link never pushes,
and because a link has no push channel that stray tag would linger forever — so
the next sync wipes it, mirroring Grafana exactly. Both halves are one rule and
one scenario; split, the first half is a scenario whose only claim is that
nothing happened.

Searchability is asserted here rather than in a scenario of its own. It is the
POINT of mirroring tags at all, and a link is the strongest place to say it:
the file holds no dashboard, so its tags are the only thing making it findable.

### Changing the tags on a file the mapping does not own

SCOPE — TAG SYNC IS A MAPPED-FILE FEATURE. An `unmapped` or an `ignored` file is
a plain Nextcloud file: its pills are ordinary system tags with no Grafana side
effect, so the listener and the reconcile must no-op on it. The two states are
one rule over two inputs, which is what an `Examples` column is for — they used
to be two scenarios differing only in how the file stopped being owned.

WRITTEN AS A POSITIVE, deliberately. The old pair asserted "no tag push is
triggered" and "no tag-reconcile job is queued" — the first is the absence of a
behaviour and the second is a fact about our queue. What is worth stating is
that the file's own two surfaces still track each other with no remote system
involved, which is a real behaviour and the reason a tag applied out here
survives until the file is moved back into a mapping.

### RETIRED — "and nothing else in the file changed"

It sat on the `@in-grafana` scenario, and adding the `Modified` row is what exposed
it. Two problems, either one fatal.

**It had stopped being true.** A tag change in Grafana IS a save — `tags` is a
top-level key in the dashboard spec — so `updated` moves and the file's Modified
time moves with it. "Nothing else changed" and "the clock changed" cannot both be
the last word on the same gesture. Strictly the line meant file CONTENT and the
Modified time is metadata, but a reader hitting the two lines together has to
untangle that, and a step that needs untangling is a step that misleads.

**It was testing a negative with no edge.** What would it catch? It names nothing
in particular, so it can only be satisfied by enumerating the whole body, and it
would fail for a reason no scenario describes. The real fear underneath — a pull
mangling the body it rewrites — is a genuine bug we HAVE had (empty JSON objects
turned into arrays), and it belongs where that lives, not smuggled into a tag
scenario as a catch-all.

Same family as the vacuous `Grafana holds no folder named "Team copy"` retired
from `folders/copy.feature`. The surviving form of that idea is
`Grafana is not contacted` — a negative about ONE named thing, which is
falsifiable. `nothing else` never is.

### tags.feature — WHAT WAS RETIRED, AND WHY

Twenty-three scenarios became six. Nothing was deleted for being wrong about
the app; every entry below was a duplicate, a mechanism, another file's
business, or a test of something nobody has written.

MECHANISM AS THE ACTION — the largest group, and the rule they broke is the
oldest one in `gherkin.instructions.md`: describe behaviour, not
implementation. `When the "flows" mapping is pulled` / `is pushed` /
`is reconciled` / `When an admin runs the optional catalog sweep`. Where a real
gesture sat underneath, the sync was folded into it; where the sync WAS the
scenario, it went.

  · Push writes Nextcloud content tags into Grafana (sync only)
  · A link file never pushes its tags to Grafana
  · Editing the file body's tags array updates the pills and pushes to Grafana
  · Removing a tag from the file body's tags array removes the pill and the tag
  · A tag added in Nextcloud since the last sync is added in Grafana
  · A tag removed in Grafana since the last sync is removed in Nextcloud
  · Independent changes on both sides both survive a reconcile
  · An add on one side and an unrelated remove on the other both apply
  · A genuine conflict falls to the reconcile's direction of truth
  · Reconcile never mints a definition it is about to drop

TIMING AND QUEUES — our plumbing, described out loud.

  · Adding a pill pushes the tag to Grafana immediately when timing is "sync"
  · Adding a pill queues the tag push when timing is "async"
  · Removing a pill removes the tag from Grafana on its own

END STATE OF SOMETHING ELSE. A mirror wearing its dashboard's tags is what a
SYNC leaves behind, not a tag change, so it is asserted where the sync lives:
`connection/sync-now.feature` gains a scenario whose Grafana folder table
carries a `tags` column and whose Then reads the mirror.

  · Pull mirrors Grafana tags onto the Nextcloud file as system tags
  · Pull mirrors tags even for a link mapping (searchability, not push)
  · The reserved namespace is never imported as a content tag  (kept, restated
    as a change made in Grafana)

TESTING SOMETHING NOBODY WROTE. Grafana has NO tag catalog — a tag is a string
that exists exactly while some dashboard carries it — so there is nothing to
sweep on that side, and on the Nextcloud side no code deletes a system-tag
definition, by design (a definition may be pinned on files this app knows
nothing about). A scenario asserting a definition survived was asserting the
absence of a feature, and mostly testing Nextcloud.

  · A dropped tag is pruned from the mirror edge, not from the shared catalog
  · The optional catalog sweep keeps any tag still used, and is NC-side only

The sweep design — an explicit, opt-in `occ` command, dry-run first, never on
the reconcile hot path, Nextcloud-side only — stays recorded above. It gets
scenarios when it gets code.

WHY THE SKIP IS POSSIBLE AT ALL, kept from the change-detection section that
went with its scenarios: `DashboardBody::VOLATILE` strips `id` and `version`,
the two fields Grafana rewrites on every save. Without that a mirror would
differ from Grafana on every read and there would be nothing to skip. A tag
change lives in the body, so it IS a body difference — which is the extra branch
the n8n sibling needs and we do not.

THE MERGE. The `grafana_syncedTags` baseline and its three-way merge are real
design and stay documented above; what they do not get is scenarios, because
every scenario that pinned them was an outline row with an extra `Given` that
PERFORMED A GESTURE. The merge runs on every row of every outline already. Its
one genuinely distinct case — the same tag added on one side and removed on the
other, resolved by the reconcile's direction of truth — has no user gesture that
distinguishes a pull from a push, so it is a note here rather than a scenario,
and it belongs to whatever unit tests the merge when it is built.

### A sync leaves the mirror wearing Grafana's tags

Lives in `connection/sync-now.feature`, not here, and the distinction is the
whole point of the makeover: NOBODY CHANGED A TAG. This is what a first sync
leaves behind, so it is asserted where the sync is, in the same shape as the
other end states that file already pins (the mirror's name, its uid, its mode,
its dates).

**The FOLDER is tagged too, in the same scenario.** A first pull has to bring both
halves or the mirror arrives half-dressed, and they are one behaviour — the mirror
wears what Grafana says — so they are one scenario rather than two. Splitting them
would restate "a sync imports tags" twice with a different noun.

The seeded Grafana folder carries an ordinary dashboard tag on purpose. With only
an untagged dashboard the assertion would pass on a mirror that imported no tags
whatsoever, which is exactly the regression it exists to catch.

## uninstall

`features/uninstall.feature`

Uninstall lifecycle — what happens to the SYSTEM and to the user's DATA when the
app is removed, and that a reinstall reconnects cleanly.

  - SYSTEM: removing the app runs the <uninstall> repair step (UnregisterMimetype),
    which REVERTS the custom-mimetype registration the install wrote into the
    Nextcloud core tree (config/mimetype*.json, core/img/filetypes/Grafana.svg,
    core/js/mimetypelist.js) and re-stamps the .grafana filecache rows back to
    application/json. The store's clean-uninstall rule is about this shared state.
  - DATA: the app ORPHANS the user's data — it never deletes the .grafana files,
    never clears their Files-Metadata, never deletes Team Folders, never touches
    Grafana. A sync folder is a full backup, so deleting it would be data loss. To wipe
    the Nextcloud side deliberately, an admin uses Purge first (see purge.feature).

Because the files keep their grafana_uid, a reinstall + pull RECONCILES them in
place (matched by uid, never duplicated) — the reconnect is free, by design.

The <uninstall> system leg needs a full app remove on a live pod (CI can't drive
it), so it stays @todo; the data-orphan + reinstall-reconnect legs are provable via
disable/re-enable + a pull, which exercises the same metadata-keyed reconcile.
Spec-first / @todo: the SYSTEM leg needs a real app-remove on a live pod (the CI
harness can only disable/enable, not remove+reinstall), so it stays manual. The
DATA promise — reinstall reconciles existing files in place by uid with NO
duplicates — is already proven LIVE by sync-now.feature ("a folder that already
holds a mirror is filled in place, not doubled"); a disable/enable changes
nothing about how a sync matches on uid, so re-proving it here would be
redundant.

### Removing the app reverts the custom mimetype registration

── system cleanup ───────────────────────────────────────────────────────────
@blocked, and the missing capability is named: this harness can only DISABLE and
ENABLE the app, never remove and reinstall it, so the uninstall repair step
(UnregisterMimetype) is unreachable from CI. The two scenarios below stay live
because disable/enable is exactly what they need.
