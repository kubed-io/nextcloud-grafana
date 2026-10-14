<!--
SPDX-FileCopyrightText: 2026 kubed-io
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Mapping version — design

A mapping chooses which of Grafana's dashboard APIs its folder is read and written
through: **version 1**, the classic dashboard JSON this app has always written, or
**version 2**, Grafana's `dashboard.grafana.app/v2` resource. The file format follows
from the version and from nothing else: **a v1 file is JSON, a v2 file is YAML.** Both are
`foo.grafana`.

## Why

Measured on the live Grafana 13.2.1, 2026-10-14:

- **Each dashboard is stored in one version, and every API is a converting view of it.**
  `status.conversion.storedVersion` says which. On the live instance 20 dashboards are
  stored `v0alpha1` (classic) and 12 `v2beta1`, and every one of them reads cleanly through
  `/api/dashboards/uid/*`, `dashboard.grafana.app/v1` and `dashboard.grafana.app/v2`.
- **Reading converts losslessly enough. Writing through v1 does not.** A throwaway
  dashboard made through v2 with a `TabsLayout` holding an `AutoGridLayout` reads through
  the classic API as a row panel plus fixed `gridPos` panels. POSTing that body back,
  unchanged, to `/api/dashboards/db` succeeded — and the dashboard was then a `RowsLayout`
  holding a `GridLayout`. The tabs and the auto grid were gone, with no error.
- **This app writes through v1 only, and it writes a lot.** An edit, a tag on the Files
  pill, a rename, a move, a copy and parking in the recycle bin all POST the file's body
  to `/api/dashboards/db`. So a sync mapping over a folder of v2 dashboards flattens each
  one the first time anyone touches it.

A version-1 mapping keeps doing exactly that, byte for byte. A version-2 mapping reads and
writes the v2 resource, so nothing only v2 can express is lost on the way through.

### This reverses a saga decision, on purpose

Chapter 2, Round 8 removed a `format` field (`json`/`yaml`) because *"nothing in `lib/`
ever branched on it"*, and `Mapping.php` still says *"THERE IS NO FORMAT OPTION … if
Grafana's v2 App Platform serialization is ever picked up it will be decided by what
Grafana serves, not by a per-folder switch set before anyone knows the answer."*

The answer is now known, and it is per dashboard: Grafana serves both, and which one is
safe to write depends on what the dashboard holds. The field that comes back is not a
format. It is the API a folder is written through; the format is a consequence (Dr K:
*"we don't need a selector for json or yaml. It's just v1 is json and v2 is yaml"*). Every
branch in `lib/` that writes a dashboard reads it, which is the thing Round 8 found
missing. Chapter 1's risk #1, "v2 round-trip", is cleared below.

## What it does

| | version 1 (default) | version 2 |
|---|---|---|
| The file | classic dashboard JSON, as today | YAML: `apiVersion`, `kind`, `metadata.name`, `spec` |
| A link's pointer | the `grafana.reference/v1` pointer, JSON | the same pointer, YAML |
| A pull reads | `GET /api/dashboards/uid/{uid}` | `GET /apis/dashboard.grafana.app/v2/namespaces/default/dashboards/{uid}` |
| A write sends | `POST /api/dashboards/db` | `PUT …/dashboards/{uid}` |
| Extension, mimetype, icon | `.grafana`, `application/grafana+json` | the same |

- **The version is chosen when the mapping is made and never changes**, like mode and
  storage. Changing it is removing the mapping and mapping the folder again. The admin
  form, `occ grafana_sync:add-mapping` and `occ grafana_sync:list-mappings` all carry it;
  an omitted version is `1`, and so is every mapping saved before the field existed.
- **A v2 sync file** is the resource as Grafana's own Git Sync export writes it
  (`pkg/tests/apis/provisioning/exportunifiedtorepository/dashboard-test-v2.yaml` in
  grafana/grafana):

  ```yaml
  apiVersion: dashboard.grafana.app/v2
  kind: Dashboard
  metadata:
    name: fleet-health
  spec:
    title: Fleet Health
    tags:
    - ops
    elements: …
    layout: …
  ```

  `metadata.name` is the dashboard's uid. Everything else under `metadata` (resource
  version, generation, the k8s uid, timestamps, labels, annotations, managed fields) and
  the whole of `status` is stripped, for the reason `id` and `version` are stripped from a
  v1 file: Grafana rewrites them on every save, and a body that carried them would churn.
  The folder is not in the file either; the Nextcloud folder is the answer, as it is for v1.
