# Chapter 1 — Mise en Place

> Every good plate starts long before the fire. It starts with *mise en place* —
> "everything in its place." The onions diced, the stock reduced, the knives
> honed, the pans within reach. A cook who skips it is just a person panicking
> next to a stove. So before we cook a single thing, we do the prep.
>
> Here's the situation. Down the street there's a chef who already has his stars —
> **`nextcloud-n8n`**, the master. He runs a tight kitchen: workflows come out of
> `n8n` and land in Nextcloud as clean, plated `.n8n.json` files, and anything the
> customer changes on the plate goes straight back to the pass. Four services a
> night, no dropped tickets. His menu is *proven*.
>
> **We** are the apprentice — **`nextcloud-grafana`**. We've staged in his kitchen,
> we've watched every service, and we want to cook *his exact menu* — the same
> mapping, the same sync, the same modes, the same plating. But with a different
> main ingredient. Where he cooks **workflows**, we cook **dashboards**. Every
> feature on his menu is a dish we have to master, one at a time, and prove we can
> plate it as well as he does.
>
> **Dr K** is the one who owns the kitchen, tastes every plate, and decides what
> goes on the menu. He's also — this being what it is — narrating from the corner
> with a glass of something, occasionally reaching over to stir. Loose kitchen.
> That's allowed.
>
> This chapter is *mise en place*. **We cook nothing yet.** We walk the pantry,
> check what Grafana actually gives us to work with, find out whether our knives
> even fit the ingredient, and come out able to say — dish by dish — *"yes chef,
> I can plate that,"* or *"chef, this one needs a different technique."* The good
> news, spoiled early: the main ingredient turned out to be **better cut** than the
> master's. Grafana comes pre-portioned in a way `n8n` never did.

---

## Where we are — 2026-07-23 · **PREP, NOT SERVICE**

> **We tasted the pantry. The ingredient is fresher than the master's — and one
> whole prep step he had to do by hand, Grafana does for us.**
>
> - **The kitchen is reachable and the card works.** From the cluster we can hit
>   Grafana's API, and the credential path — a **Grafana service-account token** —
>   is a known, first-class thing (the operator mints them; three already exist).
>   We can get a scoped key the same way we get an `n8n` key. Proven below.
> - **Grafana comes pre-portioned.** The master's single hardest bit of knife-work
>   was that **`n8n` has no folders** — he had to fake structure with *tags*,
>   binding one tag to one folder. **Grafana has real folders.** A Grafana folder
>   *is* a Nextcloud folder. The whole "tag → folder" contraption collapses into a
>   plain folder-to-folder mirror. This dish is *easier* than his.
> - **Grafana has a second, modern cut of the ingredient.** Beyond the classic
>   dashboard JSON, Grafana 13 ships a live **k8s-style App Platform API**
>   (`dashboard.grafana.app`, `folder.grafana.app`) that serves each dashboard and
>   folder as a versioned resource — the thing you can write as **YAML**. So a
>   dashboard file can be *either* the familiar JSON *or* a clean k8s manifest, and
>   the mapping can decide which. **Yes chef — the new YAML dashboards work right
>   now.** Measured on the live instance, not read off the box.
>
> **Nothing is plated.** The next move is one honest **test cook** (§ The Test
> Cook): pull one real dashboard down as a file, change it, push it back, confirm
> Grafana shows the change. If that holds — and the prep says it will — the rest is
> the master's menu re-cooked with our ingredient, a technique we've now watched
> him run twice (`nextcloud-n8n`, and its cousin `drupal-n8n`).

---

## Course 0 — Does the kitchen let us in? (the API + auth reality check)

> Before you dice anything you find out whether you're even allowed at this station:
> is the walk-in unlocked, is there an ingredient on the shelf, will your key open
> the door? Dr K asked the blunt version — *"can Nextcloud actually reach Grafana,
> and can we get a credential of any sort?"* So we walked to the Grafana station,
> from inside the cluster, and tried the doors.

**The walk-in is unlocked.** Grafana runs in namespace **`observe`**
(`grafana-service.observe.svc.cluster.local:3000`), version **13.0.2** — a live,
healthy instance (`/api/health` → `{"database":"ok","version":"13.0.2"}`).
Nextcloud runs one namespace over, in **`cloud`**. Pod-to-service over cluster DNS;
no ingress round-trip needed. Same reachability story the master already relies on
for `n8n`.

**There's an ingredient on the shelf, and it's the good stuff.** Two ways in, and
they *stack*:

