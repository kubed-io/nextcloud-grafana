# Notes, decisions and history for this feature: AGENTS.md#tag-sync

Feature: A dashboard's tags and its Nextcloud system tags stay one set
  As a Grafana admin browsing dashboards in Nextcloud
  I want each dashboard's Grafana tags mirrored as Nextcloud system tags and back
  So that the mirror is as searchable as Grafana and I can re-tag from either side

  Background:
    Given the app is connected to Grafana
    And a folder mapped as "sync" to the Grafana folder "flows"

  # ── pull mirror: Grafana → NC pills (sync AND link) ───────────────────────────

  @grafana @in-grafana @occ @ui @unbuilt
  Scenario: Pull mirrors Grafana tags onto the Nextcloud file as system tags
    Given Grafana has a dashboard in "flows" tagged "dns" and "linux"
    When the "flows" mapping is pulled
    Then the dashboard's file has the Nextcloud system tags "dns" and "linux"
    And the file can be found by a Nextcloud tag search for "linux"

  @grafana @in-grafana @occ @unbuilt
  Scenario: The reserved namespace is never imported as a content tag
    Given Grafana has a dashboard in "flows" tagged "linux" and "grafana:sync"
    When the "flows" mapping is pulled
    Then the dashboard's file has the Nextcloud system tag "linux"
    And the file has no content tag "grafana:sync"
    And the file's "grafana:sync" mode pill is unaffected

  @grafana @in-grafana @occ @ui @unbuilt
  Scenario: Pull mirrors tags even for a link mapping (searchability, not push)
    Given a folder mapped as "link" to the Grafana folder "reports"
    And Grafana has a dashboard in "reports" tagged "prod"
    When the "reports" mapping is pulled
    Then the link file has the Nextcloud system tag "prod"
    And the file can be found by a Nextcloud tag search for "prod"

  # ── push: NC pills → Grafana (sync only) ──────────────────────────────────────

  @admin @in-nextcloud @occ @ui @unbuilt
  Scenario: Push writes Nextcloud content tags into Grafana (sync only)
    Given a managed "sync" dashboard file in "flows" with Grafana tags "linux"
    When the admin adds the Nextcloud system tag "urgent" to the file
    And the "flows" mapping is pushed
    Then the dashboard in Grafana is tagged "linux" and "urgent"
    And the reserved "grafana:*" tags are not written to Grafana

  @admin @in-nextcloud @occ @ui @unbuilt
  Scenario: A link file never pushes its tags to Grafana
    Given a folder mapped as "link" to the Grafana folder "reports"
    And a managed "link" file in "reports" for a dashboard tagged "prod"
    When the admin adds the Nextcloud system tag "mine" to the link file
    And the "reports" mapping is pushed
    Then the dashboard in Grafana still carries only its original tags

  # ── the reactive pill edit (Slice A) — auto-propagates, honours the timing knob ─

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Adding a pill pushes the tag to Grafana immediately when timing is "sync"
    Given the push timing is "sync"
    And a managed "sync" dashboard file in "flows" with Grafana tags "linux"
    When the admin adds the Nextcloud system tag "urgent" to the file
    Then the dashboard in Grafana is tagged "linux" and "urgent" without a manual push
    And the file has the Nextcloud system tag "urgent"

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Adding a pill queues the tag push when timing is "async"
    Given the push timing is "async"
    And a managed "sync" dashboard file in "flows" with Grafana tags "linux"
    When the admin adds the Nextcloud system tag "urgent" to the file
    Then a tag-reconcile job is queued for the file
    And the dashboard in Grafana is still tagged only "linux"
    When the background queue runs
    Then the dashboard in Grafana is tagged "linux" and "urgent"

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Removing a pill removes the tag from Grafana on its own
    Given the push timing is "sync"
    And a managed "sync" file in "flows" last synced with tags "linux" and "old"
    When the admin removes the Nextcloud system tag "old" from the file
    Then the dashboard in Grafana is tagged "linux" without a manual push
    And the file has no content tag "old"

  # notes: AGENTS.md#editing-a-pill-updates-the-file-bodys-tags-array-body-is-canonical

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Editing a pill updates the file body's tags array (body is canonical)
    Given a managed "sync" dashboard file in "flows" with body tags "linux"
    When the admin adds the Nextcloud system tag "urgent" to the file
    Then the file body's "tags" array becomes "linux" and "urgent"
    And the reserved "grafana:*" pills are not written into the body

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Editing the file body's tags array updates the pills and pushes to Grafana
    Given a managed "sync" dashboard file in "flows" for a dashboard tagged "linux"
    When the admin edits the file body's "tags" array to "linux" and "prod"
    Then the file's Nextcloud system tags become "linux" and "prod"
    When the "flows" mapping is pushed
    Then the dashboard in Grafana is tagged "linux" and "prod"

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Removing a tag from the file body's tags array removes the pill and the Grafana tag
    Given a managed "sync" dashboard file in "flows" for a dashboard tagged "linux" and "old"
    When the admin edits the file body's "tags" array to "linux"
    Then the file's Nextcloud system tags become "linux"
    When the "flows" mapping is pushed
    Then the dashboard in Grafana is tagged "linux"

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: A link file has no editable body tag surface (pills mirror Grafana only)
    Given a folder mapped as "link" to the Grafana folder "reports"
    And a managed "link" file in "reports" for a dashboard tagged "prod"
    When the admin edits the link file body's "tags" array to "prod" and "mine"
    And the "reports" mapping is pushed
    Then the dashboard in Grafana still carries only its original tags
    And the next pull resets the link file's body tags to Grafana's set

  # ── the baseline three-way merge (add-vs-remove provenance) ───────────────────

  @user @in-nextcloud @occ @ui @unbuilt
  Scenario: A tag added in Nextcloud since the last sync is added in Grafana
    Given a managed "sync" file in "flows" last synced with tags "linux"
    And the file now also has the Nextcloud system tag "urgent"
    And the dashboard in Grafana still has only "linux"
    When the "flows" mapping is pushed
    Then the dashboard in Grafana is tagged "linux" and "urgent"

  @grafana @in-grafana @occ @ui @unbuilt
  Scenario: A tag removed in Grafana since the last sync is removed in Nextcloud
    Given a managed "sync" file in "flows" last synced with tags "linux" and "old"
    And the dashboard in Grafana now has only "linux"
    When the "flows" mapping is pulled
    Then the file's Nextcloud system tags are exactly "linux"

  @admin @occ @ui @unbuilt
  Scenario: Independent changes on both sides both survive a reconcile
    Given a managed "sync" file in "flows" last synced with tags "linux"
    And the file now also has the Nextcloud system tag "urgent"
    And the dashboard in Grafana now also has "prod"
    When the "flows" mapping is reconciled
    Then the resulting tag set on both sides is "linux", "urgent", and "prod"

  @admin @occ @ui @unbuilt
  Scenario: An add on one side and an unrelated remove on the other both apply
    Given a managed "sync" file in "flows" last synced with tags "linux" and "old"
    And the file now also has the Nextcloud system tag "urgent"
    And the dashboard in Grafana now has only "linux"
    When the "flows" mapping is reconciled
    Then the resulting tag set on both sides is "linux" and "urgent"
    And the "old" tag is gone from both sides

  @admin @occ @ui @unbuilt
  Scenario: A genuine conflict falls to the reconcile's direction of truth
    Given a managed "sync" file in "flows" last synced with tags "linux" and "staging"
    And the admin removed the Nextcloud system tag "staging" from the file
    And someone re-added "staging" in Grafana since the last sync
    When the "flows" mapping is pulled
    Then the file's Nextcloud system tags include "staging"
    When the "flows" mapping is instead pushed
    Then "staging" is removed from the dashboard in Grafana

  # Edge case the sibling hit: a purely-numeric tag name must survive the merge as a
  # string, not be silently cast to an int array key (TagMerge NUL-prefixes its keys).
  @admin @occ @unbuilt
  Scenario: A purely-numeric tag name survives the reconcile as a string
    Given a managed "sync" dashboard file in "flows" with Grafana tags "2024" and "linux"
    When the admin adds the Nextcloud system tag "prod" to the file
    And the "flows" mapping is reconciled
    Then the resulting tag set on both sides is "2024", "linux", and "prod"
    And the tag "2024" is a string, not coerced to a number

  # notes: AGENTS.md#an-unchanged-dashboard-is-skipped-by-the-pull

  @admin @occ @unbuilt
  Scenario: An unchanged dashboard is skipped by the pull
    Given a managed "sync" dashboard file in "flows" whose body and tags match Grafana
    When the "flows" mapping is pulled
    Then the file is not rewritten
    And its Nextcloud system tags are unchanged

  @grafana @in-grafana @occ @ui @unbuilt
  Scenario: A change in Grafana pulls the new body and reconciles the pills
    Given a managed "sync" dashboard file in "flows" whose dashboard changed in Grafana
    When the "flows" mapping is pulled
    Then the file body is updated from Grafana
    And the file's Nextcloud system tags match the dashboard's Grafana tags

  # ── scope: an unmapped/ignored file is a plain Nextcloud file ──────────────────

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Editing tags on an unmapped file has no Grafana tag-sync side effect
    Given a dashboard file that has become "unmapped"
    When the admin adds the Nextcloud system tag "urgent" to the file
    Then no tag push to Grafana is triggered
    And no tag-reconcile job is queued
    And the tag is just a plain Nextcloud system tag on the file

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Editing tags on an ignored file has no Grafana tag-sync side effect
    Given a managed "sync" dashboard file in "flows" tagged "grafana:ignore"
    When the admin adds the Nextcloud system tag "urgent" to the file
    Then no tag push to Grafana is triggered
    And the tag is just a plain Nextcloud system tag on the file

  # ── pruning: edges are swept, catalog definitions are not ─────────────────────

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: A dropped tag is pruned from the mirror edge, not from the shared catalog
    Given a managed "sync" file in "flows" last synced with tags "linux" and "old"
    And the Nextcloud system tag "old" is also pinned on an unrelated non-dashboard file
    When the admin removes the "old" pill from the dashboard file
    And the "flows" mapping is pushed
    Then the dashboard in Grafana is tagged "linux"
    And the "old" system-tag definition still exists
    And the unrelated file still carries the "old" pill

  @admin @occ @unbuilt
  Scenario: Reconcile never mints a definition it is about to drop
    Given a managed "sync" file in "flows" last synced with tags "linux"
    And the dashboard in Grafana now has only "linux"
    When the "flows" mapping is reconciled
    Then no new tag definition is created on either side

  @admin @occ @unbuilt
  Scenario: The optional catalog sweep keeps any tag still used, and is NC-side only
    Given a non-reserved Nextcloud system tag "shared" that is on no managed file
    But the tag "shared" is still pinned on an unrelated non-dashboard file
    When an admin runs the optional catalog sweep
    Then the "shared" definition is kept
    # (Grafana has no tag catalog to sweep — a Grafana tag exists only while a dashboard carries it.)