- **A v2 link file** is the same six-field pointer v1 writes, as YAML. A link reads only
  `/api/search` and the dashboard's clocks, so the version changes its format and nothing
  else. *(My call: Dr K's rule is about the folder's files, and a folder where pointers
  were JSON and dashboards YAML would be the one exception anybody had to remember.)*

## The rule that keeps every gesture lossless

> **The mapping decides how a folder is read. The body decides how it is written.**

- **A pull** reads each dashboard through the mapping's version and writes the file in the
  mapping's format. This is the only place the mapping's version picks an API.
- **Every write** — push, create-on-land, copy, rename, tag write-back, move, recycle-bin
  park and restore — looks at the body it is about to send:
  - a `dashboard.grafana.app/*` resource (`apiVersion` + `kind: Dashboard` + `spec`) is
    PUT to the API version **its own `apiVersion` names**. Grafana refuses a body whose
    `apiVersion` differs from the URL's (measured: 400), so this is the only URL that works;
  - anything else is a classic dashboard, and goes to `POST /api/dashboards/db` exactly as
    today.
- **Decoding** tries JSON first and YAML second, in every folder. YAML is the fallback, not
  the default, so a v1 folder full of JSON never reaches the YAML parser.

What that buys, gesture by gesture:

| Arrives | In a v1 folder | In a v2 folder |
|---|---|---|
| The + New starter (classic JSON) | as today | created through the classic API, then rewritten as YAML |
| A dashboard JSON downloaded from grafana.com | as today | the same — a classic body holds nothing only v2 can, so nothing is lost |
| A v2 YAML file moved in from a v2 folder | written through v2 (lossless), then rewritten as JSON | written through v2 |
| A v1 JSON file moved in from a v1 folder | as today | written through the classic API, then rewritten as YAML |

So a move between a v1 and a v2 mapping is allowed, and loses nothing the moving file
held. *(My call. The alternative was refusing it the way a move between a sync and a link
mapping is refused; that rule exists because a link cannot hold a dashboard, and both
versions can.)*

**Why never wrap a classic body in a v2 envelope.** It would be shorter, and it is wrong:
the v2 API validates almost nothing (measured: a `spec` of `{title, panels: []}` PUT to
v2 answered 200) and does not convert a classic spec it is handed, so the dashboard would
be stored as a v2 object with classic fields in it. Grafana's classic API converts a
classic body properly; the app lets it.

**"Then rewritten as YAML"** means the gesture leaves the file in its mapping's format, not
"eventually". Where the hook that saw the gesture can write the file, it does, under the
`SyncGuard` like every other stamp; where it cannot (a copy's hook holds locks — see
`CopyService`), the follow-up job that already repairs bodies after a hook
(`ReconcileNameJob`) does it. A user who makes a new dashboard in a v2 folder and opens it
finds YAML.

## Platform facts the design rests on

All measured against the live Grafana 13.2.1 on 2026-10-14, with throwaway dashboards
named `zz-ncgs-*` that were deleted afterwards.