- **The classic pantry** — Grafana's long-standing REST API: `/api/search` (lists
  folders + dashboards), `/api/dashboards/uid/{uid}` (the full dashboard JSON),
  `/api/folders` (CRUD on folders). This is the `n8n`-equivalent surface and it's
  everything the sync engine needs.
- **The modern pantry** — the **App Platform / k8s-style apiserver**, live at
  `/apis/`. We enumerated it on the running instance:

  ```
  dashboard.grafana.app     folder.grafana.app     provisioning.grafana.app
  playlist.grafana.app      preferences.grafana.app ...
  ```

  `dashboard.grafana.app` advertises **six versions** — `v0alpha1`, `v1beta1`,
  `v1`, `v2alpha1`, `v2beta1`, `v2` (preferred). We pulled a real dashboard through
  it (`.../dashboards/kel4vkt`) and got back a proper k8s object —
  `apiVersion: dashboard.grafana.app/v1beta1`, `kind: Dashboard`,
  `metadata.name: kel4vkt`, and a `spec` holding the familiar panels/schemaVersion
  model. This is the thing that serializes to **YAML**.

**The card that opens the door: a Grafana service-account token.** This is the
crux of Dr K's "can we get a credential" question, and the answer is a clean *yes*.
Grafana service accounts are first-class, and in this cluster the **Grafana Operator
mints them declaratively** — a `GrafanaServiceAccount` custom resource with a role
and a token, and the operator writes the token into a Kubernetes Secret. We didn't
have to theorize this; **three already exist** in `observe`:

```
NAMESPACE   NAME          ROLE     WRITES SECRET
observe     opentofu      Admin    opentofu-grafana-creds
observe     n8n-grafana   —        (n8n's own datasource creds)
observe     grafana-mcp   Editor   grafana-mcp-token   (key: token)
```

So the credential pattern is *already in the building*. We add one more
`GrafanaServiceAccount` for our app, the operator hands us a token secret, and
Nextcloud presents it to Grafana. The one wrinkle — where that secret has to live —
is a real gotcha and gets its own note below.

**How the card is presented (a per-adapter difference to bank now).** `n8n` wants
its key in a custom header, `X-N8N-API-KEY`. **Grafana wants a bearer token** —
`Authorization: Bearer <token>`. Same *idea* (one encrypted secret, sent on every
request), different envelope. This is exactly the kind of thing the master's design
already anticipated: a thin **per-source adapter** where `list/read/upsert/deeplink/
auth` differ, riding a shared sync core (see `nextcloud-n8n` saga §8).

### The one sharp knife on the counter — the namespace transformer

Here's the gotcha Dr K flagged before we even started, and he was right. The
operator only reconciles a `GrafanaServiceAccount` **in the same namespace as the
Grafana instance** — i.e. `observe`. But our new Nextcloud component
(`apps/nextcloud/components/grafana`) is built under Nextcloud's kustomization,
which carries a global `namespace: cloud` transformer that **rewrites every resource
it renders into `cloud`.** Drop the SA into that build unguarded and kustomize
retags it `cloud`, the operator never sees it, and no token is ever minted — a
silent no-op.

So the SA can't just ride the normal build. Two honest options, and we take the
first for now:

