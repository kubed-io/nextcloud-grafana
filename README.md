# Grafana Sync

A Nextcloud app that surfaces Grafana dashboards as native files — browse, edit, and
manage your dashboards right inside the Files app, with folder-to-folder mapping and
(planned) bidirectional sync back to Grafana.

[![🧪 Tests](https://github.com/kubed-io/nextcloud-grafana/actions/workflows/tests.yml/badge.svg)](https://github.com/kubed-io/nextcloud-grafana/actions/workflows/tests.yml)
[![🛡️ Quality](https://github.com/kubed-io/nextcloud-grafana/actions/workflows/quality.yml/badge.svg)](https://github.com/kubed-io/nextcloud-grafana/actions/workflows/quality.yml)
[![🔗 Integration](https://github.com/kubed-io/nextcloud-grafana/actions/workflows/integration.yml/badge.svg)](https://github.com/kubed-io/nextcloud-grafana/actions/workflows/integration.yml)
[![License: AGPL v3](https://img.shields.io/badge/License-AGPL%20v3-blue.svg)](LICENSE)
[![Nextcloud](https://img.shields.io/badge/Nextcloud-30--33-0082c9?logo=nextcloud&logoColor=white)](https://apps.nextcloud.com)
[![PHP](https://img.shields.io/badge/PHP-%E2%89%A58.1-777bb4?logo=php&logoColor=white)](composer.json)

> **Status: active development.** The sync engine is live: mapped folders provision and
> fill with dashboards as `.grafana.json` files (**pull**), editing a synced file pushes
> the dashboard back to Grafana on save (**writeback**), and the full file lifecycle works
> — **create** a dashboard by making a file, **copy** it to fork a new one, **move** it
> between mapped folders to re-parent it in Grafana (uid kept) or out to delete it, and
> **delete/restore** through the Nextcloud trash (with an optional Grafana recycle-bin folder
> that preserves ids). In the Files app, a dashboard row opens straight in Grafana or in a raw
> JSON editor, and the **+ New** menu makes one. A DAV guard keeps a link file's pointer from
> being overwritten, and removing a mapping tears it down safely. On top of that sits the full
> admin surface: point the app at Grafana with a service-account token (live **Test
> connection**), set the sync schedule and recycle-bin behaviour, and define folder mappings —
> all persisted and scriptable over `occ`. Still to come: bidirectional tag sync and the v2/YAML
> dashboard cut. Behaviour is written up front as executable specs under [`features/`](features/),
> so docs, tests, and roadmap stay aligned.

---

## How it works (the design)

Grafana Sync maps a Grafana **folder** to a Nextcloud folder. Every dashboard inside a
mapped Grafana folder appears in the corresponding Nextcloud folder as a `.grafana.json`
file. Depending on the mode you choose, changes you make in Nextcloud push back to
Grafana, and changes made in Grafana pull back into Nextcloud on a schedule.

```
Grafana (folder of dashboards) ⟺ Nextcloud (mapped folder of .grafana.json files)
```

Because **Grafana has real folders**, the mapping is a plain folder-to-folder mirror —
there is no tagging scheme to maintain (this is the main thing that makes it simpler than
the sibling [n8n](https://github.com/kubed-io/nextcloud-n8n) integration, whose API has no
folders). The link between a file and its dashboard is the stable Grafana **uid** stored in
the file's metadata — not the filename — so renaming, moving, and restoring all work
without ever breaking the connection.

### Modes

| Mode | File content | Pushes to Grafana? |
|---|---|---|
| **Sync** | Full dashboard JSON | Yes — bidirectional; the folder doubles as a restorable backup |
| **Link** | Tiny pointer (uid, title, URL) | No — click opens the dashboard in Grafana |

**Link** mode is a natural fit for operator- or GitOps-provisioned dashboards (e.g. via the
Grafana Operator's `GrafanaDashboard` CRD): they're owned elsewhere and should never be
written back — a clickable pointer is all you want.

### Tags — one searchable set *(planned)*

A dashboard's **tags** are part of the object, so a real sync keeps them in step too. Grafana
holds tags *inside* the dashboard (`dashboard.tags: ["dns","linux"]`); Nextcloud has its own
first-class **system tags** (the searchable coloured pills in Files). Grafana Sync keeps the
two the same set, so **the mirror is as searchable as Grafana itself** — filter "every
`linux` dashboard" the Nextcloud-native way.

There's no separate button for tags — a pill edit propagates on its own:

- **Edit in Grafana** → a pull brings the tags onto the Nextcloud file's pills.
- **Edit the file's pills** → adding or removing a pill on a synced dashboard reconciles that
  tag to Grafana **by itself**, following the same *instant* vs *background* timing as the rest
  of the writeback. Removing a tag on either side removes it on the other.

Two rules keep it safe. The app's own control tags (the reserved `grafana:` namespace, e.g.
`grafana:sync`) are **never** mixed into your dashboard's tags in either direction. And because
the app remembers the last-synced set, a change on one side is applied as a true *add* or
*remove* rather than a blind overwrite — even when both sides drifted. Tag sync runs in **both**
`sync` and `link` mappings for searchability; a `link` file is read-only, so its tags flow one
way, Grafana → Nextcloud.

Because Grafana Sync maps by **real folders**, a dashboard's tags are always just labels — there
is no "mapping tag" that can unbind a dashboard if you remove it (the one caveat the tag-based
n8n sibling has, and the reason this is the *simpler* half to cook here).

### Two dashboard "cuts" (classic JSON and the new YAML schema)

Grafana serves a dashboard two ways, and the mapping records which one a folder uses:

- **Classic JSON** (`dashboard.grafana.app/v1beta1` / the `/api/dashboards` model) — the
  familiar dashboard JSON. The default, and what every existing dashboard already is.
- **The v2 schema as YAML** (`dashboard.grafana.app/v2`, the App Platform's k8s-style
  resource) — the modern, GitOps-friendly cut, serialized as YAML (`.grafana.yaml`).

Classic JSON ships first; the v2/YAML cut is an opt-in per mapping.

---

## Features

A high-level showcase of what's live today. Each feature links to its **executable
specification** — a Gherkin `.feature` file under [`features/`](features/) that describes
the exact behaviour in plain language and drives the integration tests — and to the
**code** that implements it. The `.feature` files *are* the requirements.

### Create a dashboard from Nextcloud

Make a `.grafana.json` file in a mapped **sync** folder (new file, upload, or move-in) and
the app registers it as a real Grafana dashboard — placed in the mapped folder and stamped
with its new uid. Author in your editor of choice; it goes live in Grafana without opening
the Grafana UI. A file created **outside** any mapped folder stays a plain, untracked
document. (A file that already carries a uid re-adopts that dashboard rather than making a
duplicate.)

📋 spec: [`features/create-dashboard.feature`](features/create-dashboard.feature) · 🛠 [`lib/Listener/CreateInGrafanaListener.php`](lib/Listener/CreateInGrafanaListener.php), [`lib/Service/CreateService.php`](lib/Service/CreateService.php)

### Mapping membership follows the folder

Folder mappings are **metadata on the folder**, so a file's mapping is resolved by where it
lives. Because mappings are per-folder, you can map a folder **inside** an already-mapped
folder — the nearest enclosing mapping wins.

📋 spec: [`features/mapping-membership.feature`](features/mapping-membership.feature) · 🛠 [`lib/Service/MappingService.php`](lib/Service/MappingService.php)

### Moving a dashboard (real folders, so a move is a real move)

Because Grafana has **real folders**, moving a dashboard file is the one place this app does
*more* than its tag-based n8n sibling — the move mirrors straight through to Grafana's own
folder tree.

- **Within its own mapping** (rename, or into a subfolder of the same mapped folder): stays
  managed; nothing changes in Grafana.
- **Into a *different* mapped folder**: a genuine Grafana **folder move** — the dashboard
  re-parents into the destination folder and **keeps its uid**. Same dashboard, new home.
  (Moving *into or out of a Team Folder* crosses a storage boundary, which Nextcloud handles
  as a copy-and-delete rather than a move; that re-homing rides the delete/create lifecycle and
  is a fast-follow.)
- **Out of every mapping** (sync): the file already holds the full JSON, so Nextcloud keeps
  the only copy — the dashboard is **deleted** in Grafana and the file's identity stripped,
  leaving a plain, untracked `.grafana.json`. Move it back into a mapping and it rides
  create-on-land: a brand-new dashboard (new uid). *(Recycle-bin-preserving move-out — park
  the dashboard instead of deleting it, uid kept — is a fast-follow.)*
- **A link** cannot be moved out of its mapping (ejecting a pointer with no local JSON is
  meaningless); that move is refused with a message.

If Grafana can't confirm the delete, the file **keeps its identity** and stays reconcilable
rather than being silently orphaned.

📋 spec: [`features/move.feature`](features/move.feature) · 🛠 [`lib/Listener/MoveGuardListener.php`](lib/Listener/MoveGuardListener.php), [`lib/Listener/MotionListener.php`](lib/Listener/MotionListener.php), [`lib/Service/MotionService.php`](lib/Service/MotionService.php)

### Copying a dashboard (always a brand-new instance)

Where a move is "the same dashboard," a **copy** is always a *new* one. A copied file never
carries the original's Grafana identity — its metadata is stripped the moment it is copied.

- **Copy within a mapped sync folder** → the copy becomes a **new** dashboard in Grafana
  (new uid, its own name).
- **Copy to outside any mapping** → a plain, untracked `.grafana.json`.

So duplicating a dashboard is as simple as copying its file, and a copy never silently
hijacks the original's dashboard.

📋 spec: [`features/copy.feature`](features/copy.feature) · 🛠 [`lib/Listener/CopyListener.php`](lib/Listener/CopyListener.php), [`lib/Service/CopyService.php`](lib/Service/CopyService.php)

### Writeback: edit a file, update the dashboard

Edit a synced `.grafana.json` in the Files app (or over WebDAV / the desktop client) and on
**Save** the dashboard updates in Grafana on its stable uid — same dashboard, same folder,
never a duplicate. It runs automatically (background by default, honouring the push-timing
setting), on the **Sync to Grafana** button, or via `occ grafana_sync:sync push`. A
request-scoped guard plus a content hash keep the app from pushing its own pull writes back
(the classic sync-loop problem). A push Grafana rejects raises a notification with Grafana's
own message and leaves the file to retry on the next save.

📋 spec: [`features/reconcile.feature`](features/reconcile.feature) · 🛠 [`lib/Listener/NodeWrittenListener.php`](lib/Listener/NodeWrittenListener.php), [`lib/Service/PushService.php`](lib/Service/PushService.php)

### A link file can't be edited into a corner

A **link** file is only a tiny pointer to a dashboard that lives in Grafana — there's no
full JSON on the Nextcloud side to change. A raw WebDAV `PUT`, a desktop-client sync, or
`curl` would otherwise overwrite the pointer blindly, so a DAV guard **refuses** any write
to a link file with a clean `403` and a notification explaining how to switch the folder to
sync mode. (The Files UI already routes a link's click to "Open in Grafana" rather than the
editor.) The guard **fails open** on any doubt — it only ever blocks a file it positively
knows is a link.

🧪 tested: [`tests/unit/DAV/LinkWriteGuardPluginTest.php`](tests/unit/DAV/LinkWriteGuardPluginTest.php) · 🛠 [`lib/DAV/LinkWriteGuardPlugin.php`](lib/DAV/LinkWriteGuardPlugin.php), [`lib/Listener/RegisterDavPluginsListener.php`](lib/Listener/RegisterDavPluginsListener.php)

### Deleting — the Nextcloud trash is Grafana's undo

Grafana has **no native trash** — a delete is permanent. So deleting a dashboard file is native
Nextcloud trash on our side, plus a Grafana action that turns on one optional setting, the
**Grafana recycle-bin folder** (admin → Sync Settings):

- **Bin off** (default, honest): trashing a synced file **deletes** its dashboard in Grafana right
  then (the full JSON is safe in the trashed file), and **restoring** it re-creates the dashboard
  with a **new id**. Emptying the trash is then a Nextcloud-only act.
- **Bin on** (id-preserving): trashing instead **moves the dashboard into the named folder**,
  keeping its id; **restoring** moves it back, same id. The one irreversible moment is **emptying
  the Nextcloud trash** — that permanently deletes from the Grafana bin, and **only** the items you
  cleared (never a wholesale bin-clear; the bin may hold dashboards Nextcloud doesn't manage).

A **link** file is a pointer, so trashing it only severs the tie — its dashboard is never deleted.
An untracked `.grafana.json` is never touched. And whichever step issues the real Grafana delete,
if Grafana can't confirm it the trash is **aborted** so the file stays recoverable — deleting can
never silently desync the two systems or lose a dashboard's content.

📋 spec: [`features/delete.feature`](features/delete.feature) · 🛠 [`lib/Service/DeleteService.php`](lib/Service/DeleteService.php), [`lib/Listener/DeleteToGrafanaListener.php`](lib/Listener/DeleteToGrafanaListener.php), [`lib/Listener/RestoreFromTrashListener.php`](lib/Listener/RestoreFromTrashListener.php)

### Removing a folder mapping tears it down safely

Deleting a mapping (admin panel or `occ grafana_sync:remove-mapping`) **trashes its connected
files** — so their dashboards follow the recycle-bin setting above (deleted, or parked for a clean
reconnect) — while **standalone files that were never part of the mapping are left strictly
alone**. Restore the trash, or re-map the folder, and they reconnect. This is the tear-down, not
the Purge button (which keeps the mapping and never touches Grafana).

📋 spec: [`features/remove-mapping.feature`](features/remove-mapping.feature) · 🛠 [`lib/Service/MappingTeardownService.php`](lib/Service/MappingTeardownService.php)

### A first-class file type: custom mimetype, icon, queryable metadata

A managed dashboard isn't a generic JSON blob — it's a proper file type. The app registers
the `application/grafana+json` mimetype, so files show a **Grafana icon** instead of a
generic JSON glyph, and each file's state is exposed over WebDAV in a raw `PROPFIND`:

| DAV property | What it contains |
|---|---|
| `nc:metadata-grafana_uid` | The dashboard's uid in Grafana |
| `nc:metadata-grafana_mode` | `sync`, `reference`¹, `unmapped`, or `ignored` |
| `nc:metadata-grafana_version` | The version of the last successful sync |
| `nc:metadata-grafana_mapping` | The mapping this file belongs to (empty when unmapped) |

¹ `reference` is the on-the-wire value for **link** mode — a Nextcloud PROPFIND quirk treats
a stored value equal to the built-in `link()` function as a callback, so the literal string
`link` would crash it. Everywhere else — UI, tag, docs — it's **link**.

Because `grafana_mode` is **indexed**, "find every sync dashboard" / "every unmapped file"
is a fast DAV `REPORT`, not a folder walk. A `grafana:sync` / `grafana:link` coloured pill
(a system tag) mirrors each managed file's mode automatically.

📋 spec: [`features/file-type.feature`](features/file-type.feature) · 🛠 [`lib/Service/DashboardMetadata.php`](lib/Service/DashboardMetadata.php), [`lib/Service/OwnershipTags.php`](lib/Service/OwnershipTags.php)

### Opening a dashboard: Open in Grafana vs text editor

Driven by the file's **mode**. Two row actions in the Files app:

- **Open in Grafana** — jumps straight to the live dashboard (built from the file's `grafana_uid`).
  Offered for **sync** and **link** files (there's a dashboard to open), and it's their default click.
- **Open with text editor** — edits the raw JSON in a monospace modal; saving pushes back to Grafana.
  Offered for every mode that holds the full JSON (**sync**, **unmapped**, **ignored**); hidden for
  **link** (a pointer — nothing to edit). For unmapped/ignored files there's no live dashboard, so the
  text editor is the default click.

There's also a **Grafana dashboard** entry in the **+ New** menu — drop the new `.grafana.json`
into a mapped sync folder and create-on-land makes it real in Grafana.

📋 spec: [`features/open-with.feature`](features/open-with.feature) · 🛠 [`src/files.js`](src/files.js), [`lib/Listener/LoadFilesScriptListener.php`](lib/Listener/LoadFilesScriptListener.php)

### Manual sync (Sync from / Sync to Grafana)

Beyond the on-save writeback, both directions are available on demand — from the admin
**Sync Actions** buttons or `occ grafana_sync:sync <pull|push> [--mapping=<id>]`:

- **Sync from Grafana** (pull) provisions each mapped folder (Team Folder or admin-owned)
  and fills it with that Grafana folder's dashboards — adding new files, updating existing
  ones in place (matched by uid, never duplicated), and pruning a file whose dashboard left
  the folder.
- **Sync to Grafana** (push) sends the mapping's sync files up.

📋 spec: [`features/reconcile.feature`](features/reconcile.feature) · 🛠 [`lib/Service/SyncService.php`](lib/Service/SyncService.php)

---

## Administration

### Grafana connection

| Setting | Description |
|---|---|
| **Grafana base URL** | Base URL of your Grafana, e.g. `https://grafana.example.com` (no trailing slash). In-cluster URLs like `http://grafana-service.observe.svc:3000` also work. |
| **Service-account token** | A Grafana service-account token (role **Editor** is enough). Create one under **Administration → Service accounts** in Grafana. Sent as `Authorization: Bearer`. Stored encrypted — never echoed back after saving. |

Because the token is stored encrypted and never echoed back, the field always looks
empty — so the card's text tells you whether a token is **currently stored**, and a
**Test connection** button (in the **Sync Actions** section, below the folder
mappings — all action buttons live together there) confirms whether it actually
*works*. The test calls an
**authenticated** Grafana endpoint (`GET /api/folders`), so a green result proves the
token itself is valid, not merely that the host is reachable. A red result
distinguishes the two failure modes you care about: **no token set yet** vs. a token
that was **set but rejected** (invalid/expired) — the same wording on the button and
the `occ` command.

### Sync Schedule

| Setting | Description |
|---|---|
| **Nextcloud → Grafana push timing** | **async** (recommended): the push runs in the background after you save a dashboard file. **sync**: the push runs inline during the save for immediate feedback. Only sync-mode mappings push back. |
| **Grafana → Nextcloud scheduled sync** | Master toggle for automatic pulls (read-only — nothing changes in Grafana). When off, use the "Sync from Grafana" button. |
| **Sync interval** | How often to pull, as `<number><unit>` — e.g. `15m`, `1h`, `6h`, `1d`. A plain number is seconds; minimum 1 minute. |
| **Grafana recycle-bin folder** | Off (default): trashing a synced file deletes its dashboard in Grafana; restoring re-creates it (new id). On: names an existing Grafana folder as the bin — trashing moves the dashboard there (id kept), restoring moves it back, and only emptying the Nextcloud trash deletes it for good. Don't map the bin folder — it has special meaning. |

These settings are stored now and read by the sync engine when it lands — a scheduled
pull doesn't run until that release.

### Folder Mappings

A mapping binds a Grafana folder to a Nextcloud folder and defines how its dashboards
appear.

| Field | Description |
|---|---|
| **Grafana folder** | The Grafana folder to mirror (picked from the folders your token can see). Bound by its stable **uid**, so a rename in Grafana never breaks the mapping. Each folder maps to exactly one location. |
| **Nextcloud folder** | The Nextcloud folder the dashboards appear in. May be nested (`dashboards/observe`); the nearest enclosing mapping wins. |
| **Mode** | `sync` (full dashboard body, edits push back) or `link` (a read-only pointer that opens the dashboard in Grafana). See [Modes](#modes). |
| **Format** | `json` (the classic dashboard model, `.grafana.json`) or `yaml` (the newer k8s-style schema, `.grafana.yaml`). |
| **Team Folder** | On = an ownerless Team Folder (requires the groupfolders app). Off = a folder in the admin account shared to the groups. |
| **Groups** | The Nextcloud groups the folder is shared with. |

Every field persists with the mapping (and round-trips over `occ`). The Team Folder,
Groups, and per-mapping **Sync** button describe how the folder is provisioned and
synced — that provisioning runs once the sync engine lands.

### Sync Actions

All action buttons live together in the **Sync Actions** section, below the folder
mappings: **Sync to Grafana** / **Sync from Grafana** (bulk manual sync), **Purge
Nextcloud files** (removes the dashboard files this app created; Grafana is never
touched), and **Test connection**. The bulk-sync and purge buttons are disabled until
dashboard sync lands; **Test connection works today**.

---

## CLI commands

Every admin action is available over `occ`, so setup can be automated (e.g. from a
Kubernetes init/config job). All commands exit `0` on success and non-zero on error.

```sh
# Point the app at your Grafana instance
occ config:app:set grafana_sync grafana_url --value="https://grafana.example.com"

# Store the service-account token (encrypted, exactly as the Settings panel does).
# Pass it as an argument, or pipe it on stdin to keep it out of shell history:
echo "$GRAFANA_TOKEN" | occ grafana_sync:set-token

# Verify it all works — the headless "Test connection" button
occ grafana_sync:test-connection

# Sync Schedule (the declarative "Sync Settings" card, headless)
occ config:app:set grafana_sync timing --value=async
occ config:app:set grafana_sync schedule_enabled --value=1
occ config:app:set grafana_sync schedule_interval --value=1h

# Folder mappings (same operations as the admin panel)
occ grafana_sync:add-mapping '{"grafana_folder_uid":"af397c9y8enswf","grafana_folder_title":"observe","nc_folder":"observe","mode":"sync","format":"json","nc_groups":["admin"],"use_team_folder":true}'
occ grafana_sync:list-mappings
occ grafana_sync:remove-mapping <mapping-id>
```

---

## Development

See [CONTRIBUTING.md](CONTRIBUTING.md) for the full process, and
[AGENTS.md](AGENTS.md) for a cold-start orientation. The long-form design narrative lives
in [`saga/`](saga/).

This is a community integration and is not affiliated with, endorsed by, or sponsored by
Grafana Labs. "Grafana" and the Grafana logo are trademarks of Grafana Labs, used here only
to identify the service this app integrates with.
