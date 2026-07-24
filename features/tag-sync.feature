# Bidirectional dashboard-tag sync — a dashboard's tags and its Nextcloud system
# tags are kept as ONE set, so the mirror is as searchable as Grafana.
#
# Imported from the finalized n8n sibling (nextcloud-n8n Chapter 5 §5.6 — the
# TagMerge / TagSyncService / ContentTagListener / ReconcileTagsJob engine + the
# *_syncedTags baseline) and re-cut for Grafana's ingredient. DESIGN, NOT WIRED:
# the whole feature is @todo — CI skips it — until (1) the sibling's tag PR merges
# (we port the engine as our base) and (2) the file-lifecycle mode machine (Course 4)
# lands, since tag sync scopes itself to mapped files and no-ops on unmapped/ignored.
#
# Two label systems, made equal (minus our control tags):
#
#   • Grafana tags   — free-text strings that live INSIDE the dashboard object
#                      (`dashboard.tags: ["dns","linux"]`). No tag-id API, no tag
#                      catalog: a tag exists exactly when some dashboard carries the
#                      string, and writing tags = upserting the dashboard. Folders
#                      have no tags.
#   • Nextcloud tags — collaborative SYSTEM TAGS (the coloured pills in Files,
#                      searchable via DAV REPORT).
#
# THE RULE OF EQUALITY: after a reconcile a managed dashboard's Grafana tags and its
# Nextcloud system tags hold the same strings, with ONE exclusion — the app's
# reserved namespace `grafana:*` (`grafana:sync`, `grafana:link`, `grafana:unmapped`,
# `grafana:ignore`, and any future control tag). Reserved tags are the app's control
# plane: never pushed into Grafana, never imported from Grafana as content.
#
# THREE EDIT SURFACES — the object body is the third: tags live INSIDE the dashboard,
# so a sync file's on-disk JSON already has a `tags` array. Three editable places,
# kept as one set:
#   1. Grafana `dashboard.tags`   (edit in Grafana → pull)
#   2. the file body `tags` array (edit the JSON → push)
#   3. Nextcloud system-tag pills (edit the pills → push)
# The FILE BODY is the canonical object; the PILLS are a listener-kept projection. In
# `link` mode the body is a pointer, so only surfaces 1 and 3 exist and the pills are
# a read-only projection of Grafana.
#
# NO EXTRA BUTTON FOR TAGS (n8n "Slice A" — the reactive engine): a content-pill
# add/remove on a managed `sync` file is caught by a dedicated ContentTagListener
# (TagAssignedEvent/TagUnassignedEvent for CONTENT tags, distinct from the reserved
# `grafana:ignore` the mode listener watches) and reconciled to Grafana ON ITS OWN —
# no "Sync to Grafana" click. It honours the SAME `timing` knob as the body writeback:
#   • `sync`  — reconcile inline during the request.
#   • `async` — enqueue a per-file ReconcileTagsJob the cron worker runs next tick.
#
# GRAFANA IS THE EASIER COOK — three backend knobs, all simpler than n8n:
#   1. TAGS ARE BODY-NATIVE, so there is NO tags-only side-channel. n8n reconciles a
#      pill to a DECOUPLED tag endpoint (setWorkflowTags → PUT /workflows/{id}/tags)
#      and must re-stamp its body hash so the silent body-tags write isn't re-pushed.
#      Grafana has no such endpoint: `dashboard.tags` IS the object, so a pill edit
#      updates the body's `tags` and rides the EXISTING body upsert — one push path,
#      no re-stamp dance. The loop guard is the SyncGuard + `grafana_syncedHash` we
#      already ship (a real tag change is a real body change — correct, not a hazard).
#   2. THE PROTECTED-TAGS SET IS EMPTY. n8n's sharpest hazard — the mapping tag is a
#      content tag (n8n maps a folder BY TAG, so dropping that pill would unbind +
#      prune) — DOES NOT EXIST here: we map by real Grafana folders, so no content tag
#      is ever load-bearing. The whole force-keep-the-mapping-pill / eject-via-ignore /
#      union-of-mapping-tags apparatus evaporates. Protected set = [].
#   3. PULL CHANGE-DETECTION IS A BRANCH SHORTER. Because tags live in the body, a
#      Grafana-side tag change IS a body change `grafana_syncedHash` catches — n8n's
#      separate "tags-only changed in the source" branch collapses into the ordinary
#      body-changed path. Detection is just: skip-if-unchanged vs body-changed → write
#      + reconcile pills.
#
# PROVENANCE — add-on-one-side vs remove-on-the-other: when the two sets differ on a
# string you cannot tell an ADD from a REMOVE from the current sets alone. So the app
# banks the reserved-stripped tag set as of the last successful sync in
# `grafana_syncedTags` (the tag analogue of `grafana_syncedHash`) and three-way-merges
# against it: add-on-either-side keeps the tag, remove-on-either-side drops it (those
# are disjoint against a single baseline, so the merge is deterministic). The only
# genuine conflict — same tag added on one side, removed on the other since baseline —
# falls to the reconcile's direction of truth (pull → Grafana wins, push → NC wins).
#
# PRUNING — edges swept, catalog definitions not. Assignment EDGES (tag-on-this-file)
# are pruned both ways: remove-on-either-side drops the edge. Catalog DEFINITIONS are
# NOT auto-pruned — an NC system tag may be pinned on unrelated files. Reconcile is
# prune-free by construction (compute the merged set first, write once, never mint a
# pill it's about to drop). An OPTIONAL, opt-in `occ` sweep (dry-run first, never on
# the hot path) can GC NC definitions orphaned everywhere. GRAFANA SUBTRACTION: Grafana
# has NO tag catalog — a tag is just a string that vanishes with its last dashboard —
# so there are no Grafana-side definitions to sweep; the sweep is NC-side-only here.
#
# SCOPE — a mapped-folder feature: every behaviour here applies ONLY to a file managed
# by a mapping. An `unmapped` or `ignored` file is a plain Nextcloud file — its pills
# are ordinary system tags with NO Grafana side effect — so the listener + reconcile
# must no-op on it.