1. **Apply the SA out-of-band** into `observe` (a plain `kubectl apply`, bypassing
   Nextcloud's namespace transformer) — the "manually apply into the correct
   namespace" path Dr K predicted. Simple, proven, done in this chapter.
2. **Later:** let the component own the SA but exempt it from the transformer
   (its own kustomization boundary / an explicit `namespace: observe` that survives),
   and bridge the minted token from `observe` → `cloud` with an ESO `ExternalSecret`
   using the Kubernetes provider (read the operator's secret in `observe`,
   materialize it in `cloud`). Clean, GitOps-native, a Course-2 refinement.

For *this* prep session the goal is just: **a token in hand, tested against Grafana.**
Option 1 gets us there without fighting the transformer.

---

## How we checked the fit (the honest palate)

Everything below was tasted against the **live instance**, not the docs:

- **Grafana:** `13.0.2`, namespace `observe`, `grafana-service:3000`. Operator
  `grafana-operator` 5.24, CRDs include `grafanadashboards`, `grafanafolders`,
  **`grafanaserviceaccounts`**.
- **The data:** 5 folders (`build`, `network`, `observe`, `secrets`, `synology`)
  and 21 dashboards, several sitting in the root "General" (no folder), the rest
  filed under a folder. Read straight off `/api/search` and cross-checked through
  the k8s-style `/apis/folder.grafana.app/v1beta1/...` (5 `Folder` objects, each
  `kind: Folder`, `spec.title`, parent carried as a `grafana.app/...` annotation).

---

## The master's menu — dish by dish, can the apprentice plate it?

The master's whole menu (`nextcloud-n8n` README) is the spec. Here's every dish,
and whether our ingredient takes the same technique. **This is the feature-parity
audit.**

| The master's dish (n8n) | Our version (Grafana) | Same technique? |
|---|---|---|
| Map a **tag → folder** | Map a **Grafana folder → NC folder** | 🟢 *Easier.* Real folders replace the tag hack |
| Resource shows as `Name.<id>.n8n.json` | `Name.<uid>.grafana.json` **or** `.grafana.yaml` | 🟢 Same, plus a YAML cut |
| Stable link = workflow **id** in metadata | Stable link = dashboard **uid** in metadata | 🟢 Identical |
| **sync** mode — full JSON, edits push back | Full dashboard JSON/YAML, edits push back | 🟢 Identical |
| **link** mode — pointer, click opens n8n | Pointer, click opens the **Grafana dashboard** | 🟢 Identical (deep-link differs) |
| **unmapped / ignored** modes | Same file-state machine | 🟢 Identical (see note on "archive") |
| Create resource from NC | Create a dashboard in Grafana from a file | 🟢 Same (`POST /api/dashboards/db`) |
| Rename three-way (file ⇄ JSON name ⇄ n8n) | file ⇄ `spec.title` ⇄ Grafana title | 🟢 Same |
| Delete mode-aware (trash → archive → purge) | Trash/purge maps to Grafana delete | 🟡 *See "archive" note* |
| Manual per-mapping Sync from/to | Per-folder pull/push | 🟢 Same |
| Custom mimetype + icon + file action | `application/grafana+json` + Grafana icon | 🟢 Same recipe, new icon/mimetype |
| Bidirectional sync + loop guard | Same content-hash loop guard | 🟢 Identical |
| `occ` CLI for headless config | Same, `grafana_sync:*` | 🟢 Same |

**The verdict up front: we can plate the whole menu.** Most dishes are the master's
technique with the ingredient swapped. Two are genuinely *different* — and both
differences are in our favor or already anticipated. They're the two things worth
the whole chapter.

### Difference #1 — folders are real (the tag hack retires)

This is the big one, and it's the thing Dr K spotted from the start. `n8n` has no
folders, so the master had to invent structure: a mapping binds an **n8n tag** to a
Nextcloud folder, and every workflow wearing that tag lands there. It works, but
it's a workaround — the tag *is* the folder, in spirit.

**Grafana already has folders**, and they nest. A dashboard's home is a `folderUid`.
So our mapping is the honest thing the master was emulating: **a Grafana folder maps
to a Nextcloud folder, and its child dashboards become the files inside.** Nested
Grafana folders (parent refs are right there in the App Platform objects) mirror to
nested NC folders. Dashboards in the root "General" area map to the mapping's root.

Concretely, that reshapes the mapping model:

- **n8n mapping:** `{ n8n_tag → NC folder, mode }` — one tag, one folder, flat.
- **Grafana mapping:** `{ Grafana folder (uid) → NC folder, mode }` — and the
  subtree comes along. A catch-all `General/root → /grafana` is the one-entry case;
  specific folders (`observe → /dashboards/observe`) bind their own subtree.

The master's **longest-prefix path resolver** (Fork G in his §5) is *exactly* the
tool for a folder tree, even though he built it for tags. We inherit it and finally
use it for what it was shaped like.

> **One dropped item, by Dr K's call, same as the master:** Grafana has more than
> dashboards — alerts, datasources, contact points, library panels, playlists — all
> first-class in that `/apis/` list. **This menu is dashboards (and their folders)
> only.** The rest are other dishes for other nights; naming them now so they don't
> sneak onto tonight's ticket. (`link` mode can still *point at* them later without
> us owning their sync.)

### Difference #2 — two cuts of the ingredient: classic JSON vs the v2 YAML schema

The master's file is always one thing: n8n workflow JSON. Ours has **two legitimate
cuts**, because Grafana serves the dashboard two ways:

- **The classic cut — `v1beta1` / `v1`.** `spec` is the dashboard model everyone
  knows (`panels`, `schemaVersion`, `templating`, …). Wrapped in a k8s envelope, or
  taken raw from `/api/dashboards/uid/{uid}`. This is the **safe default** — it's
  what every existing dashboard already is, and what the classic REST API round-trips
  losslessly.
