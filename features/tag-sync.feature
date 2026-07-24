# Bidirectional dashboard-tag sync — the dashboard's tags and its Nextcloud
# system tags are kept as ONE set, so the mirror is as searchable as Grafana.
#
# Two label systems, made equal (minus our control tags):
#
#   • Grafana tags   — free-text strings that live INSIDE the dashboard object
#                      (`dashboard.tags: ["dns","linux"]`). No tag-id API; writing
#                      them = upserting the dashboard. Folders have no tags.
#   • Nextcloud tags — collaborative SYSTEM TAGS (the coloured pills in Files,
#                      searchable via DAV REPORT).
#
# THE RULE OF EQUALITY: after a reconcile a managed dashboard's Grafana tags and
# its Nextcloud system tags hold the same strings, with ONE exclusion — the app's
# reserved namespace `grafana:*` (`grafana:sync`, `grafana:link`, `grafana:ignore`,
# and any future control tag). Reserved tags are the app's control plane: never
# pushed into Grafana, never imported from Grafana as content.
#
# SEARCHABILITY IS MODE-INDEPENDENT: the pull-side systemtag reconcile runs for
# BOTH `sync` and `link` files. A `link` file's body is only a pointer, but its
# Nextcloud system tags still mirror the live Grafana tags — so the mirror filters
# like the origin app no matter the mode. A `link` file is never pushed, so its
# tags flow one way only: Grafana → Nextcloud.
#
# PROVENANCE — a new tag from Nextcloud vs a new tag from Grafana: when the two
# tag sets differ on a string you cannot tell an ADD on one side from a REMOVE on
# the other from the current sets alone. So the app banks the reserved-stripped
# tag set as of the last successful sync in `grafana_syncedTags` (the tag analogue
# of `grafana_syncedHash`) and three-way-merges against it: add-on-either-side is
# additive, remove-on-either-side propagates, and the only genuine conflict (same
# tag added on one side, removed on the other) falls to the reconcile's direction
# of truth — pull → Grafana wins, push → Nextcloud wins.
#
# THREE EDIT SURFACES — the object body is the third: tags live INSIDE the
# dashboard, so a sync file's on-disk JSON already has a `tags` array. That makes
# three editable places, kept as one set:
#   1. Grafana `dashboard.tags`   (edit in Grafana → pull)
#   2. the file body `tags` array (edit the JSON → push)
#   3. Nextcloud system-tag pills (edit the pills → push)
# The FILE BODY is the canonical object; the PILLS are a listener-kept projection.
# Editing either Nextcloud surface updates the other and pushes to Grafana; a pull
# writes Grafana's tags into the body and reconciles the pills. In `link` mode the
# body is a pointer (not the object), so only surfaces 1 and 3 exist and the pills
# are a read-only projection of Grafana.
#
# DESIGN, NOT WIRED: this feature is @todo — CI skips it — until the pull/push
# spine and the `grafana_syncedTags` baseline key are cooked (saga Ch2 Round 2,
# fork H, 🟡 leaning).

@todo
Feature: A dashboard's tags and its Nextcloud system tags stay one set
  As a Grafana admin browsing dashboards in Nextcloud
  I want each dashboard's Grafana tags mirrored as Nextcloud system tags and back
  So that the mirror is as searchable as Grafana and I can re-tag from either side

  Background:
    Given the app is connected to Grafana
    And a folder mapped as "sync" to the Grafana folder "flows"

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
    And the dashboard in Grafana still carries only its original tags

  Scenario: Push writes Nextcloud content tags into Grafana (sync only)
    Given a managed "sync" dashboard file in "flows" with Grafana tags "linux"
    When the admin adds the Nextcloud system tag "urgent" to the file
    And the "flows" mapping is pushed
    Then the dashboard in Grafana is tagged "linux" and "urgent"
    And the reserved "grafana:*" tags are not written to Grafana

  Scenario: Editing a pill updates the file body's tags array (body is canonical)
    Given a managed "sync" dashboard file in "flows" with body tags "linux"
    When the admin adds the Nextcloud system tag "urgent" to the file
    Then the file body's "tags" array becomes "linux" and "urgent"
    And the reserved "grafana:*" pills are not written into the body

  Scenario: Editing the file body's tags array updates the pills
    Given a managed "sync" dashboard file in "flows" with system tags "linux"
    When the admin edits the file body's "tags" array to "linux" and "prod"
    Then the file's Nextcloud system tags become "linux" and "prod"

  Scenario: Editing the file body's tags array pushes to Grafana
    Given a managed "sync" dashboard file in "flows" for a dashboard tagged "linux"
    When the admin edits the file body's "tags" array to "linux" and "prod"
    And the "flows" mapping is pushed
    Then the dashboard in Grafana is tagged "linux" and "prod"

  Scenario: A link file has no editable body tag surface (pills mirror Grafana only)
    Given a folder mapped as "link" to the Grafana folder "reports"
    And a managed "link" file in "reports" for a dashboard tagged "prod"
    When the admin edits the link file body's "tags" array to "prod" and "mine"
    And the "reports" mapping is pushed
    Then the dashboard in Grafana still carries only its original tags
    And the next pull resets the link file's body tags to Grafana's set

  Scenario: A link file never pushes its tags to Grafana
    Given a folder mapped as "link" to the Grafana folder "reports"
    And a managed "link" file in "reports" for a dashboard tagged "prod"
    When the admin adds the Nextcloud system tag "mine" to the link file
    And the "reports" mapping is pushed
    Then the dashboard in Grafana still carries only its original tags

  Scenario: A tag added in Nextcloud since the last sync is added in Grafana
    Given a managed "sync" file last synced with tags "linux"
    And the file now also has the Nextcloud system tag "urgent"
    And the dashboard in Grafana still has only "linux"
    When the "flows" mapping is pushed
    Then the dashboard in Grafana is tagged "linux" and "urgent"

  Scenario: A tag removed in Grafana since the last sync is removed in Nextcloud
    Given a managed "sync" file last synced with tags "linux" and "old"
    And the dashboard in Grafana now has only "linux"
    When the "flows" mapping is pulled
    Then the file's Nextcloud system tags are exactly "linux"
    And the file no longer has the system tag "old"

  Scenario: Independent changes on both sides both survive a reconcile
    Given a managed "sync" file last synced with tags "linux"
    And the file now also has the Nextcloud system tag "urgent"
    And the dashboard in Grafana now also has "prod"
    When the "flows" mapping is reconciled
    Then the resulting tag set on both sides is "linux", "urgent", and "prod"

  Scenario: A genuine conflict falls to the reconcile's direction of truth
    Given a managed "sync" file last synced with tags "linux" and "staging"
    And the admin removed the Nextcloud system tag "staging" from the file
    And someone re-added "staging" in Grafana since the last sync
    When the "flows" mapping is pulled
    Then the file's Nextcloud system tags include "staging"
    And when the "flows" mapping is instead pushed
    Then "staging" is removed from the dashboard in Grafana
