# Chapter 2 — Service for a King

> **Prerequisite:** Chapter 1 (*Mise en Place*) closed **complete**. The prep is
> clean: the walk-in is open (token in hand, `Authorization: Bearer`, `GET /api/health`
> green from the `cloud` pod), the ingredient is the good cut (real Grafana folders,
> not the master's tag-hack), the whole menu has been read dish by dish, and the
> **appetizer is already plated and tasted** — the admin connection panel stores a
> token encrypted, the folder-mapping card mirrors the master's layout with a **live
> folder picker**, and *Test connection* goes green against a real Grafana.
>
> Chapter 1 was everything-in-its-place. **Chapter 2 is service.** The burners are lit.

---

## The occasion — we're not cooking for the room, we're cooking for the king

Mise en place earns you the right to a real service. But this isn't a Tuesday lunch.
The master (`nextcloud-n8n`) is on the marquee now — starred, on
[apps.nextcloud.com](https://apps.nextcloud.com/apps/n8n_sync), a full menu sent
flawlessly night after night. The bar is set at *his* plate. So Chapter 2 has exactly
one standard: **feature parity with the master, cooked on our ingredient, good enough
to send to the king's table.** Every dish he sends, we send. Nothing half-plated,
nothing "for parity but not wired." When Chapter 2 closes, an admin who knows the n8n
app should sit down in front of the Grafana app and feel *no downgrade* — same menu,
different protein.

> **Dr K, tying the king's napkin himself:** *"Parity isn't 'looks the same.' Parity is
> 'the king can't tell which kitchen it came from.' You've plated the amuse. Now send
> the whole tasting menu — and every course lands hot, or it doesn't leave the pass."*

This chapter plans **the entire meal** — every dish, in the order we fire it — and
then commits to the **first round** we actually cook. We went dish-by-dish with the
master to *discover* the recipes. We don't need to re-discover them; we have the
master's cards. So this time we fire **several interrelated dishes per round**, and
the **first round finishes the whole dining room** — the admin page, complete to the
last garnish and every control actually working — before we light the sync engine
behind it.

---

## The full menu — every dish on the king's tasting

The master's menu is the spec (see `nextcloud-n8n` README + `lib/` tree). Below is the
whole thing re-plated for our ingredient, grouped into **courses** (each course = one
PR / one round). A dish is 🟢 *straight re-cook* (master's technique, nouns swapped),
🟡 *seasoned* (a real Grafana-shaped change), 🔴 *cooked fresh* (genuinely new), or
⛔ *left on his shelf* (doesn't belong on our menu).

### Amuse-bouche — already sent (Chapter 1)

| Dish | State |
|---|---|
| Admin **Instance** card (base URL + encrypted service-account token) | ✅ live |
| **Test connection** (authenticated `GET /api/folders`, missing-vs-rejected) | ✅ live |
| Folder-mapping card + **live Grafana folder picker** (CRUD round-trips) | ✅ live |
| `occ`: `set-token`, `test-connection`, `add/list/remove-mapping` | ✅ live |

---

### 🍽️ Course 1 — **The Dining Room** *(Round 1 · the next PR)*

> *Finish the room the king eats in, and make every switch in it real.* The admin page
> is the one surface an admin touches before a single dashboard moves — so we finish it
> **whole**, and every control **persists, reads back, and has an `occ` twin**. Nothing
> on this page is decorative when the course closes.

| Dish | Parity target (n8n) | Kind | Notes |
|---|---|---|---|
| **Sync Schedule** card | `AutoSyncSettings` | 🟢 | Enable scheduled pull · interval (`15m`/`1h`/`1d`) · push timing (async/sync). Persist to appconfig; read back. |
| **Sync Actions** panel, completed | `SyncSettings` (sync_settings.php) | 🟡 | Add **Sync from Grafana** / **Sync to Grafana** / **Purge Nextcloud files** buttons beside Test connection — the master's one-home-for-buttons layout. Present + honest now; they graduate to *live* as Courses 2–3 land. |
| Mapping card: **Groups + Team Folder wired** | `MappingSettings` fields | 🟡 | Today they render "for parity"; Round 1 makes them **persist and round-trip** through `MappingService` (folder→folder + mode + format + `nc_groups` + `use_team_folder`). No more decoration. |
| Mapping card: **format/mode fully honoured in config** | — | 🟢 | Ensure `json`/`yaml` + `sync`/`link` save, reload, and are the values the engine will read. |
| **Sync Schedule + Actions `occ` twins** | n8n `occ` set | 🟡 | Schedule via `config:app:set`; the bulk `sync`/`purge` commands arrive with their engine (Courses 2–3), same as the buttons. Mapping fields already have `add/list/remove-mapping`. |
| **Keep `admin_test.php` as the dead auth-target template** | n8n keeps it too | 🟢 | The master retains it as the `#[AuthorizedAdminSetting]` target even though it isn't rendered — so we keep it identical rather than diverge. |
| ⛔ **Webhook** card | `WebhookSettings` | ⛔ | **Left on his shelf.** Grafana has one API and one credential — there is no second push path to configure. Named here so it can't sneak onto the ticket. |

**Course-1 exit line:** the admin page shows **Instance → Sync Schedule → Folder
Mappings → Sync Actions**, every field round-trips through config, the folder picker is
live, groups/team-folder persist, and each control has an `occ` twin. The room is
finished; the burners behind the pass light in Course 2. *This is the "finalize the
entire admin page UI, then start making it work" round.*

---

### 🍖 Course 2 — **The Main Protein** *(pull: Grafana → Nextcloud)*

> The centre of the plate: real dashboards, pulled down as real files. This is where
> "Sync from Grafana" stops being a button and starts moving food.

| Dish | Parity target | Kind | Notes |
|---|---|---|---|
| **Adapter: list + read** | `N8nClient` list/read | 🟡 | `GrafanaClient.listDashboards()` / `readDashboard(uid)` on `/api/search` + `/api/dashboards/uid/{uid}`. |
| **Reconcile-by-uid pull** | `SyncService` (pull) | 🟢 | Match on **dashboard uid**, never filename — re-running a pull never duplicates. Prune a mapped file whose dashboard left the folder. |
| **Folder resolver (nested, longest-prefix)** | `MappingService` / Fork G | 🟡 | The master's longest-prefix resolver, finally used for a **real folder tree** — nested Grafana folders → nested NC folders; root "General" → mapping root. |
| **File writer + codec** | `StorageService`, `ManagedFile`, `FilenameCodec` | 🟢 | `Name.<uid>.grafana.json`; compound-extension rule (`.json` tail so the OS opens it, `.grafana.` as NC's hook). |
| **Dashboard metadata + DAV props** | `WorkflowMetadata` → `DashboardMetadata` | 🟡 | `grafana_uid` / `grafana_mode` / `grafana_version` / `grafana_mapping`, read-only over PROPFIND, `mode` indexed for fast REPORT. |
| **Custom mimetype + icon** | `RegisterMimetype` migration | 🟡 | `application/grafana+json` (+ `application/grafana+yaml` for Course 6). Grafana icon, not a generic JSON glyph. |
| **Tagging (mode mirror)** | `ModeTagListener` | 🟢 | `grafana:sync` / `grafana:link` pills, mutually exclusive, app-maintained. |
| **`occ` pull + smoke tools** | `sync pull`, `list/get-workflow` | 🟢 | `grafana_sync:sync pull`, `list-dashboards`, `get-dashboard <uid>`. |

**Exit:** click **Sync from Grafana** (or `occ`) and a mapped folder fills with the
real dashboards as `.grafana.json` files, tagged, mimetyped, uid-stamped — and a second
pull changes nothing. **Classic JSON cut only; v2/YAML waits for Course 6.**

---

### 🥄 Course 3 — **The Sauce** *(push: Nextcloud → Grafana, bidirectional)*

> A protein without sauce is a demo. The sauce is the writeback — edit a file, the
> dashboard changes in Grafana — and the discipline that keeps it from splitting into a
> loop.

| Dish | Parity target | Kind | Notes |
|---|---|---|---|
| **Adapter: upsert** | `N8nClient` upsert | 🟡 | `GrafanaClient.upsertDashboard()` via `POST /api/dashboards/db`, preserving **uid** (same dashboard, not a new one). |
| **Save → push** | `PushService`, `NodeWrittenListener` | 🟢 | Every save of a sync-mode file pushes back. |
| **Content-hash loop guard (Grafana-aware)** | `SyncGuard` / `n8n_syncedHash` | 🔴 | *The one to actually get right.* Grafana bumps a dashboard `version` on every save — hash **the spec we sent**, not Grafana's echoed-back object, or a push→pull looks like a change and loops (Ch1 risk #6). |
| **Request-scoped pull-write guard** | `SyncGuard` | 🟢 | Don't let our own pull writes trigger a push. |
| **Async/sync push timing** | `PushWorkflowJob` + timing setting | 🟢 | Honour the Course-1 push-timing switch. |
| **`occ` push** | `sync push` | 🟢 | `grafana_sync:sync push [--mapping]`. |

**Exit:** edit a `.grafana.json`, hit save, and the dashboard updates in Grafana with
its uid intact — and nothing loops. **Sync to Grafana** goes live.

---

### 🥗 Course 4 — **The Sides** *(the file-lifecycle semantics)*

> The dishes around the protein that make it a *meal* — the moves that feel like magic
> because the same dashboard follows the file wherever it goes.

| Dish | Parity target | Kind | Notes |
|---|---|---|---|
| **Create from Nextcloud** | `CreateInN8nListener`/`CreateService` | 🟢 | A new `.grafana.json` in a mapped sync folder becomes a real dashboard (`POST /api/dashboards/db`), uid-stamped. Outside a mapping → plain untracked file. |
| **Move (unmapped / restore)** | `MoveGuardListener` | 🟡 | Move out of a mapping → **unmapped** (NC keeps full JSON + uid); move back → dashboard restored, same uid. Link can't be ejected. Merge-on-collision by uid. |
| **The "archive" substitution** | n8n's `archive` verb | 🔴 | Grafana has **no archive state** (Ch1's one semantic that doesn't translate). Decide: *leave-in-place & drop from mapping* (default, honest) vs *move to an app-owned "archive" folder*. Wire it here. |
| **Copy (always new instance)** | `CopyListener`/`CopyService` | 🟢 | A copy strips identity — new uid, never hijacks the original. |
| **Rename (three-way)** | `NameSyncListener`, `FilenameCodec` | 🟡 | filename stem ⇄ `spec.title` ⇄ Grafana title, kept in agreement; uid is the stable thread. |
| **Delete (mode-aware, two-step trash)** | `DeleteService`, `DeleteToN8nListener`, `RestoreFromTrashListener` | 🟡 | trash→(archive-substitute)/untag, purge→Grafana delete, restore→re-add. Abort delete if Grafana unreachable (never desync). |
| **DAV link-write guard** | `LinkWriteGuardPlugin` | 🟢 | A link file is a read-only pointer — refuse writes to it over DAV. |

**Exit:** the full mode machine — **sync / link / unmapped / ignored** — behaves exactly
as the master's, adapted for "no archive verb."

---

### 🍤 Course 5 — **Plating & Garnish** *(the Files-app experience)*

> How the plate *presents*. The king eats with his eyes first.

| Dish | Parity target | Kind | Notes |
|---|---|---|---|
| **Open in Grafana vs text editor** | `src/files.js` | 🟡 | Mode-driven openers; deep link `/d/<uid>/<slug>` built from carried uid — zero extra lookup. |
| **"+ New" menu item** | `src/files.js` New menu | 🟢 | "New Grafana dashboard" in a mapped folder. |
| **Link mode** | link-mode files | 🟢 | Tiny pointer (uid/title/URL); perfect for **operator/GitOps-owned** dashboards (the master predicted this exact case). |
| **Reserved tag exclude** | `grafana:ignore` / `ReservedTagResolver` | 🟢 | Optional, hand-set, read-only — skip one dashboard even though it's in a mapped folder. |
| **Notifications** | `Notifier` / `SyncNotifier` | 🟢 | Sync result/error notices. |

---

### 🆕 Course 6 — **The King's Special Request** *(the v2 YAML cut)*

> The one dish the master *can't* make, because his ingredient doesn't come this way.
> Grafana serves a dashboard as a modern, k8s-style resource that reads beautifully as
> **YAML** — the GitOps cut. Dr K asked for it by name in Chapter 1.

| Dish | Kind | Notes |
|---|---|---|
| **`.grafana.yaml` serializer + `application/grafana+yaml`** | 🔴 | Second adapter mode on the same sync core — not a fork. |
| **Pin the cut on the mapping (`format: yaml`)** | 🔴 | Already a saved field (Course 1); here it selects the serializer + `apiVersion`. |
| **v2 round-trip proof** | 🔴 | Ch1 risk #1 — pull `dashboard.grafana.app/v2` as YAML, push back, prove no field loss. Ship classic JSON as default; YAML is opt-in. |
| **Self-describing version stamp** | 🔴 | File metadata stamps its apiVersion (like `n8n_versionId`) so a file always knows how to be read back. |

---

### 🍰 Dessert — **The Check** *(release readiness → the marquee)*

> The master's Chapters 4–5 in miniature: once the meal is whole, we earn the marquee.

| Dish | Kind | Notes |
|---|---|---|
| **Scheduled pull background job** | 🟢 | `ScheduledPullJob` on the Course-1 interval. |
| **README un-hedged + screenshots** | 🟡 | Drop "early development"; real screenshots replace the master's. |
| **Un-fuse the store publish leg** | 🟡 | Chapter 1 fused off apps.nextcloud.com upload "until feature-complete." Feature-complete is *here*. Light the marquee — the apprentice joins the master on the store. |

---

## The line order — how we fire the courses

```
  Course 1  Dining Room ......  admin page finished + every control live (Round 1, next PR)
  Course 2  Main Protein .....  pull: Grafana → NC files (classic JSON)
  Course 3  The Sauce ........  push: NC → Grafana, loop-guarded
  Course 4  The Sides ........  create / move / copy / rename / delete (mode machine)
  Course 5  Plating ..........  openers, New menu, link mode, ignore tag, notices
  Course 6  King's Request ...  the v2 / YAML cut (opt-in)
  Dessert   The Check ........  scheduled job, docs, screenshots, un-fuse publish → store
```

Courses 2 and 3 are the spine (a protein needs its sauce); 4 rounds out the mode
machine; 5 is presentation; 6 is the Grafana-only flourish; dessert is the marquee.
Each is one PR. Several interrelated dishes per PR — we have the master's recipe cards
now, so we cook in confident batches, not one nervous taste at a time.

---

## What "left on his shelf" means (so it never sneaks on)

- **⛔ The Webhook channel.** The master runs REST **and** a webhook as a second push
  path because n8n benefits from it. Grafana is **one API, one credential** — there is
  no second envelope to configure. We don't cook it, and Course 1 explicitly *omits*
  the Webhook card. (Any "test webhook" wording from the copy is dropped.)
- **⛔ Everything that isn't dashboards.** Alerts, datasources, contact points, library
  panels, playlists — all first-class in Grafana's `/apis/`, all **other nights**. This
  menu is **dashboards and their folders**. `link` mode can *point at* the rest later
  without us owning their sync.

---

## Round 1 — the dish we actually cook next (the commitment)

> Everything above is the menu. Here's the **ticket for the next PR.**

**Course 1 — The Dining Room.** Finish the admin page whole and make every control on
it real. Concretely, one PR that:

1. **Adds the Sync Schedule card** (`AutoSyncSettings`-equivalent): enable scheduled
   pull, interval, push timing — persisted to appconfig, read back on reload.
2. **Completes the Sync Actions panel**: **Sync from Grafana / Sync to Grafana / Purge**
   buttons beside **Test connection**, in the master's single-home layout. They're
   present and honest (they light up as the engine lands in Courses 2–3); Test connection
   is already live.
3. **Wires Groups + Team Folder in the mapping card** so they *persist and round-trip*
   through `MappingService` — retiring the "rendered for parity" caveat.
4. **Confirms mode + format** save/reload as the exact values the engine will read.
5. **Gives every new config control an `occ` twin** (schedule via `config:app:set`;
   mapping groups/team-folder round-trip through `add-mapping`). The bulk `sync`/`purge`
   commands land with their engine (Courses 2–3), matching the disabled buttons.
6. **Keeps `admin_test.php`** as the dead auth-target template — the master keeps it,
   so parity means keeping it, not deleting it.

**The PR ships when:** the admin page reads **Instance → Sync Schedule → Folder
Mappings → Sync Actions**, every field round-trips, the folder picker is live,
groups/team-folder persist, each control has a headless twin, `npm run build` + `eslint`
+ `vitest` are green, and it's **smoke-tested in a live Nextcloud pod** (Chapter-5 house
rule from the master: CI green ≠ a human clicking). No engine wiring beyond what's
needed to make the *config surface* honest — the burners light in Course 2.

> **Dr K, chalk in hand at the pass:** *"Menu's on the wall — the whole tasting, in
> order. But we only fire one ticket at a time. Round 1: finish the room and make every
> switch in it *mean* something. When a guest flips a switch, a light turns on — even if
> the stove it controls isn't lit yet. Then we cook the protein. Fire Course 1."*

---

## Round 2 — the protein, cooked by two hands *(Course 2 · this PR)*

> Course 1 finished the room. Round 2 lights the first burner — **the pull, Grafana →
> Nextcloud** — but we cut the ticket smaller than the menu implied and we put **two
> cooks on the same PR** at once. And before a single destructive verb is wired, we go
> back into the walk-in: the master's move/delete recipes assume an **archive** verb our
> ingredient doesn't have, so those semantics get **written as driving-truth docs this
> round, not cooked.** We learned something in prep that changes the recipe, so we
> re-plate before we sear.

### What we learned in the walk-in (the finding that resizes the ticket)

We put the master's delete/restore recipe to the fire against real Grafana, through the
`cloud` pod, with the app's own service-account token. The result:

- Classic `DELETE /api/dashboards/uid/{uid}` returns **200 "deleted"** and the dashboard
  is **gone immediately** — an instant `404`, no grace.
- The app-platform trash (`labelSelector=grafana.app/trash=true`) returned **0 items**,
  even with the `restoreDashboards` toggle **on**.
- `/api/search?deleted=true` — the UI's "Recently deleted" path — returned **401** to our
  SA token.

**Reading:** from *our* seat, **a Grafana delete is permanent.** The master's whole
delete/move safety net is `archive` → `unarchive` — a reversible soft-delete Grafana
simply does not offer us. So the master's core assumption ("the id is the thread; move
out archives, move back unarchives") **does not translate**. That is exactly the
data-loss trap Dr K warned about: *treating a Grafana dashboard like an n8n workflow
would quietly lose data the first time someone drags a file out of a folder.*

### The re-plated model — reversibility lives in **our** kitchen, not Grafana's

Since Grafana won't hold a safety net, **Nextcloud holds it.** Two pillars:

1. **The file *is* the backup.** A `sync`/`unmapped` file carries the **full dashboard
   JSON**. Move it out of a mapping and we do **not** try to "archive" in Grafana — we
   keep the JSON in the file, drop the mapping, and (the fork below) decide whether the
   live Grafana dashboard is left in place or removed. Move it back in and we **re-create
   / upsert from the JSON we still hold**, re-using the same `uid`. The thread is the
   `uid` **plus the bytes on disk** — not a Grafana-side archived object.

2. **The Nextcloud trashbin is the single reversible gate.** Delete = move to NC trash
   (fully recoverable — the JSON is right there). Grafana is only touched when the file
   is **purged from NC trash** — that is the one moment we issue the irreversible Grafana
   `DELETE`. Restore-from-trash re-links/re-creates. NC's own two-step trash becomes the
   soft-delete Grafana never gave us.

> **Dr K, at the walk-in door:** *"You proved the net isn't there. Good. So you build the
> net on our side of the line — the file is the parachute, the trashbin is the ripcord.
> Nothing leaves the pass that can lose a guest's dashboard on a careless drag."*

### Two cooks, one PR — the split (async-safe)

We put a second cook — **Claude** — on the line beside me, on the **same PR**, on files
that don't touch mine, so we cook more at once without colliding.

**Claude's station — the pure foundation (zero Grafana API, no merge surface with mine):**
- `lib/Service/FilenameCodec.php` — filename ⇄ name/uid parse+format (pure logic; port).
- `lib/Service/SyncGuard.php` — request-scoped re-entrancy guard (trivial; port).
- `lib/Service/ManagedFile.php` — typed value object over the metadata keys.
- `lib/Service/DashboardMetadata.php` — the Files-Metadata wrapper + key **registration**
  (the master's `WorkflowMetadata`), using the key set **decided below**.
- `lib/Migration/RegisterMimetype.php` — `application/grafana+json` mimetype + icon.
- Unit tests for each (the master ships `FilenameCodecTest`, `ManagedFileTest`, etc.).

**My station — the Grafana-facing spine:**
- `GrafanaClient` gains `listDashboards()` / `readDashboard(uid)` (`/api/search` +
  `/api/dashboards/uid/{uid}`).
- `StorageService` + the longest-prefix folder resolver (real folder tree).
- `SyncService` **pull** — reconcile-by-uid, prune, collision suffixes.
- `SyncController` + `occ grafana_sync:sync pull` wired to the live **Sync from Grafana**
  button.

**The seam that keeps us from colliding:** Claude owns *pure/no-API* files + the metadata
**contract**; I consume that contract from the Grafana side. We agree the metadata key
names and `ManagedFile` shape **first** (below), commit that as the interface, then cook
in parallel. Destructive verbs (move/delete/copy) are **docs only** this round — no code,
so no listener wiring conflicts.

### The metadata — scrutinised, not 1:1 ported

The master's five keys are `n8n_*`. We are **not** blindly renaming to `grafana_*`. Two
goals pull at once: **(a)** each extension's metadata must stay **isolated** — a
`grafana_*` file must never surface in an n8n-specific indexed query and vice-versa; and
**(b)** we want the *meaning* of each key to be **abstractable** into a future shared
module. Resolution for this round:

| Meaning | n8n key | **Grafana key (this round)** | Kind | Note |
|---|---|---|---|---|
| Stable backend id | `n8n_id` | `grafana_uid` | 🟢 rename | dashboard **uid** — survives rename/move. |
| Last-seen backend revision | `n8n_versionId` | `grafana_version` | 🟡 seasoned | Grafana bumps `version` **every save** — store it, **never hash it** (risk #6). |
| Loop-guard body hash | `n8n_syncedHash` | `grafana_syncedHash` | 🟢 rename | sha1 of **the spec we sent**, not Grafana's echo. |
| File mode | `n8n_mode` | `grafana_mode` | 🟢 rename | sync/link/unmapped/ignored; `link` still stored on-wire as `reference`. INDEXED. |
| Originating mapping id | `n8n_mapping` | `grafana_mapping` | 🟢 rename | INDEXED. |
| **Source folder uid** | — | **`grafana_folderUid`** | 🔴 new | Grafana has **real nested folders** (n8n had flat tags). Banked now — the move-back-in / cascade model below needs it. |
| **Serialization schema** | — | **`grafana_apiVersion`** | 🔴 new | classic JSON vs v2 YAML cut (Course 6). A file that **self-describes its schema** reads back losslessly. Banked now. |

**Isolation is safe by construction:** Nextcloud's Files-Metadata keys are a **flat global
namespace keyed by the exact string**. `grafana_mode` and `n8n_mode` are *different keys*;
one app's indexed REPORT can only ever match its own registered key. Two apps installed
side-by-side never cross-contaminate. So we get isolation **and** parallel structure for
free — no prefix collision, no shared index.

**The abstraction caveat (banked for down the road, NOT this round):** the *cleanest*
shared module would give these keys a **neutral, extension-agnostic name** (e.g.
`kubedsync_id` / `kubedsync_mode`) so a single shared library registers **one** key set and
both extensions consume it — but that would mean a file could carry a key that *both*
apps' queries match, which is the exact cross-contamination we want to avoid, **unless**
the shared module also carries a "source backend" discriminator. That is a real design
question (shared key + discriminator vs. per-extension keys + shared *code* that only
differs by a prefix constant). **We do not solve it now.** We note it: *when we build the
shared module, revisit whether the metadata keys in both the n8n and Grafana extensions
should collapse to a shared, discriminated key set — the per-app `n8n_*` / `grafana_*`
split we ship today is the conservative, isolation-first choice and can be migrated later.*

> **Dr K:** *"Name them so they can't bleed into each other today, but so a smart reader
> sees they're the same recipe. The shared pot comes later — don't burn it in now."*

### Subfolders — the lazy, presence-driven mirror (revised this round)

The master only had flat tags, so "a subfolder inside a mapped folder" was never a real
question for him. **Grafana has genuine nested folders**, so it *is* a question for us. An
earlier sketch modelled subfolders as *hidden child mappings* with a "has-parent" flag and
split them into "Grafana-mapped" vs "plain NC" kinds. **We're retiring that** — Dr K called
for something simpler and more magical. The decided model:

**One rule:** *a subfolder exists on the other side exactly when it holds a dashboard.*
Presence-driven, lazy, symmetric — and gated by a single per-mapping checkbox.

- **The control is a per-mapping "Sync subfolders" checkbox** (default **off**). On = the
  Grafana folder tree mirrors the Nextcloud folder tree under the mapped folder. The
  checkbox is the escape hatch — *turn it off when it acts up, on when it behaves* — so we
  iterate toward the perfect integration without a schema change.
- **With the checkbox OFF (the n8n-like flat model):** a subfolder of a mapped folder is a
  plain local Nextcloud folder. A dashboard dragged into it **keeps all its metadata** and
  stays bound to the **parent** mapped folder — same `grafana_uid`, same `grafana_mapping`,
  `grafana_folderUid` still the *parent's* Grafana folder — and **nothing changes in
  Grafana**. The nesting is purely cosmetic, exactly how the master treats a tagged workflow
  no matter where its file sits. (A file only becomes `unmapped` when it leaves *every*
  mapped folder — a subfolder of a mapping is still inside the mapping.)
- **No hidden mappings, no "two kinds."** A subfolder is **not** a separate mapping object
  and needs no pre-existing Grafana counterpart. There is just the one rule above.
- **Nextcloud → Grafana (the magic):** create or move a dashboard **into** a NC subfolder →
  the app ensures the matching Grafana subfolder exists (creating it **lazily**, by path,
  only when needed), places/re-parents the dashboard there, and stamps the file's
  `grafana_folderUid`. An **empty** NC subfolder creates nothing — the folder "magically
  shows up" in Grafana the instant a dashboard lands in it, and not before.
- **Grafana → Nextcloud (pull):** a Grafana child folder that *contains dashboards* is
  mirrored down as a nested NC subfolder; its files carry that child's `grafana_folderUid`.
- **The uid is always preserved.** Moving into a subfolder never re-mints — it re-parents
  (changes `folderUid`) and keeps the same `grafana_uid`.
- **The subtree belongs to the top-level mapping.** A file in a cascaded subfolder still
  resolves to the parent mapping (`grafana_mapping` = the top-level mapping); its
  `grafana_folderUid` records *which* Grafana subfolder it sits in. (Explicit **nested
  mappings** — an admin adding a second mapping *on* a subfolder — remain supported via the
  longest-prefix resolver; cascade is the automatic alternative that needs no second
  mapping.)
- **Folder lifecycle follows the dashboards:** when the last dashboard leaves a subfolder,
  the now-empty Grafana subfolder may be pruned (a detail deferred with the engine).
- **Deletes** follow the same NC-trash gate as any dashboard — there is **no special
  "block subfolder deletes."** (That block was scaffolding for the retired hidden-mapping
  model.) The *wiring* still lands with the delete Course; the model does not special-case
  depth.

Why this is better: it answers "what happens when I make a folder in a mapped folder?" with
*nothing — until you put a dashboard in it, then it appears in Grafana* — no manual tag, no
admin bookkeeping, one honest toggle to fall back on. `grafana_folderUid` (banked in Fork A)
is exactly the per-file breadcrumb this needs; a special "trigger tag" was considered and
dropped in favour of the dashboard-presence trigger.

> **Dr K, sketching folders on a napkin:** *"Don't make the admin declare a subfolder. Let
> them just drop a dashboard in a folder and watch it appear on the Grafana side like it was
> always there. One checkbox to switch the magic off when it misbehaves. We'll nail the
> corners over time — but the feel is 'it just mirrors.'"*

### Mapping the root — and the perfect one-to-one mirror

Grafana has a **root / "General" area**: dashboards with no folder. It has no real
`folderUid`, so it isn't in the folder list the picker fetches — but it's mappable, and it's
the key to the most powerful configuration we can offer. The folder picker gains a reserved
**`/` (General — dashboards with no folder)** entry; a mapping bound to it pulls the
no-folder dashboards into its Nextcloud folder (Ch1 already anticipated "General/root → the
mapping's root").

**The marquee case — a whole-instance mirror.** Map the Grafana root **`/` → Nextcloud `/`**
(or any folder) with **"Sync subfolders" ON**. Because the root encloses *every* Grafana
folder, the cascade follows the entire tree: every Grafana folder that holds dashboards
becomes a nested Nextcloud folder, every dashboard a `.grafana.json` file, nested exactly as
in Grafana — a **perfect one-to-one mirror of the whole Grafana instance's dashboards and
folder structure**, browsable and (in sync mode) editable as a file tree, edits pushing
back. This is the GitOps dream the v2/YAML cut (Course 6) then makes gorgeous.

- **Precedence still holds:** a more-specific folder mapping wins over the root mapping for
  its own subtree (longest-prefix resolver), so you can mirror everything under `/` *and*
  route one folder somewhere special.
- **Still presence-driven:** "all folders" means every folder that *contains* dashboards; a
  genuinely empty Grafana folder has nothing to mirror.
- **The root marker is reserved.** `/` in the picker stores a reserved root sentinel (not a
  real `folderUid`); the pull treats it as "no-folder dashboards, and — if cascade — the
  whole tree beneath."

> **Dr K, eyes lighting up:** *"Map the top to the top, flip the switch, and the whole
> Grafana shows up in Nextcloud folder-for-folder, dashboard-for-dashboard. That's the plate
> that makes the king put his fork down. Build toward that."*

### The decision forks — where I need Dr K at the pass

Some forks Dr K has already called (banked above ✅). These remain open for us to refine
through the saga before the destructive Courses are cooked:

| # | Fork | Options | Status |
|---|---|---|---|
| A | **Metadata key set** | 1:1 `grafana_*` rename **+ bank `grafana_folderUid` + `grafana_apiVersion`** | ✅ **called** — per table above |
| B | **Shared-module key naming** | per-app keys now (isolation-first) vs. shared discriminated keys later | ✅ **called** — per-app now, revisit at shared-module time |
| C | **Cascade / subfolders** | per-mapping **"Sync subfolders" checkbox**; **lazy, presence-driven mirror** (a subfolder appears on the far side only once it holds a dashboard); **no** hidden mappings, **no** "two kinds"; subtree stays under the top-level mapping, per-file `grafana_folderUid` records the nesting; deletes use the normal NC-trash gate | ✅ **re-called** — supersedes the earlier hidden-child-mappings sketch |
| D | **Move-out of a mapping: what happens to the *live* Grafana dashboard?** | (i) leave it live + just unmap the file; (ii) remove it from Grafana and rely on the file's JSON to restore on move-back-in | 🔴 **open** — the archive-verb substitution. (i) is honest + lossless but leaves an orphan live dashboard; (ii) matches the master's "it disappears from Grafana when unmapped" feel but leans 100% on our JSON + upsert-by-uid. |
| E | **Delete gate mechanics** | NC-trash-as-gate: trash = recoverable (no Grafana call), **purge-from-trash = the one Grafana `DELETE`**, restore = re-upsert | 🟡 **leaning** — needs the trashbin-DAV listener proven on the pod (the master's `@todo` purge leg is unproven here too) |
| F | **`ignored` mode without an archive verb** | the master archives an ignored dashboard; we can't — so `ignored` just means "skip it in sync," dashboard left fully live | 🟡 **leaning** — simplest honest behaviour |
| G | **Mapping binds a folder, not a tag** | the feature specs still say "Grafana **tag**" (verbatim from the master); Ch1 already made mappings bind real **folders** | 🔴 **open wording fix** — reword specs to folders (this round's feature edits) |

### Round-2 ticket — the commitment

**Cook (real code, two cooks):**
1. **Claude:** `FilenameCodec`, `SyncGuard`, `ManagedFile`, `DashboardMetadata` (with the
   decided key set incl. `grafana_folderUid` + `grafana_apiVersion` registered but only
   *written* by later courses), `RegisterMimetype`, + unit tests.
2. **Me:** `GrafanaClient` list/read, `StorageService` + folder resolver, `SyncService`
   pull (reconcile-by-uid + prune), `SyncController` + `occ …:sync pull`, wire **Sync from
   Grafana** live.

**Document (driving-truth, no code):**
3. The **no-archive move/delete model** (file-is-the-backup + NC-trash-as-gate) and the
   **subfolder/cascade** model, written into the feature specs so they stop describing the
   master's archive verb.

**The PR ships when:** a mapped folder fills with real dashboards on **Sync from Grafana**
(and `occ`), a second pull changes nothing, the foundation files are unit-tested green,
the high-value feature specs (`move`, `delete`, `file-type`, `reserved-tags`, `purge`)
read against **our** ingredient (folders not tags, no archive verb, NC-trash gate), CI is
green, and it's **smoke-tested in the live pod**. Destructive verbs stay `@todo` in the
specs — designed, not wired.

> **Dr K, chalk down:** *"Two cooks, one ticket, and you re-plated the dangerous course
> before you seared it. Pull the protein down clean, write the delete recipe so the next
> hand can't lose a guest's data, and leave the knife on the board until I say cut. Fire
> Round 2."*

---

## The mise-en-place log — measured vs. assumed (carried from Chapter 1)

- **Measured & banked:** reachability (`cloud`→`observe`), bearer-token auth, the full
  parity map, the live folder picker, mapping CRUD round-trip, admin connection panel.
- **To prove as we cook:** lossless v2/YAML round-trip on write (Course 6 / risk #1);
  `Editor` role covers delete + folder management (risk #4); content-hash loop guard
  survives Grafana's per-save `version` bump (Course 3 / risk #6).
- **Measured in Round 2 (banked):** **a Grafana delete is permanent from our SA's
  seat** — `DELETE /api/dashboards/uid/{uid}` → instant `404`; app-platform trash
  (`grafana.app/trash=true`) → 0 items even with `restoreDashboards` on;
  `/api/search?deleted=true` → 401 to our token. So the archive-verb substitution
  (risk #2) is **not** "find Grafana's soft-delete" — there isn't one — it's **the file
  is the backup + the NC trashbin is the reversible gate.**

### Progress log (Chapter 2)
- ✅ **Chapter 1 stamped complete;** ending + transition written.
- ✅ **Full menu planned** — six courses + dessert, every parity dish mapped, the
  Webhook card explicitly left off, Round 1 committed to (**Course 1 — The Dining Room**).
- 🍳 **Course 1 cooking (PR: finalize the admin section).** Ported the master's
  **Sync Settings** card (push timing + scheduled pull — declarative, `data_sync`,
  near-verbatim from n8n's `AutoSyncSettings`), **wired Groups + Team Folder to
  persist** on every mapping (the `Mapping` model gained `nc_groups` + `use_team_folder`
  mirroring n8n exactly, so the two reduce cleanly into a shared base later), and
  **completed the Sync Actions panel** to the master's full layout — Sync to/from
  Grafana + Purge, rendered disabled until the engine lands, beside the live Test
  connection. One decision changed from the plan: **`admin_test.php` stays** — the
  master keeps it as the dead auth-target template too, so removing it would *diverge*
  from parity. Proven in the **live Nextcloud pod**: `php -l` clean, mapping
  groups/team-folder round-trip through `occ`, schedule config persists via
  `config:app:set`; JS build + eslint green.
- ⏭ *Next: PR review loop (address valid review-bot comments), then Course 2 — the
  Main Protein (pull). Chapter 2 stays open until Dr K calls it.*

- 🍳 **Round 2 cooking (this PR: the pull + the re-plated delete/move model).**
  Cut the ticket smaller and put **two cooks on one PR**: Claude on the pure
  foundation (`FilenameCodec`, `SyncGuard`, `ManagedFile`, `DashboardMetadata` +
  mimetype migration, all unit-tested), me on the Grafana-facing spine
  (`GrafanaClient` list/read, `StorageService` + folder resolver, `SyncService`
  pull, `SyncController` + `occ …:sync pull`, wiring **Sync from Grafana** live).
  **Proved in the walk-in that a Grafana delete is permanent** (no soft-delete our
  SA can reach), so the master's archive-verb move/delete recipe **does not
  translate** — re-plated to **the file is the backup + NC trashbin is the single
  reversible gate**, written as driving-truth into the feature specs (not yet
  wired). **Metadata scrutinised, not 1:1 ported:** five `n8n_*` keys → `grafana_*`
  (isolation is free — NC metadata keys are a flat string-keyed namespace, so no
  cross-app query bleed), **plus two banked-now Grafana-only keys**
  `grafana_folderUid` + `grafana_apiVersion`; the *shared-module neutral-key*
  question is explicitly deferred. **Subfolders designed** — *(this first sketch was
  hidden child mappings matched by name + "two kinds" + blocked subfolder deletes;
  **superseded the same round** by the lazy presence-driven mirror — see the next
  progress bullet and the revised "Subfolders" section)*. Dr K has called forks A/B/C;
  D (move-out → live dashboard?), E (delete gate mechanics), F (`ignored` without
  archive), G (specs say "tag", must say "folder") stay open.
- ✅ **Subfolder model re-decided (Dr K + Claude, other cook napping).** Retired the
  "hidden child mappings / two kinds of subfolder" sketch in favour of a **lazy,
  presence-driven mirror**: one per-mapping **"Sync subfolders" checkbox**, and a single
  rule — *a subfolder exists on the far side exactly when it holds a dashboard* (drop a
  dashboard into a NC subfolder → the Grafana subfolder magically appears and the dashboard
  re-parents into it; empty subfolders mirror nothing). No hidden mappings, no manual
  trigger tag; the subtree stays under the top-level mapping with per-file `grafana_folderUid`
  recording the nesting; deletes use the normal NC-trash gate (no special subfolder block).
  Fork C **re-called**. Feature specs (`move`, `admin-mapping`, `mapping-membership`)
  rewritten to this model.
- ⏭ *Next: finish Round 2's pull code + spec rewordings, smoke-test in the pod, PR
  review loop. Destructive verbs stay designed-not-wired until Dr K calls the forks.
  Chapter 2 stays open until Dr K calls it.*

---

> **Dr K, holding the door to the dining room:** *"Prep got you here. Service is what
> they remember. Send it hot, send it whole, and don't let anything leave the pass
> half-plated. The king's seated. Cook."*

---

Sources / cross-links:
- [`nextcloud-n8n` README](https://github.com/kubed-io/nextcloud-n8n) — the master's full menu (the parity spec).
- [`nextcloud-n8n` saga, Chapter 5 — The Marquee and the Meal](https://github.com/kubed-io/nextcloud-n8n/blob/main/saga/Chapter_5_The_Marquee_and_the_Meal.md) — the other side of the cameo; the store, the review bot, the shared connection-UX fix.
- [Chapter 1 — Mise en Place](Chapter_1_Mise_en_Place.md) — the prep this service is built on.
- The `features/` Gherkin specs — the executable requirements each course must make pass.