- **The modern cut — `v2` / `v2beta1` (preferred on 13.0.2).** The **new dashboard
  schema** — a restructured spec (elements/layout/annotations as typed lists) that's
  the direction Grafana is going. This is the one that reads beautifully as **YAML**
  and lines up with GitOps / the operator's `GrafanaDashboard` CRD.

**Dr K's question — "can I use the new YAML-style dashboards now?"** Measured answer:
**yes, right now.** `v2` is the *preferred* version the live 13.0.2 apiserver
advertises, and we pulled resources through the App Platform successfully. The catch
to respect: v2 is newer and still stabilizing across Grafana minors, and converting a
v1 dashboard to v2 is a real schema migration, not a reserialize. So the sane design
is **the mapping pins the cut**:

- A field on the mapping — call it `apiVersion` / `format` — records which cut this
  folder's files are (`v1beta1+json`, the default; or `v2+yaml`, opt-in).
- The file's own metadata also stamps its version (like the master stamps
  `n8n_versionId`), so a file is self-describing and a client always knows how to
  read it back.
- File extension follows the cut: `.grafana.json` for the classic cut,
  `.grafana.yaml` for the k8s/v2 cut. (The compound-extension logic — real `.json`/
  `.yaml` tail so the OS opens it, `.grafana.` segment as the hook NC keys the
  icon/actions off — is lifted straight from the master's locked `.n8n.json`
  decision, AGENTS.md "Architectural non-negotiables".)

So we ship the **classic JSON cut first** (parity, low risk, everything already is
this), and the **v2 YAML cut is a second adapter mode** the mapping can opt into —
not a fork of the whole app, just a different serializer + apiVersion on the same
sync core.

### The note on "archive" — the one semantic that doesn't translate 1:1

The master leans hard on one n8n verb: **archive**. Moving a sync workflow out of a
folder *archives* it in n8n (reversible, restorable); purge *deletes* it. Grafana
has **no "archived" state** for a dashboard — a dashboard is present or deleted.
(There's soft-delete/trash on newer Grafana, and folder-level trash, but not the
same archive verb.) So our mode machine keeps the *shape* — `sync / link / unmapped
/ ignored` — but the "unmapped/ignored ⇒ archived in n8n" edge maps to one of:

- **Leave the dashboard in place in Grafana** but drop it from the mapping (the file
  becomes a free-standing copy; Grafana is untouched) — simplest, and arguably more
  honest than n8n's archive.
- **Move it to a dedicated "archive" Grafana folder** we own — emulating archive with
  a real folder, which Grafana *does* have.

This is a Course-2 decision (it only bites the unmapped/ignored edges), not a
blocker. Flagging it so it doesn't get plated wrong later.

---

## The deep link (the "Open in Grafana" plate garnish)

`link` mode and the "Open in …" file action need a URL per resource. Measured off
the live search results, so these are exact:

- **Dashboard:** `/d/<uid>/<slug>` — e.g. `/d/kel4vkt/homepage`.
- **Folder:** `/dashboards/f/<uid>/<slug>` — e.g.
  `/dashboards/f/af397c9y8enswf/observe`.

Built from the `uid` we already carry in metadata + the base URL — zero extra
lookup, same as the master builds his n8n deep link from the workflow id. And the
**operator/GitOps-owned dashboards** (Grafana Operator ships `GrafanaDashboard` +
`GrafanaFolder` CRDs; some dashboards here are provisioned that way) are the *perfect*
`link`-mode citizens — they're owned elsewhere, should never be written back, and
just want a clickable pointer. The master *predicted this exact case* in his §8:
*"some dashboards are operator/marketplace-loaded → naturally mode:link."* He was
cooking for us before we showed up.

---

## The pattern we'd cook (proposed build)

Same **two-plane** cut as the master, and — for now, by Dr K's explicit call —
**we copy his kitchen wholesale and swap the ingredient.** No premature abstraction.

```
  Grafana (the pantry)                         Nextcloud (the pass)
  ────────────────────                         ────────────────────
  Folder (folder.grafana.app)          ⇄       mapped NC folder
    └─ Dashboard (dashboard.grafana.app) ⇄      Name.<uid>.grafana.json (classic)
                                                Name.<uid>.grafana.yaml (v2/k8s cut)
       ▲   GET  /api/dashboards/uid/{uid}              ▲  edit + save
       │   POST /api/dashboards/db  (upsert)           │
       │   Bearer <service-account token>              │
  [GrafanaServiceAccount → token secret]        [nextcloud-grafana app:
   role: Editor (create/edit dashboards+folders)  - re-cut of nextcloud-n8n
   ns: observe (NOT cloud — transformer!)         - admin: Grafana URL + token + Test
                                                   - map a Grafana folder → NC folder
                                                   - modes: sync / link / unmapped / ignored
                                                   - stable thread = dashboard uid in metadata
                                                   - mimetype application/grafana+json + icon]
```