| Fact | Consequence |
|---|---|
| `dashboard.grafana.app` serves `v0alpha1`, `v1beta1`, `v1`, `v2alpha1`, `v2beta1`, `v2`; **`v2` is the preferred version** | v2 means the GA `v2`; there is no beta to pick |
| A v2 GET → PUT → GET of three real dashboards (one stored classic, two stored `v2beta1`) is a fixed point: no field differs, and `generation` does not move | the v2 round-trip is lossless (Ch1 risk #1), and the existing sha1 loop guard needs nothing new |
| A minimal spec PUT to v2 comes back with defaults filled in (`timeSettings`, `variables`, `cursorSync`, …) | as with v1, the guard hashes the bytes the app sent; the next pull writes Grafana's fuller form once, and is quiet after |
| `PUT …/dashboards/{name}` on a name that does not exist answers 201 | one upsert verb, like `POST /api/dashboards/db` |
| A PUT without the `grafana.app/folder` annotation moves the dashboard to General | every v2 write sets the annotation; only the reserved-root mapping omits it, exactly as v1 omits `folderUid` |
| `grafana.app/message` is accepted as an annotation | the commit message v1 sends rides there |
| The legacy `meta.version` equals `metadata.generation` | `grafana_version` keeps its meaning: stamp `generation` from the PUT's answer |
| `metadata.creationTimestamp` and the `grafana.app/updatedTimestamp` annotation match the legacy `meta.created` / `meta.updated` | a v2 pull dates the mirror from the one response; no second read |
| `/api/search`, `/api/dashboards/uid/*` (read) and `DELETE /api/dashboards/uid/*` work on every stored version | search, existence checks, deletes and folder calls stay on the APIs they use today |
| A dashboard's stored version is unaffected by which API reads it | a v2 mapping over classic dashboards is safe: they are converted on read and stored as v2 on the first write |
| `metadata.generateName` works but mints `<prefix>xxxxx` | a born copy mints its uid locally in Grafana's own shape and PUTs it, rather than taking a uid that looks like nothing else in the instance |

**Version 2 needs a Grafana that serves `dashboard.grafana.app/v2`**, which every Grafana
13 does (13.0.2 already listed it as preferred — saga Ch1). A version-2 mapping on a
Grafana that does not is **refused when it is made**, by reading the group's version list
at `GET /apis/dashboard.grafana.app`, with *"This Grafana does not serve version 2
dashboards (dashboard.grafana.app/v2)."* — rather than saved and then failing every pull
with a 404. The README states the requirement. *(My call.)*

**CI runs Grafana 13.0.2; production runs 13.2.1.** Every fact above was measured on
13.2.1, so the integration job's image moves to `grafana/grafana:13.2.1` in this round,
for the reason the PHP in CI matches the pod's. *(My call.)*

Grafana can report `status.conversion.failed: true` for a dashboard it cannot convert.
A pull skips that dashboard with a warning and counts it as failed — the same treatment
as a record with no spec today. Nothing is written over the file it already has.

## The YAML

There is no YAML parser in Nextcloud core or any installed app (checked in the live pod:
no `ext-yaml`, no `symfony/yaml` in `3rdparty/`), and none of the nextcloud-* apps ships a
runtime PHP dependency yet. This would be the first.

- **`symfony/yaml` 7.4 LTS**, and the app declares `<php min-version="8.2"/>`. *(My call.)*
  6.4 would keep PHP 8.1, which has been end-of-life since 2025-12-31, and it cannot
  write a list of maps as `- kind: Panel`: it writes a lone `-` with the map on the next
  line, at every element and every query of every dashboard. 7.3 added
  `DUMP_COMPACT_NESTED_MAPPING` for exactly that.
- `symfony/polyfill-ctype` and `symfony/deprecation-contracts` are declared `replace`d:
  Nextcloud requires `ext-ctype` and ships `deprecation-contracts` itself.
- **Prefixed under `OCA\GrafanaSync\Vendor\`** so another app's copy of Symfony can never
  be the one that loads. *(How it is packaged is the open question below.)*
- **Shape-exact, like the JSON.** Parse with `PARSE_OBJECT_FOR_MAP`, so an empty `{}`
  survives as `{}` — the exact defect `DashboardBody::encodeSync()` documents for
  `json_decode(assoc: true)`. Dump with `DUMP_OBJECT_AS_MAP`,
  `DUMP_COMPACT_NESTED_MAPPING`, `DUMP_MULTI_LINE_LITERAL_BLOCK` (a markdown panel's text
  reads as text) and `DUMP_EMPTY_ARRAY_AS_SEQUENCE`, block style at every depth, two-space
  indent.
- **Compact nesting only applies to PHP arrays, not objects**, so the dump converts each
  non-empty object to an array first — except an object whose keys would make PHP read
  it as a list (`{"0": a, "1": b}`), which stays an object and so stays a map. An empty
  object stays `{}` (symfony writes it `{  }`).
- **Measured** in a scratch install of 7.4.21: three real v2 dashboards (106–183 KB of
  YAML each) and an edge-case body (`{"0":…}` maps, `{}`, `[]`, `null`, `1.0`, `"012"`,
  `"true"`, multi-line strings, lists of lists) dump, parse back JSON-identical, and
  re-dump to the same bytes.
- **Deterministic**, because `bodyDiffers()` compares bytes: the same spec always dumps to
  the same string.

## Changes, by part

- **`Mapping`** gains `version` (`1`|`2`, default `1`), validated in `fromArray()`, always
  written by `toArray()`, carried by `withNcFolderId()`/`withNcFolder()`. The docblock's
  "THERE IS NO FORMAT OPTION" section is replaced by one saying what this spec says.
- **`DashboardBody`** grows the v2 half: encode a v2 resource and a v2 pointer as YAML,
  decode a file body of either kind, build the PUT body (stamp `metadata.name` from the
  file's metadata uid, set the folder and message annotations, keep the body's own
  `apiVersion`).
- **One place answers "uid, title, tags of this body"** for both shapes (`uid` / `title` /
  `tags` at the top of a classic body; `metadata.name` / `spec.title` / `spec.tags` in a
  resource). Today a dozen call sites reach into `$spec->uid`, `->title` and `->tags`
  directly; each goes through that one place instead.
- **`GrafanaClient`** gains the v2 read and the v2 PUT. Folder calls, search, delete and
  the existence checks are unchanged.
- **The pull** (`SyncService::writeDashboard`) picks the read and the encoding by the
  mapping's version.
- **Every write site** (push, create, copy, rename, name reconcile, tag write-back, delete
  to bin, motion) decodes through the one decoder and sends through the one
  body-decides upsert.
- **The admin form** gets a third select beside mode and storage, locked once the mapping
  is saved like the other two; the card shows it. Copy is terse: the select reads
  `v1 · JSON` / `v2 · YAML`.
- **`occ`**: `add-mapping` accepts `"version": 2`; `list-mappings` prints it.
- **README** says what the version is for, in one paragraph, with the flattening as the
  reason to pick 2.

## Gherkin

Three files, chosen so that each new line says something no existing line says. The
exact text is in the plan; the claims are:

1. **`mapping/create.feature`** — `version` is a mapping field like the others: it
   defaults to `1`, and `1` and `2` are both accepted, alone and in combination. One row in
   the defaults table, one row in the submitted table, one column in both Examples tables.
2. **`dashboards/create.feature`** — *"the mapping decides what the file is"* gains the
   version axis: a dashboard made in Grafana under a v2 sync mapping lands as the
   dashboard's full YAML, and under a v2 link mapping as the pointer in YAML. A dashboard
   made from the Files app in a v2 folder is created in Grafana like any other.
3. **`dashboards/edit.feature`** — the reason for the feature, as a rule: *an edit to a
   file in a version-2 mapping keeps what only version 2 can hold.* A dashboard with tabs
   is edited from Nextcloud and still has its tabs in Grafana. The version-2 mapping is
   declared in the scenario, not the Background: the version is the whole point of the
   rule, so it is on the page where the rule is (Dr K).

Moves, copies, renames, tags and the recycle bin across versions are covered by unit
tests in this round; their scenarios are listed under *Next* rather than added, so the
Gherkin diff stays a statement of the feature rather than a census of it.

## Not in this round

- Changing a mapping's version in place, or converting a folder's files between formats.
- The folder API (`folder.grafana.app`) — folders are version-neutral already.
- An editor schema. Neither the Foundation SDK's `dashboardv2.jsonschema.json` nor the
  live `/openapi/v3/apis/dashboard.grafana.app/v2` validates a real dashboard (both type
  override values as objects, and the SDK requires a panel `description`). A patched copy
  of the live OpenAPI is the closest thing, and is editor-side work.
- A `application/grafana+yaml` mimetype. One extension carries one mimetype, and the
  icon and the click are all it drives.

## Next

Scenarios to propose once this lands: a move between a v1 and a v2 mapping; a copy in a
v2 folder; a tag added on the pill reaching `spec.tags`; a v2 dashboard parked in and
restored from the recycle bin; a classic JSON uploaded into a v2 folder becoming YAML.

## How the YAML library ships (Dr K, 2026-10-14)

*"I don't want third party stuff committed when we can easily pull it again."*

- `composer.json` requires `symfony/yaml ^7.4`; Strauss is a dev dependency, and a
  `post-install-cmd` hook prefixes the library into **`lib/Vendor/`, which is gitignored**.
  Every `composer install` — the unit and quality jobs, the dev pod — produces it with no
  step of its own to forget.
- `package.yml` and `deploy-dev.sh` run no composer today, so each gains one
  `composer install` line. Both already copy `lib/`, so the prefixed library rides along
  with no change to what they pack. The package job fails if `lib/Vendor/autoload.php` is
  missing, so a tarball that would die on "class not found" never ships.
- **Not the PECL `yaml` extension in the Nextcloud image**, though Dr K asked whether it
  could be: a store user on a stock image does not have it, so the bundled copy ships
  regardless and the extension would only add a second code path. It also cannot do the
  job — `yaml_parse()` returns every map as a PHP array, so an empty `{}` comes back `[]`,
  the defect `DashboardBody::encodeSync()` documents for JSON.
