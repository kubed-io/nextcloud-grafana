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

### Dashboard **tags** — the ingredient we hadn't tasted, and bidirectional tag sync

We had mapped Grafana's **folders** (the placement axis) but never looked at its **tags**
(the labelling axis). A dashboard is not fully synced if its tags aren't — so we went back
into the walk-in and tasted how Grafana actually holds tags, live, through the pod.

**What Grafana tags *are* (measured, not assumed):**

- **They live *inside* the dashboard object** — `dashboard.tags: ["dns","external-dns"]`
  — a flat list of free-text strings. They are **not** a separate resource: there is no
  create-tag / delete-tag / tag-id API the way n8n has `/api/v1/tags` with opaque ids.
  A tag exists exactly when some dashboard carries the string; it vanishes when the last
  dashboard drops it. (Confirmed: `dashboard.tags = ["cert-manager"]` on the full object;
  the search row echoes the same list.)
- **No `labels`, no separate metadata map.** We checked — the dashboard object has **no**
  `labels` key and `meta.*` carries only server-computed placement/permission fields
  (folderUid, version, canEdit, …), never user tags. So **`dashboard.tags` is the one and
  only user-labelling surface.** (Grafana's newer app-platform `/apis/` layer *does* add
  Kubernetes-style `metadata.labels`, but the classic dashboard object — the one our JSON
  cut serialises — expresses user labels solely as `dashboard.tags`.)
- **Read-only aggregate + AND-filter search.** `GET /api/dashboards/tags` returns
  `[{term,count}, …]` across the instance (read-only, derived). `GET /api/search?tag=a&tag=b`
  AND-matches — a dashboard must carry **all** listed tags (verified: `tag=dns&tag=external-dns`
  → only *External DNS*). **Folders do NOT have tags** (folder objects are just
  `{id,title,uid}`) — tags are a dashboard-only axis.
- **Writing a tag = writing the dashboard.** Because tags live in the object, you change
  them by upserting the dashboard through `POST /api/dashboards/db` with the edited
  `tags` array. There is no side-channel — the tag write rides the same upsert as any
  content edit. **This makes tags trivially part of `sync` mode already:** `encodeSync()`
  serialises the whole object, so a pulled `sync` file *already carries the Grafana tags on
  disk*, and a push already round-trips them. **What's missing is the Nextcloud-native
  half.**

**The gap — and why "sync without tags isn't a full sync."** Nextcloud has its **own**
first-class labelling system: **collaborative system tags** (the coloured pills in Files,
`OCP\SystemTag\ISystemTagManager` / `ISystemTagObjectMapper`, searchable via DAV REPORT).
Today the app uses NC systemtags *only* as an internal control plane — `grafana:sync` /
`grafana:link` mode pills and the `grafana:ignore` exclude. **A guest's actual dashboard
tags (`dns`, `linux`, `media`, …) never surface as NC tags.** So a user browsing the
mirror in Files can't filter "show me every `linux` dashboard" the Nextcloud-native way,
and can't *re-tag* a dashboard by adding an NC pill and have it reach Grafana. The object's
label axis is stored-but-invisible on the NC side and one-directional. That's a real
seam: **we mirror the dashboard's body and folder, but not its labels as labels.**

**The dish — bidirectional dashboard-tag sync (spec, not yet cooked).** Make the two label
sets *the same set*, minus our reserved control tags, in both directions:

1. **The rule of equality.** After a reconcile, a managed dashboard's **Grafana
   `dashboard.tags`** and its Nextcloud **system tags** hold **the same strings**, with
   exactly one exclusion: the app's **reserved namespace** (`grafana:*` — `grafana:sync`,
   `grafana:link`, `grafana:ignore`, and any future control tag). Reserved tags are the
   app's control plane and are **never** pushed into Grafana's `tags`, and Grafana tags
   that happen to collide with the reserved namespace are **never** imported as content
   (defensive — a user could hand-type `grafana:sync` as a Grafana tag; we ignore it as
   content so it can't masquerade as a mode pill).
2. **Pull (Grafana → NC).** On reconcile, read `dashboard.tags`, strip the reserved
   namespace, and reconcile the file's **NC system tags** to exactly that set: add missing
   ones (creating the systemtag if absent, via `ISystemTagManager`), remove NC content tags
   no longer present in Grafana — **without ever touching the reserved mode/ignore pills.**
   The tags also remain on disk inside the JSON (that's automatic in `sync` mode), so the
   file stays a complete backup.
3. **Tag pull is mode-independent — `link` files get NC tags too.** The whole point of the
   NC systemtag half is **searchability**: browsing the mirror in Files, a guest can filter
   "every `linux` dashboard" the Nextcloud-native way. That value must not depend on mode.
   So the **pull-side systemtag reconcile (point 2) runs for `link` mappings exactly as for
   `sync`** — a `link` file's body is only a tiny pointer, but its **NC system tags still
   mirror the live Grafana tags**, so the mirror is *as searchable as the origin app*
   regardless of whether the body is the full spec or a reference. This is the one place a
   `link` file gets more than a pointer: a pointer body, but real, searchable NC tags. (The
   pointer JSON also carries a `tags` array for the human reader — see `encodeReference` —
   but the **NC system tags** are what make it filterable, and those are reconciled on every
   pull.) A `link` file is still **never pushed** (point 4 push is `sync`-only), so its tags
   flow **one way only, Grafana → NC** — which is exactly right for a read-only pointer.
4. **Push (NC → Grafana).** On push (**`sync` mode only** — a `link` file never pushes),
   read the file's NC system tags, strip the reserved namespace, and write that set into
   `dashboard.tags` before the `POST /api/dashboards/db` upsert. A user adding a `linux`
   pill in Files, then pushing, lands `linux` in Grafana's tag list. (In `sync` mode the
   file's own JSON `tags` array and its NC pills must be reconciled to agree first — see the
   conflict rule.)
5. **The conflict rule (two-way, direction-of-truth per reconcile).** Tags are part of the
   object, so they obey the **same** direction-of-truth the body already does: a **pull**
   makes Grafana authoritative for that file's tags (both `sync` and `link`); a **push**
   (only ever `sync`) makes Nextcloud authoritative. There is no separate tag-merge policy —
   tags travel *with* the body, which is the whole point ("truly syncing the object"). The
   only always-on invariant is the reserved-namespace exclusion.
6. **The loop guard already covers it.** Because tags are inside the spec, they're inside
   the bytes `grafana_syncedHash` hashes — so a tag-only change is a real change the hash
   catches, and a no-op reconcile stays a no-op. No new guard needed; the tag set is not a
   side-channel. (For `link` files, whose body is a pointer, the systemtag reconcile is
   idempotent against the live Grafana tag list — re-pulling an unchanged dashboard adds and
   removes nothing.)
7. **Reserved namespace is the seam that makes it safe.** The `grafana:` prefix on our
   control tags is what lets content tags and control tags coexist in the *same* NC
   systemtag space without collision. This is why the mode pills were named `grafana:sync`
   / `grafana:link` and not bare `sync` / `link` — a decision that now pays off: the
   filter that separates "the user's labels" from "the app's controls" is a single
   prefix test.

**The three edit surfaces — the object body is the third.** The user sharpened this: *tags
live inside the object we're mapping*, so a `sync` file's on-disk JSON **already has a
`tags` array**, and editing that array in a text editor is a first-class way to change the
tags — just like editing a panel. That means the tags exist in **three** editable places,
and a full sync keeps all three equal (minus the reserved namespace):

| # | Surface | Where | Edited by | Authority |
|---|---|---|---|---|
| 1 | **Source tags** | Grafana `dashboard.tags` (inside the object) | the Grafana UI / API | wins on **pull** |
| 2 | **File-body tags** | the `tags` array inside the `.grafana.json` file | opening the JSON in an editor / desktop client | the object's own truth in Nextcloud |
| 3 | **NC system tags** | the coloured pills in Files (`ISystemTagManager`) | the Files UI, DAV | the searchable projection |

The subtlety the user is naming: **two of the three surfaces live inside Nextcloud** — the
file-body `tags` array (2) and the system-tag pills (3) — and they can drift from each other
*without ever touching Grafana*. So before we even talk to the source, Nextcloud must keep
its **own** two representations in agreement. The model:

- **The file body is the canonical object; the pills are its projection.** In `sync` mode
  the file *is* the dashboard, so its `tags` array is the Nextcloud-side source of truth for
  the object. The NC system-tag pills are a **searchable projection** kept equal to the body
  by listeners — so the two never silently disagree.
- **Edit the pills → the body follows → the source follows.** Adding/removing an NC pill
  updates the file body's `tags` array (a guarded write, so it doesn't loop), which — being
  a body change — is a normal push candidate that carries the new tag set to Grafana on the
  next push. This is the "edit Nextcloud tags, it syncs back to source" path, routed
  *through* the object so there's only ever one push mechanism (the body upsert), never a
  side-channel.
- **Edit the body JSON → the pills follow → the source follows.** Saving the file with an
  edited `tags` array updates the pills to match (same guarded listener, other direction)
  and pushes the body to Grafana. Editing tags in the JSON and editing them as pills are two
  doors into the same room.
- **Edit the source → pull updates both.** A pull writes Grafana's tags into the body
  (automatic in `sync` mode — it's the whole object) **and** reconciles the pills to match.
  Both NC representations converge on the source.
- **`link` mode has only surfaces 1 and 3.** A `link` file's body is a pointer, not the
  object, so there is no editable body-`tags` surface to keep canonical — the pills (3) are
  a **read-only projection of the source** (1), reconciled on pull, never pushed. This is
  exactly why link-mode tags flow one way (Grafana → NC): with no canonical body to push,
  there's nothing to send back. (The pointer JSON still lists tags for the human reader, but
  those are regenerated from the source on every pull, not an edit surface.)