**The Nextcloud app (`nextcloud-grafana`)** — a wholesale copy of `nextcloud-n8n`
with these swaps (this *is* the parity list, in build terms):

- **Identity:** appid `n8n_sync → grafana_sync`; namespace `OCA\N8nSync →
  OCA\GrafanaSync`; the `N8nClient` service → `GrafanaClient`; mimetype
  `application/n8n+json → application/grafana+json`; extension `.n8n.json →
  .grafana.json` (+ `.grafana.yaml` for the v2 cut); tags `n8n:sync/link →
  grafana:sync/link`.
- **The adapter (the only real logic delta):** `list/read/upsert/delete/deeplink`
  retargeted from the n8n workflows API to Grafana's dashboards+folders API; auth
  header `X-N8N-API-KEY → Authorization: Bearer`.
- **The mapping model:** `tag → folder` becomes `Grafana folder(uid) → NC folder`,
  carrying the subtree; add the `format`/`apiVersion` field for the JSON-vs-v2-YAML
  cut. Everything else — mode machine, loop guard, reconcile-by-id, three-way
  rename, mimetype/icon, file actions, `occ` CLI, admin panel — is the master's
  code with the nouns renamed.

**The cluster wiring (`apps/nextcloud/components/grafana`)** — mirror
`components/n8n`:

- A `GrafanaServiceAccount` (role `Editor`, ns `observe`) → token secret. Applied
  out-of-band for now (namespace-transformer gotcha above); later bridged into
  `cloud` via ESO.
- A `grafana-sync-config.sh` (mirroring `n8n-sync-config.sh`) that `occ
  app:install/enable grafana_sync`, sets `grafana_url`, and pipes the token through
  the app's own `occ grafana_sync:set-api-key` (encrypted, same as n8n).
- Env `GRAFANA_SYNC_URL` / `GRAFANA_SYNC_TOKEN` patched onto the Deployment + cron,
  token sourced from the bridged secret.

---

## Where this dish could still fall flat (risks to clear before we cook)

1. **v2 schema churn** *(the one to actually test)* — v2 is preferred but young.
   Round-trip a v2 dashboard file → does `POST` back to Grafana preserve every field,
   or does the apiserver normalize/drop something on write? Ship **classic JSON as
   default**; treat v2/YAML as an opt-in cut we prove separately. Don't let v2 gate
   parity.
2. **The archive verb** — no native n8n-style "archive" in Grafana. Decide the
   unmapped/ignored edge (leave-in-place vs. an archive folder) before wiring delete
   semantics. Course-2.
3. **The namespace transformer** — proven real (above). The SA must land in
   `observe`. Out-of-band apply now; ESO bridge later. Don't let a `cloud`-retagged
   SA silently no-op.
4. **Folder permissions / role scope** — `Editor` can create/edit dashboards and
   create folders; confirm it can also *delete* dashboards and manage the folders we
   map, or bump to `Admin`. Test with the real token, not the docs.
5. **Two hands on one plate** — concurrent edits. Already solved by the master
   (reconcile-by-uid, mode-driven authority, content-hash loop guard). Re-wear it.
6. **Loop guard vs. Grafana's own version bump** — Grafana increments a dashboard
   `version` on every save. Make sure our content-hash guard hashes the *spec we
   sent*, not Grafana's echoed-back object (which may differ by a version int), or a
   push→pull could look like a change and loop. Mirror the master's `n8n_syncedHash`
   discipline carefully here.

---

## The Test Cook (proves or returns this in an afternoon)

The `nextcloud-drupal` "test wear," Grafana edition — one dashboard, down and back:

1. Mint the service-account token (this chapter does the SA; §component below).
2. `GET /api/search` with the token → confirm we see the 5 folders + 21 dashboards
   (read parity).
3. Pull one real dashboard (`/api/dashboards/uid/kel4vkt`) to a
   `Homepage.kel4vkt.grafana.json` file; make a trivial change (bump a panel title).
4. `POST /api/dashboards/db` it back with the token; confirm the change shows in
   Grafana and the `uid` is unchanged (same dashboard, not a new one — the
   reconcile-by-uid promise).
