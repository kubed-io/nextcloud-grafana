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

## The mise-en-place log — measured vs. assumed (carried from Chapter 1)

- **Measured & banked:** reachability (`cloud`→`observe`), bearer-token auth, the full
  parity map, the live folder picker, mapping CRUD round-trip, admin connection panel.
- **To prove as we cook:** lossless v2/YAML round-trip on write (Course 6 / risk #1);
  `Editor` role covers delete + folder management (risk #4); content-hash loop guard
  survives Grafana's per-save `version` bump (Course 3 / risk #6); the archive-verb
  substitution for the unmapped/ignored edge (Course 4 / risk #2).

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