So "three-way" is precise: **source ↔ body ↔ pills**, with the body as the hinge. Two of the
arrows (body ↔ pills) are internal to Nextcloud and kept tight by listeners; the third
(body ↔ source) is the existing pull/push spine, which already moves the body — tags simply
ride inside it (Grafana) or alongside it via the tags endpoint (n8n — see the parity note).

**The provenance problem — a *new* tag from Nextcloud vs. a *new* tag from Grafana.** The
rules above say "a pull makes Grafana authoritative, a push makes Nextcloud authoritative,"
and for a **manual, single-direction** reconcile that is complete and honest. But the moment
tags drift on **both** sides between reconciles, "make them the same set" is ambiguous — and
the ambiguity is exactly the one the user named: **when the two tag sets differ on a string,
was it *added* on one side or *removed* on the other?** You cannot tell from the two current
sets alone. Consider a dashboard that last synced with `{linux}`:

- NC now has `{linux, urgent}`, Grafana has `{linux}` → did the user **add** `urgent` in
  Files (should push to Grafana), or did someone **remove** `urgent` in Grafana (should
  strip from NC)? Both produce the exact same pair of current sets.
- NC has `{linux}`, Grafana has `{linux, prod}` → did Grafana **gain** `prod` (pull it into
  NC), or did NC **drop** `prod` (push the removal to Grafana)? Again indistinguishable from
  the current sets.