@todo
Feature: A dashboard's tags and its Nextcloud system tags stay one set
  As a Grafana admin browsing dashboards in Nextcloud
  I want each dashboard's Grafana tags mirrored as Nextcloud system tags and back
  So that the mirror is as searchable as Grafana and I can re-tag from either side

  Background:
    Given the app is connected to Grafana
    And a folder mapped as "sync" to the Grafana folder "flows"

  # ── pull mirror: Grafana → NC pills (sync AND link) ───────────────────────────

  Scenario: Pull mirrors Grafana tags onto the Nextcloud file as system tags
    Given Grafana has a dashboard in "flows" tagged "dns" and "linux"
    When the "flows" mapping is pulled
    Then the dashboard's file has the Nextcloud system tags "dns" and "linux"
    And the file can be found by a Nextcloud tag search for "linux"

  Scenario: The reserved namespace is never imported as a content tag
    Given Grafana has a dashboard in "flows" tagged "linux" and "grafana:sync"
    When the "flows" mapping is pulled
    Then the dashboard's file has the Nextcloud system tag "linux"
    And the file has no content tag "grafana:sync"
    And the file's "grafana:sync" mode pill is unaffected

  Scenario: Pull mirrors tags even for a link mapping (searchability, not push)
    Given a folder mapped as "link" to the Grafana folder "reports"
    And Grafana has a dashboard in "reports" tagged "prod"
    When the "reports" mapping is pulled
    Then the link file has the Nextcloud system tag "prod"
    And the file can be found by a Nextcloud tag search for "prod"

  # ── push: NC pills → Grafana (sync only) ──────────────────────────────────────

  Scenario: Push writes Nextcloud content tags into Grafana (sync only)
    Given a managed "sync" dashboard file in "flows" with Grafana tags "linux"
    When the admin adds the Nextcloud system tag "urgent" to the file
    And the "flows" mapping is pushed
    Then the dashboard in Grafana is tagged "linux" and "urgent"
    And the reserved "grafana:*" tags are not written to Grafana

  Scenario: A link file never pushes its tags to Grafana
    Given a folder mapped as "link" to the Grafana folder "reports"
    And a managed "link" file in "reports" for a dashboard tagged "prod"
    When the admin adds the Nextcloud system tag "mine" to the link file
    And the "reports" mapping is pushed
    Then the dashboard in Grafana still carries only its original tags

  # ── the reactive pill edit (Slice A) — auto-propagates, honours the timing knob ─

  Scenario: Adding a pill pushes the tag to Grafana immediately when timing is "sync"
    Given the push timing is "sync"
    And a managed "sync" dashboard file in "flows" with Grafana tags "linux"
    When the admin adds the Nextcloud system tag "urgent" to the file
    Then the dashboard in Grafana is tagged "linux" and "urgent" without a manual push
    And the file has the Nextcloud system tag "urgent"

  Scenario: Adding a pill queues the tag push when timing is "async"
    Given the push timing is "async"
    And a managed "sync" dashboard file in "flows" with Grafana tags "linux"
    When the admin adds the Nextcloud system tag "urgent" to the file
    Then a tag-reconcile job is queued for the file
    And the dashboard in Grafana is still tagged only "linux"
    When the background queue runs
    Then the dashboard in Grafana is tagged "linux" and "urgent"

  Scenario: Removing a pill removes the tag from Grafana on its own
    Given the push timing is "sync"
    And a managed "sync" file in "flows" last synced with tags "linux" and "old"
    When the admin removes the Nextcloud system tag "old" from the file
    Then the dashboard in Grafana is tagged "linux" without a manual push
    And the file has no content tag "old"

  # ── the body↔pills projection (n8n "Slice B") — still @todo even in the sibling ─
  # For Grafana this is simpler than n8n: the body tags ARE what gets pushed, so a
  # pill edit and a body-tags edit both ride the one upsert. Kept @todo until Slice B.

  Scenario: Editing a pill updates the file body's tags array (body is canonical)
    Given a managed "sync" dashboard file in "flows" with body tags "linux"
    When the admin adds the Nextcloud system tag "urgent" to the file
    Then the file body's "tags" array becomes "linux" and "urgent"
    And the reserved "grafana:*" pills are not written into the body

  Scenario: Editing the file body's tags array updates the pills and pushes to Grafana
    Given a managed "sync" dashboard file in "flows" for a dashboard tagged "linux"
    When the admin edits the file body's "tags" array to "linux" and "prod"
    Then the file's Nextcloud system tags become "linux" and "prod"
    When the "flows" mapping is pushed
    Then the dashboard in Grafana is tagged "linux" and "prod"

  Scenario: Removing a tag from the file body's tags array removes the pill and the Grafana tag
    Given a managed "sync" dashboard file in "flows" for a dashboard tagged "linux" and "old"
    When the admin edits the file body's "tags" array to "linux"
    Then the file's Nextcloud system tags become "linux"
    When the "flows" mapping is pushed
    Then the dashboard in Grafana is tagged "linux"

  Scenario: A link file has no editable body tag surface (pills mirror Grafana only)
    Given a folder mapped as "link" to the Grafana folder "reports"
    And a managed "link" file in "reports" for a dashboard tagged "prod"
    When the admin edits the link file body's "tags" array to "prod" and "mine"
    And the "reports" mapping is pushed
    Then the dashboard in Grafana still carries only its original tags
    And the next pull resets the link file's body tags to Grafana's set

  # ── the baseline three-way merge (add-vs-remove provenance) ───────────────────

  Scenario: A tag added in Nextcloud since the last sync is added in Grafana
    Given a managed "sync" file in "flows" last synced with tags "linux"
    And the file now also has the Nextcloud system tag "urgent"
    And the dashboard in Grafana still has only "linux"
    When the "flows" mapping is pushed
    Then the dashboard in Grafana is tagged "linux" and "urgent"

  Scenario: A tag removed in Grafana since the last sync is removed in Nextcloud
    Given a managed "sync" file in "flows" last synced with tags "linux" and "old"
    And the dashboard in Grafana now has only "linux"
    When the "flows" mapping is pulled
    Then the file's Nextcloud system tags are exactly "linux"

  Scenario: Independent changes on both sides both survive a reconcile
    Given a managed "sync" file in "flows" last synced with tags "linux"
    And the file now also has the Nextcloud system tag "urgent"
    And the dashboard in Grafana now also has "prod"
    When the "flows" mapping is reconciled
    Then the resulting tag set on both sides is "linux", "urgent", and "prod"

  Scenario: An add on one side and an unrelated remove on the other both apply
    Given a managed "sync" file in "flows" last synced with tags "linux" and "old"
    And the file now also has the Nextcloud system tag "urgent"
    And the dashboard in Grafana now has only "linux"
    When the "flows" mapping is reconciled
    Then the resulting tag set on both sides is "linux" and "urgent"
    And the "old" tag is gone from both sides

  Scenario: A genuine conflict falls to the reconcile's direction of truth
    Given a managed "sync" file in "flows" last synced with tags "linux" and "staging"
    And the admin removed the Nextcloud system tag "staging" from the file
    And someone re-added "staging" in Grafana since the last sync
    When the "flows" mapping is pulled
    Then the file's Nextcloud system tags include "staging"
    When the "flows" mapping is instead pushed
    Then "staging" is removed from the dashboard in Grafana

  # ── pull change-detection: only write what changed (a branch shorter than n8n) ──

  Scenario: An unchanged dashboard is skipped by the pull
    Given a managed "sync" dashboard file in "flows" whose body and tags match Grafana
    When the "flows" mapping is pulled
    Then the file is not rewritten
    And its Nextcloud system tags are unchanged

  Scenario: A change in Grafana pulls the new body and reconciles the pills
    Given a managed "sync" dashboard file in "flows" whose dashboard changed in Grafana
    When the "flows" mapping is pulled
    Then the file body is updated from Grafana
    And the file's Nextcloud system tags match the dashboard's Grafana tags

  # ── scope: an unmapped/ignored file is a plain Nextcloud file ──────────────────

  Scenario: Editing tags on an unmapped file has no Grafana tag-sync side effect
    Given a dashboard file that has become "unmapped"
    When the admin adds the Nextcloud system tag "urgent" to the file
    Then no tag push to Grafana is triggered
    And no tag-reconcile job is queued
    And the tag is just a plain Nextcloud system tag on the file

  Scenario: Editing tags on an ignored file has no Grafana tag-sync side effect
    Given a managed "sync" dashboard file in "flows" tagged "grafana:ignore"
    When the admin adds the Nextcloud system tag "urgent" to the file
    Then no tag push to Grafana is triggered
    And the tag is just a plain Nextcloud system tag on the file

  # ── pruning: edges are swept, catalog definitions are not ─────────────────────

  Scenario: A dropped tag is pruned from the mirror edge, not from the shared catalog
    Given a managed "sync" file in "flows" last synced with tags "linux" and "old"
    And the Nextcloud system tag "old" is also pinned on an unrelated non-dashboard file
    When the admin removes the "old" pill from the dashboard file
    And the "flows" mapping is pushed
    Then the dashboard in Grafana is tagged "linux"
    And the "old" system-tag definition still exists
    And the unrelated file still carries the "old" pill

  Scenario: Reconcile never mints a definition it is about to drop
    Given a managed "sync" file in "flows" last synced with tags "linux"
    And the dashboard in Grafana now has only "linux"
    When the "flows" mapping is reconciled
    Then no new tag definition is created on either side

  Scenario: The optional catalog sweep keeps any tag still used, and is NC-side only
    Given a non-reserved Nextcloud system tag "shared" that is orphaned (on no managed file)
    But the tag "shared" is still pinned on an unrelated non-dashboard file
    When an admin runs the optional catalog sweep
    Then the "shared" definition is kept
    # (Grafana has no tag catalog to sweep — a Grafana tag exists only while a dashboard carries it.)