5. Repeat once for the **v2 YAML cut** — pull `.../apis/dashboard.grafana.app/v2/...`
   as YAML, round-trip it, confirm no field loss (risk #1).

If 3–4 hold (the prep says they will), parity is proven and the rest is the master's
menu re-cooked — a technique we've now watched him run twice.

---

## The bigger arc — from three apprentices to one mother sauce

Dr K's long game, stated so it shapes nothing prematurely but isn't forgotten:

- **Today:** one master (`nextcloud-n8n`, starred) and two apprentices cooking his
  menu with new ingredients — `nextcloud-drupal` (BPMN/subsites) and this one,
  `nextcloud-grafana` (dashboards). **Each apprentice copies the whole kitchen** on
  purpose. Concrete beats clever; we want three real, working plates before we
  reduce anything.
- **Tomorrow:** once all three plate the same menu, the shared technique will be
  obvious — the sync core, the mode machine, the metadata contract, the admin/CLI,
  the mimetype/file-action scaffolding are **the same base in all three.** That's the
  **mother sauce**: a common plugin (the union of what repeats) the three finish
  differently with their own adapter (`list/read/upsert/deeplink/auth`). Grafana's
  clean folder model and its two-cut ingredient are *useful stress on that future
  abstraction* — if the mother sauce can hold folders-as-folders *and* tags-as-folders,
  and JSON *and* YAML cuts, it's a real base and not just n8n with the labels filed
  off.

So we build this one **fully concrete** now — and cook every dish in a way that would
*reduce cleanly* into that shared base later. Prep with the finish in mind.

---

## The mise-en-place log — what's measured vs. assumed

- **Measured (live instance, this session):** Grafana `13.0.2` in `observe`,
  reachable at `grafana-service:3000`, `/api/health` ok. App Platform apiserver live
  at `/apis` — `dashboard.grafana.app` (versions `v0alpha1`, `v1beta1`, `v1`,
  `v2alpha1`, `v2beta1`, **`v2` preferred**), `folder.grafana.app`,
  `provisioning.grafana.app`, and more. Pulled a real dashboard object through
  `v1beta1` (`kind: Dashboard`, classic `spec`); listed 5 `Folder` objects through
  `folder.grafana.app/v1beta1` (parent carried as annotation). Classic REST confirms
  5 folders + 21 dashboards, some in root "General". Deep-link forms verified from
  search URLs (`/d/<uid>/<slug>`, `/dashboards/f/<uid>/<slug>`). Operator CRDs
  present: `grafanadashboards`, `grafanafolders`, **`grafanaserviceaccounts`**; three
  `GrafanaServiceAccount`s already live in `observe` (`opentofu` Admin, `grafana-mcp`
  Editor → secret key `token`, `n8n-grafana`). Nextcloud in `cloud`.
- **Measured (auth path):** Grafana takes a **bearer** service-account token
  (`Authorization: Bearer`), minted declaratively by the operator into a k8s Secret;
  the Nextcloud namespace transformer *will* retag a naively-placed SA to `cloud`
  (the gotcha), so it must land in `observe` out-of-band or be bridged.
- **Assumed / to verify (the test cook):** lossless v2/YAML round-trip on write
  (risk #1); `Editor` role covers delete + folder management (risk #4); content-hash
  loop guard survives Grafana's per-save `version` bump (risk #6); the archive-verb
  substitution for the unmapped/ignored edge (risk #2).

---

## Still in mise en place — the prep list (portioning the master's kitchen)

> Dr K's second pass: don't just wave at the menu — **portion the master's whole
> kitchen into bowls** so we copy-and-season each in one motion. And a standing
> rule (Dr K, this session): **a chapter isn't done until Dr K says so.** This one
> stays open. Prep continues here; we only crack open a Chapter 2 once we know the
> line where mise en place actually ends. Everything below is prep — the only thing
> we take to the flame right now is **the appetizer**: the admin panel that
> registers Grafana's URL + token and fires a *Test connection*. Every other bowl
> gets prepped (feature cards written, pipeline built, icons cut) but stays cold.

**What we learned turning the master's kitchen inside-out** (`nextcloud-n8n`, read
file-by-file):

- It's **~155 files**; roughly **40%** copies with a find-and-replace
  (`n8n_sync`→`grafana_sync`, `OCA\N8nSync`→`OCA\GrafanaSync`,
  `application/n8n+json`→`application/grafana+json`, `.n8n.json`→`.grafana.json`).
- The **real cooking is one file:** `Service/N8nClient` → `GrafanaClient`. Auth
  flips `X-N8N-API-KEY`→`Authorization: Bearer`, and `ping()` goes
  `GET /api/v1/workflows?limit=1` → **`GET /api/health`** (both proven Ch. 1).
- The **CI token bootstrap is easier than the master's.** n8n has no headless
  API-key mint; **Grafana does** — admin basic-auth `POST /api/serviceaccounts`
  then `POST /api/serviceaccounts/{id}/tokens` returns a token. The ephemeral-Grafana
  pipeline mints its own token in two curls, no secrets needed.
- The **app-store publish leg must be fused off** (Dr K: can't publish until we're
  done). Keep the GitHub-release half, disable the apps.nextcloud.com upload.

**The bowls** (🟩 copy+rename · 🟨 copy+season · 🟥 cook fresh · ⬜ prep-only, no
code yet · ⛔ leave on the master's shelf):

- **A · Repo meta/legal** — `LICENSE`/`.gitattributes`/`.nvmrc` 🟩; `.gitignore`/
  `.env.example`/`CHANGELOG` 🟨; `README`/`AGENTS`/`CONTRIBUTING`/`SECURITY` 🟥🟨.
- **B · Tooling** — `.php-cs-fixer`/`vitest`/`eslint` 🟩; `composer.json`(psr-4
  `OCA\GrafanaSync\`)/`package.json`/`vite.config.js`/`.devcontainer` 🟨.
- **C · App metadata** — `appinfo/info.xml` 🟨 (id/namespace/descriptions, settings
  trimmed to the connection panels, commands trimmed to set-token+test, mimetype
  repair-steps deferred); `appinfo/routes.php` 🟨 (only `config#testConnection`).
- **D · Admin POC code** 🟥 — `AppInfo/Application` (strip to connection forms),
  `Settings/{AdminSection,InstanceSettings,ConnectionSettings,AdminTest}`,
  `Controller/ConfigController` (testConnection only), `Service/GrafanaClient`
  (rewrite), `Command/{SetToken,TestConnection}`, `Exception/GrafanaApiException`.
- **E · Admin frontend** 🟨 — `templates/admin_test.php` (drop webhook button),
  `js/admin-test.js` (single button), `css/admin-test.css`; a minimal `src/files.js`
  stub so `npm run build` emits `dist/grafana_sync-files.js`.
- **F · Feature files** 🟨 — copy **all 15**; the shape-changer is
  `admin-mapping.feature` (**Grafana folder(uid) → NC folder**, not tag→folder). POC
  makes only `admin-connection` + `lifecycle` pass; the rest ride as `@todo` specs.
- **G · Integration harness** 🟨🟥 — behat/traits/occ/webdav 🟩🟨; `N8nApiTrait`→
  `GrafanaApiTrait` 🟥; `mint-n8n-key.sh`→`mint-grafana-token.sh` (SA-token curls) 🟥;
  `preload-n8n.sh`→`preload-grafana.sh` (sample folder+dashboard) 🟥.
- **H · Unit tests** 🟨⬜ — bootstrap/stubs/phpunit/baseline 🟨; ship one
  `GrafanaClientTest`, rest deferred.
- **I · CI** 🟨🟥 — `pr`/`quality`/`tests`/`package`/`dependabot`/`copilot` 🟨;
  **`integration.yml`** 🟥 (ephemeral `grafana/grafana:13` + mint-token step);
  **`publish.yml`** 🟥 (GitHub Release kept, **store upload disabled**).
- **J · Branding** 🟥 — `img/app.svg`+`app-dark.svg`, `img/grafana.svg`,
  `img/icons/*`; lock `<summary>`/`<description>` + README hero.
- **K · Leave shelved** ⛔⬜ — the whole sync engine (`Service/Sync*`, all
  `Listener/*`, `BackgroundJob/*`, `Migration/*`, mapping/sync `Controller`/`Settings`/
  `Command`, `DAV/*`, `Notification/*`, `config/mimetype*`), `screenshots/`, `.signing/`
  — described in features/README, cooked in later chapters.

**Step order:** (1) bulk-seed A–C,E–I + scripted rename; (2) prune bucket K; (3) cook
D + `GrafanaClient`; (4) season the shape-changers by hand (`info.xml`, `routes`,
`Application`, `composer` autoload, `README`, `admin-mapping.feature`, `integration.yml`,
`publish.yml`, the integration trait + mint/preload scripts); (5) cut branding SVGs;
(6) repo: `gh repo create kubed-io/nextcloud-grafana`, mirror the master's settings/
branch-protection/labels, stage the init commit for Dr K to review; (7) prove locally
(php -l + the Ch. 1 connection curl) and hand over the *Test connection* appetizer.

**The appetizer's exit line:** `occ app:enable grafana_sync` → admin section shows
**Instance (URL)** + **Connection (token)** + a **Test connection** button that goes
green against live Grafana with the Ch. 1 token; `occ grafana_sync:set-token` +
`grafana_sync:test-connection` do it headless. Nothing else wired — on purpose.

### Progress log (this session, prep in motion)
- ✅ Ch. 1 credential proven: `GrafanaServiceAccount` **nextcloud-grafana** (Editor)
  applied to `observe`; token reads all folders + dashboards from the NC pod
  (`cloud`→`observe`). Component lives at `apps/nextcloud/components/grafana`.
- ✅ Seeded `nextcloud-grafana` from the master (tar copy, minus `.git`/`vendor`/
  `dist`/`screenshots`/`.signing`/`saga`); pruned bucket K; ran the identifier
  rename (0 residual `N8nSync`/`n8n_sync`); renamed client/exception/command/trait/
  script files.
- ✅ **Cooked the appetizer (admin-connection POC).** `GrafanaClient` (Bearer auth,
  `ping()` = authenticated `GET /api/folders` — proves the *token*, not just
  reachability, per Dr K); `Application` stripped to the two connection forms;
  `InstanceSettings` (URL) + `ConnectionSettings` (token, encrypted) + `AdminTest`
  (single Test-connection button) + `ConfigController::testConnection` +
  `SetToken`/`TestConnection` occ commands + `GrafanaApiException`. info.xml/routes
  trimmed to match. Frontend: single-button template/JS/CSS + a `src/files.js` stub
  so the bundle builds. One green unit test (`GrafanaApiExceptionTest`).
- ✅ **CI seasoned.** `integration.yml` now stands up an ephemeral `grafana/grafana:13.0.2`
  and self-mints an Editor SA token over admin basic-auth (`bin/mint-grafana-token.sh`)
  + preloads a folder+dashboard (`bin/preload-grafana.sh`) — no secret needed.
  `publish.yml` keeps the GitHub Release, **app-store upload fused off** until
  feature-complete. Harness trimmed to the active connection+lifecycle traits;
  deferred `*Steps` return with the sync chapters.
- ✅ **Features:** all 15 copied/adapted; the shape-changer `admin-mapping.feature`
  now maps **Grafana folder → NC folder** (not tag→folder). Only `admin-connection`
  + `lifecycle` run; the rest ride as `@todo` executable specs.
- ✅ **Branding:** original app mark (`app.svg`/`app-dark.svg` — a folder holding a
  bar chart; deliberately not Grafana's trademarked logo). Descriptions locked in
  `info.xml` + README hero + trademark disclaimer.
- ✅ **Proven green, for real:** JS toolchain (`npm run build` → `dist/grafana_sync-files.js`,
  `eslint` clean, `vitest` pass). And the whole appetizer end-to-end **in the live
  Nextcloud pod**: `php -l` clean, `occ app:enable`, token stored encrypted via
  `occ grafana_sync:set-token`, then `occ grafana_sync:test-connection` →
  *"Authenticated to Grafana (HTTP 200) — token valid, 6 folders visible."* (then
  removed — the real deploy path is `apps/nextcloud/components/grafana`).
- ⏭ *Next: create the GitHub repo (`kubed-io/nextcloud-grafana`), mirror the master's
  settings/branch-protection via `gh`, land the init commit through the PR flow.
  Chapter 1 stays open until Dr K calls it.*

---

<details>
<summary>Appendix — the brief, as Dr K set it</summary>

Goal: make a first-class Grafana plugin that basically does the same as the n8n
plugin. Copy `nextcloud-n8n` wholesale into `nextcloud-grafana`. Feature parity,
but careful about what's relevant. Focus: dashboards → Nextcloud; Grafana's real
**folders** map cleanly (easier than n8n's tags); Grafana's new **YAML** dashboard
API — support both versions, maybe the mapping holds the version; can we use the new
YAML-style dashboards now? Make the `apps/nextcloud/components/grafana` component with
just the service account (see how n8n gets a Grafana SA token; the operator's
`GrafanaServiceAccount` must sit in Grafana's namespace or the transformer fights us);
then use kubectl to pull the SA's token secret for testing. The story: an aspiring
chef mastering dishes, `nextcloud-n8n` the master chef teaching him, Dr K narrating.
The larger arc: one mature base pattern, now drupal + grafana as copies, eventually
abstract the common union into a shared plugin.

</details>