**A two-way merge needs a baseline** — the tag set *as of the last successful sync* — to
turn "the sets differ" into "who changed what." This is the same three-way-merge insight the
body already leans on (`grafana_syncedHash` is the body's baseline); tags need the analogous
baseline. Resolution:

- **Bank a new metadata key `grafana_syncedTags`** — the reserved-stripped tag set we last
  reconciled, stored on the file (comma-joined, sorted, alongside `grafana_syncedHash`).
  Registered like the other banked keys; **written by the tag course, not this round.** With
  it, each side's delta is computable: `nc_added = NC − baseline`, `nc_removed = baseline −
  NC`, `g_added = Grafana − baseline`, `g_removed = baseline − Grafana`. The merged result is
  `baseline ∪ (adds from both sides) − (removes from both sides)` — a true union-of-adds,
  intersection-of-keeps three-way merge, so **a new tag from *either* side is additive and a
  removal from *either* side propagates**, with no side clobbering the other's untouched
  tags.
- **The genuine conflict** — the same tag added on one side and removed on the other since
  baseline — is the only case the merge can't auto-resolve. It resolves by the **reconcile's
  direction of truth** (pull → Grafana wins that tag, push → NC wins), so behaviour stays
  predictable and matches the body. This keeps the simple manual flows (pull-only, push-only)
  behaving exactly as points 2–5 describe, and *only* the two-sided-drift case consults the
  baseline.
- **Origin, not authorship.** We deliberately track the *baseline set*, **not** a per-tag
  "created in NC" / "created in Grafana" author stamp. Neither system records tag authorship,
  and a per-tag origin flag would rot the instant a user re-adds a tag the other side also
  has. The last-synced baseline is the minimal, honest state that answers the add-vs-remove
  question without inventing provenance the backends don't keep.
- **The n8n dimension is the same shape.** For the n8n sibling the identical baseline
  (`n8n_syncedTags`) answers the identical question; the only backend difference is that n8n
  tags are id'd resources (`ensureTag` to resolve a name→id before `PUT …/tags`), whereas
  Grafana tags are bare strings inside the object. The three-way-merge logic — baseline,
  deltas, direction-of-truth on conflict — is **backend-agnostic** and belongs in the shared
  module. **This is the tag-provenance note to carry into the n8n saga too** (below).

**Why this matters beyond parity — and a note back to the master.** **n8n has this same
gap and hasn't closed it either.** n8n workflows carry real tags (`/api/v1/tags`, opaque
ids, `PUT …/tags` to set them), and the master app uses them only as the *mapping key* +
reserved control tags — it does **not** reconcile a workflow's content tags into NC system
tags bidirectionally. So this is a genuinely new capability for **both** extensions, and a
prime candidate for the eventual shared module: *"reconcile a backend object's label set
with Nextcloud system tags, minus a reserved control namespace"* is backend-agnostic. The
per-backend differences are three, all small and injectable: (a) **where tags live / how
they're written** — inside the object for Grafana (write = upsert the dashboard), a separate
id'd resource for n8n (write = `ensureTag` name→id then `PUT …/tags`, and n8n's body PUT
*drops* tags entirely); (b) **the reserved prefix** (`grafana:` vs `n8n:`); and (c) **a
protected-tags set** — tags the pill-sync may show but must not push a *removal* for. For
Grafana that set is **empty** (a content tag is never load-bearing); for n8n it's the
**mapping tags** (n8n binds a folder *by tag*, so removing the mapping pill would unmap the
workflow — a hazard Grafana's folder-mapping simply doesn't have). The NC-side half — the
body↔pills projection, systemtag reconcile, reserved-namespace filter, baseline three-way
merge, direction-of-truth — is **identical**. **No commits to the n8n repo for the design;
this is a saga note there too (now written up in `nextcloud-n8n` Chapter 5 §5.6) — see the
cross-note below.**

> **Dr K, turning a dashboard over in his hands:** *"You plated the body and the folder but
> left the labels on the cutting board. A dish isn't sent without its garnish. Tags live
> inside the object here — good, that means they ride the same upsert, no new envelope. So
> make the two label sets *one* set, keep our own `grafana:` pills out of the guest's
> garnish, and let the direction of the sync decide who's right — same as the body. And
> here's the sharp bit: **even a `link` pointer gets the real Nextcloud tags** — a pointer
> body, but a fully *searchable* one, so the mirror filters like the origin no matter the
> mode. Now the part the guest just taught us: the tags live in **three** places — in
> Grafana, in the file's own JSON, and on the Nextcloud pills — and a guest might reach for
> any of the three. So make the **file the hinge**: the pills follow the file, the file goes
> to Grafana, and it all closes the loop no matter which door they opened. And when a tag
> shows up on one side and not the other, you can't guess whether it was **born there or died
> on the other side** — so you keep a little note of what the tags were last time you all
> agreed. That note is what tells an *add* from a *remove*. Tell the n8n line: they've got the
> same three doors and the same fix. This is shared-module bait."*

#### Finalized in the sibling — the reactive engine, and what Grafana imports vs. adapts *(update)*

The n8n line didn't just take the note — they **cooked the whole dish**. As of `nextcloud-n8n`
Chapter 5 §5.6 the tag-reconcile engine is built, unit-tested, and live in CI, and it hardens
the design above in ways we import wholesale. When that PR merges it is our **base**; this is
what lands and how our ingredient bends it.

**The engine (backend-agnostic, ported as-is):**
- **`TagMerge`** — the pure three-way merge (baseline + both sides → merged set; reserved-
  stripped, deduped, sorted). No I/O, no backend — the exact function §620–643 specced. Ours
  verbatim.
- **`TagSyncService` / `TagReconcileService`** — drive the pull mirror (source → pills, `sync`
  **and** `link`), the push (pills → source, `sync` only), and the baseline write
  (`*_syncedTags`). The seams we inject: **where tags live / how written**, the **reserved
  prefix**, and the **protected-tags set**.
- **`n8n_syncedTags` → `grafana_syncedTags`** — the banked baseline key, registered beside the
  other managed keys (our metadata contract already reserves this slot, §624).

**The part that's genuinely new to the spec above — reactivity (n8n "Slice A", live):**
- **No button for tags.** A content-pill add/remove on a managed `sync` file is caught by a
  dedicated **`ContentTagListener`** (`TagAssignedEvent`/`TagUnassignedEvent` for *content*
  tags, distinct from the reserved `grafana:ignore` the mode listener watches) and reconciled
  to the source **on its own** — no "Sync to Grafana" click. It honours the **same `timing`
  knob** as the body writeback: `sync` reconciles inline, `async` enqueues a per-file
  **`ReconcileTagsJob`** the cron worker runs next tick. This upgrades §579's "edit the pills →
  the body follows → the *next push* carries it" to **edit the pills → it propagates itself**.
- **Slices.** n8n shipped **A** (pill → backend reactive) and left **B** (the body↔pills
  projection — a pill edit also silently rewrites the file-body `tags` array, and a hand-edit
  of that array updates the pills + pushes) plus **pull change-detection**, the **reactive
  eject**, and the **optional catalog sweep** as `@todo`. Our feature file mirrors that slicing.

**What changes for Grafana (the three injected knobs, all simpler here):**
1. **Tags are body-native, so there is no tags-only side-channel.** n8n's reactive pill push
   uses a **decoupled** tag path (`setWorkflowTags` → `PUT /workflows/{id}/tags`) and must
   *re-stamp `n8n_syncedHash`* on the silent body-tags write so it isn't mistaken for a body
   edit and re-pushed. Grafana has **no such endpoint**: `dashboard.tags` *is* part of the
   object, so a pill edit updates the body's `tags` and rides the **existing body upsert** —
   the one push mechanism we already have. There's nothing to decouple and no re-stamp dance;
   the loop guard is the `SyncGuard` + `grafana_syncedHash` we already ship (a genuine tag
   change *is* a genuine body change — which is correct, not a hazard).
2. **The protected-tags set is empty.** n8n's sharpest hazard — the **mapping tag is a content
   tag** (n8n maps a folder *by tag*, so dropping that pill would unbind + prune) — **does not
   exist for us.** We map by *real Grafana folders*, so no content tag is ever load-bearing:
   the whole "force-keep the mapping pill / eject-via-`ignore` / union-of-mapping-tags across
   mirrors" apparatus (a big slice of n8n's feature file) **evaporates**. Our protected set is
   `[]`. (The n8n README says it outright: *"Grafana Sync, which maps by real folders, has no
   such caveat."*)
3. **Pull change-detection is a branch shorter.** Because our tags live *in* the body, a
   Grafana-side tag change is a **body** change `grafana_syncedHash` already catches — n8n's
   separate "tags-only changed in the source" branch (needed because its tags sit *outside* the
   body) **collapses** for us into the ordinary body-changed path. Our detection is just
   *skip-if-unchanged* vs *body-changed → write + reconcile pills*.

**Pruning (imported, with one Grafana subtraction):** assignment **edges** are swept both ways
(remove-on-either-side drops the edge, so the mirror never keeps a tag the canonical side let
go); catalog **definitions** are **not** auto-pruned (a system tag may be pinned on unrelated
files); reconcile is **prune-free by construction** (compute the merged set first, write once,
never mint a pill we're about to drop); and an **optional, opt-in `occ` sweep** (dry-run first,
never on the hot path) can GC definitions orphaned *on both sides at once*. Grafana subtraction:
Grafana has **no tag catalog** — a tag exists only as a string on some dashboard and vanishes
when the last one drops it — so there are **no Grafana-side definitions to sweep**; the sweep is
an **NC-side-only** courtesy here.

**Scope (imported):** tag sync is a **mapped-folder feature**. An `unmapped` or `ignored` file
is a plain Nextcloud file — its pills are ordinary system tags with **no Grafana side effect** —
so the `ContentTagListener` and the reconcile must no-op on it. (This is why the mode machine —
Course 4 — lands *before* we cook tags: `unmapped`/`ignored` are its states.)

> **Dr K, reading the sibling's ticket:** *"They didn't just take the note — they built the
> line. Good. So we don't re-derive it; we plate it on our ingredient. And ours is the *easier*
> cook: our tags live in the object, so a pill edit just rides the same pan the body does — no
> second burner, no re-stamp sleight of hand. And the guest can't hand us the one knife that
> cuts n8n — the mapping tag — because we plate on real folders. Empty protected set, one push
> path, one fewer branch. Wait for their pan to come off the heat, then pour it over our
> protein. But not tonight: the file *lifecycle* (Course 4) sets the table this dish is served
> on."*

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
| H | **Bidirectional dashboard-tag sync (three surfaces)** | keep **source `dashboard.tags` ↔ file-body `tags` array ↔ NC system tags** one set, minus the reserved `grafana:*` namespace; the **file body is the canonical object** and the pills are a listener-kept projection (edit either NC surface → the other follows → push); **pull reconciles NC tags for BOTH `sync` and `link`** (searchability is mode-independent), **push writes tags for `sync` only**; two-sided drift resolved by a **three-way merge against a banked `grafana_syncedTags` baseline** (add-vs-remove provenance), genuine conflicts fall to the reconcile's direction-of-truth (pull → Grafana, push → NC) | 🟡 **leaning** — Grafana tags measured (live inside `dashboard.tags`, no `labels`, no tag-id API; write = upsert the dashboard). Needs new banked key `grafana_syncedTags` + a body↔pills mirror listener. New capability for **both** extensions → shared-module bait. Course TBD (rides the pull/push spine). |
| H | **Reserved tags — two origins, don't conflate** | a Nextcloud-origin tag (on the *file*) vs a Grafana-origin tag (on the *dashboard*) are different systems: `grafana:ignore` = NC file tag (app namespace) read on tag events → `ignored` mode; `nextcloud:ignore` = Grafana dashboard tag (`nextcloud:` = addressed-to-NC) read at pull → never pulled. Rule: **tag with the name of the system you're talking to.** | 🟡 **called** — split the two in `reserved-tags.feature`; symmetric with n8n (`n8n:ignore` on the file, `nextcloud:ignore` on the workflow) so the shared base gets one two-axis model |

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
- ✅ **Round 2 landed — PR #4 merged.** The **metadata contract + foundation** (Claude's
  station: `DashboardMetadata`, `ManagedFile`, `FilenameCodec`, `SyncGuard`,
  `DashboardBody` — all unit-tested, incl. the loop-guard hash + `link↔reference` wire)
  and the **Grafana read surface** (`GrafanaClient.listDashboards`/`readDashboard`/
  `deepLink`, folder-scoped + root-`general` aware). Metadata keys register on boot; the
  seam is committed + tested. Review loop run clean (15/15 threads, incl. a real
  `ArgumentCountError` the bot caught). Live pod updated; **`observe` (team folder +
  groups) + `nxt-fun` (non-team) mappings staged** as the smoke test — config lives in
  appconfig, survives every code-only deploy.
- ✅ **Round 3 cooked — PR #5 (the pull works, folders provision).** The whole flat,
  classic-JSON pull, end to end and live. Dishes plated: **`RegisterMimetype` /
  `UnregisterMimetype`** (application/grafana+json + a folder-bars icon, install/upgrade
  repair-step, clean uninstall) · **`TeamFolderService` + `StorageService`** (provision a
  team folder shared to its groups, or an admin-owned folder — ported 1:1 from the master,
  `teamFolder`→`ncFolder`) · **`SyncService` (pull)** (reconcile-by-uid: update in place,
  collision-suffix fresh writes, prune the departed, SyncGuard-wrapped, filecache mimetype
  fixup) · **`OwnershipTags`** (grafana:sync/link/unmapped pills) · **`SyncController` POST
  /sync/pull + `occ grafana_sync:sync pull` + the enabled "Sync from Grafana" button**
  (js/sync-settings.js). **Live smoke test PASSED** on the pod: `observe` (team folder, 6
  dashboards) + `nxt-fun` (admin-owned, 1) materialized as `.grafana.json` with the full
  metadata contract (uid/mode/version/hash/mapping) + `grafana:sync` pill + correct
  mimetype; second pull idempotent (7 files, 0 dupes, 0 pruned); RegisterMimetype verified
  (icon in core, alias in mimetypelist.js). **reconcile.feature** pull scenarios off `@todo`
  and runnable (SyncSteps over occ + WebDAV, admin-owned so CI needs no groupfolders).
  Push + the whole-instance root mirror stay `@todo` for their courses.
- ✅ **Round 3 review loop closed — PR #5 merged.** 34/34 review threads triaged/resolved
  (Psalm infra suppressions ported from the master, redundant casts, unused logger, error
  curation via `describeConnectionError`, robust JS fetch, saga filename fix) + the unit
  tests the bot asked for (`SyncServiceTest`, `OwnershipTagsTest`). CI green across the board.
- ⏭ *Now cooking: **Round 4 — The Sauce** (Course 3, push: NC edits → Grafana, loop-guarded)
  — see the section below. Chapter 2 stays open until Dr K calls it.*

---

## Round 3 — The Protein Plated *(this PR: the pull works, folders provision)*

> Round 2 built the substrate and the read surface. **Round 3 lights the burner and
> plates the protein.** Be bold: this PR is the whole pull, end to end — the button
> stops being disabled and starts moving food. Click **Sync from Grafana** (or `occ`)
> and a mapped folder is *created* (a real team folder shared to its groups, or a plain
> admin folder) and *fills* with the live dashboards as `.grafana.json` files —
> uid-stamped, mode-tagged, mimetyped — and a second pull changes nothing. This is the
> plate that makes the staged `observe` + `nxt-fun` mappings finally *materialize*.

### The ticket — several interrelated dishes, one PR (be bold)

1. **`RegisterMimetype`** *(finishes the foundation station)* — register
   `application/grafana+json` + a Grafana filetype icon via an idempotent install/upgrade
   repair-step (mirror the master's `RegisterMimetype`), with an uninstall reversal so
   removal leaves core clean (store rule).
2. **Folder provisioning — the engine finally *acts* on the stored team-folder/groups
   config** (the "stored-but-not-yet-acted-on" from Course 1 comes alive):
   - `TeamFolderService` — create an ownerless **groupfolder** at the mapping's
     `nc_folder`, shared to `nc_groups`, plus a dedicated actor group for the app's own
     write access (the master's model). `use_team_folder = false` → a plain folder in the
     admin's files instead.
   - `ensureFolder(mapping)` picks the path from the flag; idempotent.
3. **`StorageService` — the file writer.** `Name.grafana.json` via `FilenameCodec`
   (the uid lives in **metadata**, not the filename — clean names, so a rename never
   breaks the link; collision suffixes only when two dashboards share a title), body
   via `DashboardBody` (sync = full stripped spec, link =
   pointer), stamped through `DashboardMetadata` (uid / mode / version / syncedHash /
   mapping / folderUid), all wrapped in `SyncGuard` so our own writes never bounce back.
4. **`SyncService` (pull) — reconcile-by-uid.** For a mapping: list its Grafana dashboards
   (folder-scoped; `general` for a root mapping), read each, write/update **matched by
   uid** (never duplicate on re-run), **prune** a managed file whose dashboard left the
   folder. **Flat only** (cascade/subfolders deferred), **classic JSON cut only** (v2/YAML
   is Course 6).
5. **Mode tags** — `grafana:sync` / `grafana:link` Nextcloud systemtags mirroring each
   file's mode (the master's `ModeTagListener`), mutually exclusive, app-maintained.
6. **`SyncController` + `occ`** — `POST /apps/grafana_sync/mappings/{id}/sync` (pull),
   `grafana_sync:sync pull [--mapping]`, `grafana_sync:list-dashboards`,
   `grafana_sync:get-dashboard <uid>`; wire the **Sync from Grafana** bulk button + the
   per-mapping sync **live** (off "disabled").
7. **Integration test — the exit gate.** `reconcile.feature`'s "Sync from Grafana pulls
   the folder's dashboards" flips off `@todo` and passes on the ephemeral Grafana + NC
   stack: folder provisioned, files land uid-matched, a second pull is a no-op, an
   unmapped file is untouched.
8. **Live smoke test.** Deploy; click Sync from Grafana; **`observe`** becomes a real team
   folder shared to `admin/admins/devs/friends` filled with the observe dashboards, and
   **`nxt-fun`** a plain folder holding the *Welcome* dashboard.

### Left on the shelf this round (still designed-not-wired)

Push / writeback (Course 3), the move/delete/copy mode machine (Course 4),
cascade/subfolders, the v2/YAML cut (Course 6), and the scheduled-pull background job
(dessert). Round 3 is the **pull, flat, classic-JSON** — but *complete and live*.

**Ships when:** Sync from Grafana provisions + fills a mapped folder (team **and**
non-team), reconcile-by-uid is idempotent, files are mimetyped + mode-tagged +
uid-stamped, the integration test + unit tests + CI are green, and it's smoke-tested
live (the two staged mappings materialize).

> **Dr K, ladle up:** *"You've prepped the protein and read the room. Now sear it and put
> it on the plate — a whole folder of live dashboards, appearing in Nextcloud like they
> were always there. Be bold: one ticket, the whole pull. Fire Round 3."*

---

## Round 4 — The Sauce *(this PR: the writeback — NC edits → Grafana, loop-guarded)*

> Round 3 seared the protein and plated it. **Round 4 spoons the sauce.** Edit a
> `.grafana.json` in the Files app (or over WebDAV, or the desktop client), hit save,
> and the dashboard changes **in Grafana** — same uid, same folder — and *nothing
> loops*. The pull made dashboards flow down; the push makes them flow back up. Together
> they are the spine: a sync folder is now a true two-way mirror **and** a restorable
> backup you can edit.

### The ticket — the whole sauce, one PR (be bold)

The full Course 3, cooked in one confident batch (we have the master's `PushService` /
`NodeWrittenListener` / push-job / `SyncNotifier` cards — the job is ported here as
`PushDashboardJob`):

1. **`GrafanaClient::upsertDashboard()`** *(finishes the adapter)* — `POST /api/dashboards/db`
   through the existing `request()` chokepoint, returning the decoded `{uid, version,
   status}`. The write half of the client the read half (Course 2) already left a seat for.
2. **`PushService`** *(the reduction)* — read a managed **sync** file, decode to `stdClass`,
   build the body with the already-shipped `DashboardBody::toUpsertBody` (forces `id:null`,
   `overwrite:true`, folder placement), upsert, then stamp `grafana_syncedHash = sha1(the
   file bytes we sent)` + the returned `grafana_version`. Folder placement is resolved from
   the file's `grafana_mapping` → the mapping's `grafanaFolderUid` so a push never yanks a
   dashboard out of its folder. Errors are **thrown** (never stamp on failure → next save
   retries), carrying Grafana's own message via `describeConnectionError`.
3. **`NodeWrittenListener`** *(the burner that fires on save)* — on `NodeWrittenEvent` for a
   `.grafana.json`: re-stamp the mimetype (every external write re-detects `.json` →
   `application/json`, clobbering our row icon), then push **only when all hold**: guard
   inactive, file is ours (`grafana_uid` set) + **sync** mode, and the content actually
   changed (`sha1 ≠ grafana_syncedHash`). Inline when `timing=sync`, else enqueue the job.
4. **`PushDashboardJob`** *(async plating)* — the background writeback path, honouring the
   Course-1 `timing` switch (default `async`), re-resolving the node by file id.
5. **`SyncNotifier` + `Notifier`** *(the callback)* — a native NC notification (bell + toast)
   when a background push fails, keyed on the file id so a later success clears it and
   retries collapse onto one entry. A failed save never loses the user's edits.
6. **`SyncService::pushOne/pushAll`** *(the bulk ladle)* — the **Sync to Grafana** button:
   push every sync file under a mapping (or all). `link` mappings are a no-op (a pointer has
   nothing to push).
7. **`SyncController::push` + `occ grafana_sync:sync push [--mapping]` + the live button** — the
   **Sync to Grafana** button stops being disabled and its `occ` twin lands. (Purge stays
   disabled — it's Course 4's delete machine.)
8. **Tests + live smoke.** `PushServiceTest` + `SyncService` push units; the **push scenario
   in `reconcile.feature` off `@todo`** (edit a synced file → assert Grafana's dashboard
   changed, uid intact); deploy + smoke on the pod (edit an `observe` dashboard file, watch
   Grafana update, prove a re-save doesn't loop).

### The one to actually get right (Ch1 risk #6 — the loop)

Grafana bumps `dashboard.version` on **every** save. If we hashed Grafana's echoed-back
object, a push→pull round-trip would look like a change and loop. We don't: the on-disk body
has `version` **stripped** (pull's `encodeSync`, push's `toUpsertBody`), so `sha1(fileBytes)`
is naturally stable across Grafana's bumps. Two guards, layered:
- **`SyncGuard`** (request-scoped) — our own pull writes never trip the save listener.
- **content hash** (`grafana_syncedHash`) — an unchanged or echoed save is skipped; only a
  real user edit pushes.

### Left on the shelf this round (still Course 4+)

The **DAV link-write guard** (refuse writes to a link pointer), **create-from-Nextcloud** (a
brand-new file becomes a dashboard), and the **move / rename / delete / copy** mode machine.
Round 4 is the **update writeback for files we already track** — complete and live.

**Ships when:** editing a synced `.grafana.json` updates its Grafana dashboard (uid + folder
intact), a re-save doesn't loop, **Sync to Grafana** + `occ grafana_sync:sync push` work, the
push integration + unit tests + CI are green, and it's smoke-tested live on the pod.

> **Dr K, tasting the spoon:** *"A protein without sauce is a demo, and a sauce that
> breaks is worse than none. Reduce it slow, guard the loop, and when the king cuts in,
> the plate writes itself back. Be bold — the whole sauce, one pan. Fire Round 4."*

---

## Round 5 — The Sides *(next: the file-lifecycle mode machine — Course 4)*

> The protein and its sauce are plated (pull + push). **Round 5 is the sides** — the moves
> around the dish that make the mirror behave like a real filesystem: create a dashboard by
> making a file, move it out to park it, copy it as a fresh one, rename it, delete it to trash
> and restore it. This is the biggest remaining **parity** gap with the n8n master (its
> `Create`/`Copy`/`Delete`/`Motion`/`ModeChange` services + the move/copy/rename/delete/restore
> listeners), and it is the **prerequisite for tags** — the `unmapped` and `ignored` states this
> course defines are what the tag reconcile scopes itself to.

### Why this is the next leg (not tags)

The bidirectional **tag sync** is designed to the last edge (above) and its engine is being
finalized in the sibling — but it is **spec-only for us until two things are true**: (1) the n8n
tag PR merges (then we import `TagMerge`/`TagSyncService`/`ContentTagListener`/`ReconcileTagsJob`
as the base), and (2) **this course lands**, because tag sync no-ops on `unmapped`/`ignored`
files and those states are born here. So the dependency order is **mode machine → tags**, and
Round 5 is the mode machine.

### The ticket — the mode machine (scope; one PR when we cook it)

Ported from the master, re-cut for "Grafana has real folders and **no archive verb**":

| Dish | Master parity | Kind | Grafana note |
|---|---|---|---|
| **Create from Nextcloud** | `CreateService` / create listener | 🟢 | A new `.grafana.json` in a mapped **sync** folder (new file, upload, move-in) becomes a real dashboard via `POST /api/dashboards/db`, uid-stamped. Outside a mapping → plain untracked file. |
| **Move → `unmapped` / restore** | `MoveGuardListener` / `MotionService` | 🟡 | Move a file **out** of its mapped folder → **unmapped** (NC keeps the full JSON + uid as the backup); move back → re-adopted by uid. `link` can't be ejected this way. |
| **The "archive" substitution** | n8n's `archive` verb | 🔴 | **The load-bearing Grafana decision.** n8n *archives* a workflow on unmap/delete (reversible in n8n). Grafana has **no soft-delete** — reversibility already lives on **our** side (the file + the NC trashbin gate, Round 2). So decide the unmap/delete contract: **leave the Grafana dashboard in place and just drop the NC binding** (default, honest, non-destructive) vs. an app-owned "archive" folder. Resolve at cook time. |
| **Copy = always a new instance** | `CopyListener` / `CopyService` | 🟢 | A copy strips identity (new file, no `grafana_uid`) so it never hijacks the original's dashboard; becomes a fresh dashboard on first push/create. |
| **Rename (three-way)** | `NameSyncListener` | 🟡 | filename stem ⇄ `dashboard.title` ⇄ Grafana title kept in agreement; the **uid** is the stable thread, so a rename never breaks the link. |
| **Delete (mode-aware, two-step trash)** | `DeleteService` / delete + restore listeners | 🟡 | Trash a managed file → drop the binding (no Grafana delete yet — the trashbin is the reversibility gate); empty-trash / purge → **permanent** Grafana delete (no soft-delete to fall back on, so gated hard); restore → re-adopt by uid. Abort if Grafana is unreachable (never desync). |
| **DAV link-write guard** | `LinkWriteGuardPlugin` + DAV registration | 🟢 | A `link` file is a read-only pointer — refuse writes to it over DAV, with the `link_edit_blocked` notice (the `SyncNotifier`/`Notifier` seam we already stubbed for Course 3 fills in here). |

**Exit:** the full mode machine — **sync / link / unmapped / ignored** — behaves like the
master's, adapted for "no archive verb," and CI's `move`/`copy`/`rename`/`delete`/`create`
feature files come off `@todo`. Then, once the sibling's tag PR merges, **tags are the round
after** (import the engine, plate it on our empty-protected-set / body-native ingredient).

> **Dr K, setting the flatware:** *"You've sent the protein and the sauce. Now lay the table —
> the moves a guest makes without thinking: slide a plate over and it's a new dish, take one
> away and it's parked not binned, drop your knife and you can pick it back up. Ours has one
> honest edge the master doesn't: there's no walk-in to archive into, so *our* trash **is** the
> safety net — treat it like one. Set the sides. The garnish (tags) comes once the sibling's
> reduction is off the heat."*

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
