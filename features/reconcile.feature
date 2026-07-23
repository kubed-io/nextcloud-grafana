# The two manual sync controls in admin settings, each SCOPED TO A MAPPING:
#   - "Sync from Grafana" (pull): bring the mapping's tagged dashboards into its folder.
#   - "Sync to Grafana"   (push): send the mapping's sync files up to Grafana.
# Both reconcile the mapped folder against the dashboards carrying that mapping's
# tag, and both FULLY IGNORE "unmapped" files — those live outside any mapping, so
# a mapping-scoped sync never sees them. Pruning here is therefore mapping-scoped:
# it only ever concerns files/dashboards inside the mapping.
#
# (The "merge" that happens when you MOVE an unmapped file back into a mapping that
# already holds its dashboard is a MOVE-time behaviour, not a sync — see
# move.feature. The duplicate state, one unmapped + one mapped with the same id, is
# perfectly fine and intentional; a sync does not touch the unmapped one.)

@todo
Feature: Manual per-mapping sync (Sync from / Sync to Grafana)
  As a Nextcloud admin
  I want the per-mapping sync buttons to reconcile just that mapping
  So that a folder matches its Grafana tag on demand, ignoring everything else

  Background:
    Given the app is connected to Grafana
    And a folder mapped as "sync" to the Grafana tag "nextcloud:alpha"

  Scenario: Sync from Grafana pulls the tagged dashboards into the mapped folder
    Given Grafana has dashboards tagged "nextcloud:alpha"
    And an unmapped dashboard file exists outside every mapping
    When the admin clicks "Sync from Grafana" for the "nextcloud:alpha" mapping
    Then each "nextcloud:alpha" dashboard appears as a file in the mapped folder
    And existing files are updated in place — matched by dashboard id, never duplicated
    And a mapped file whose dashboard no longer carries the tag is pruned from the folder
    And the unmapped file is left untouched (it is outside the mapping's scope)

  Scenario: Sync to Grafana pushes the mapping's sync files up to Grafana
    Given the "nextcloud:alpha" folder has sync dashboard files with local changes
    And an unmapped dashboard file exists outside every mapping
    When the admin clicks "Sync to Grafana" for the "nextcloud:alpha" mapping
    Then each sync file in the folder is pushed to its dashboard in Grafana
    And the unmapped file is not pushed (it is outside the mapping's scope)
