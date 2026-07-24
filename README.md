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

> **Status: early development.** This build ships the full admin **settings** surface —
> point the app at Grafana and store a service-account token (with a live Test
> connection), configure the sync schedule, and define folder mappings (mode, format,
> groups, Team Folder) — all persisted and editable over `occ`. The dashboard sync
> engine itself (files, two-way writeback, the bulk-sync buttons) lands in later
> releases. The full behaviour is written up front as executable specs under
> [`features/`](features/) (most tagged `@todo`), so the docs, tests, and roadmap stay
> aligned.

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

### Tags — synced three ways *(planned)*

A dashboard's **tags** are part of the object, so a real sync keeps them in step too. Grafana
holds tags *inside* the dashboard (`dashboard.tags: ["dns","linux"]`); Nextcloud has its own
first-class **system tags** (the searchable coloured pills in Files). Grafana Sync keeps the
two the same set, so **the mirror is as searchable as Grafana itself** — filter "every
`linux` dashboard" the Nextcloud-native way.

Because the tags live in the object, there are **three** places to edit them, and all three
stay in agreement:

- **Edit in Grafana** → a pull brings the tags into the Nextcloud file and onto its pills.
- **Edit the file's pills** (or the `tags` array in the JSON) → the change pushes back to
  Grafana.
- The file body is the hinge: the pills mirror it, and it round-trips to Grafana.

Two rules keep it safe: the app's own control tags (the reserved `grafana:` namespace, e.g.
`grafana:sync`) are **never** mixed into your dashboard's tags in either direction; and when
tags have changed on **both** sides since the last sync, a three-way merge (against the
last-synced set the app remembers) tells an *add* apart from a *remove* so nothing is lost.
Tag sync runs in **both** `sync` and `link` mappings for searchability — a `link` file is
read-only, so its tags flow one way, Grafana → Nextcloud.

### Two dashboard "cuts" (classic JSON and the new YAML schema)

Grafana serves a dashboard two ways, and the mapping records which one a folder uses:

- **Classic JSON** (`dashboard.grafana.app/v1beta1` / the `/api/dashboards` model) — the
  familiar dashboard JSON. The default, and what every existing dashboard already is.
- **The v2 schema as YAML** (`dashboard.grafana.app/v2`, the App Platform's k8s-style
  resource) — the modern, GitOps-friendly cut, serialized as YAML (`.grafana.yaml`).

Classic JSON ships first; the v2/YAML cut is an opt-in per mapping.

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
