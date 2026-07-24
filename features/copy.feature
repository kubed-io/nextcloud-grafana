# Copying a dashboard file. Where a MOVE is "the same dashboard" (see move.feature),
# a COPY is ALWAYS a brand-new instance. A copy never inherits the original's Grafana
# identity — its metadata (grafana_uid, version, mapping, mode) is stripped the moment
# it is copied. Copy is therefore the single safest point to strip metadata:
# whatever the source was (sync, link, unmapped), the copy starts clean.
#
# Nextcloud distinguishes copy from move at the event layer (NodeCopiedEvent vs
# NodeRenamedEvent), which is what lets us treat them oppositely.

@todo
Feature: Copying a dashboard file always makes a new instance
  As a Nextcloud user
  I want a copy to be a fresh dashboard, never a hijack of the original
  So that duplicating a file is safe and predictable

  Background:
    Given the app is connected to Grafana
    And a folder mapped as "sync" to the Grafana folder "alpha"

  Scenario: Copy within a mapped sync folder becomes a new dashboard in Grafana
    Given a managed "sync" dashboard file in the "alpha" folder
    When I copy the file within the "alpha" folder
    Then the copy carries no inherited "grafana_uid"
    And the copy is registered as a NEW dashboard in Grafana with its own uid
    And the original file and dashboard are unchanged
    And there are now two distinct dashboards in Grafana

  Scenario: Copy to outside any mapping is a plain untracked file
    Given a managed "sync" dashboard file in the "alpha" folder
    When I copy the file to a folder that is not mapped
    Then the copy has no Grafana metadata
    And no dashboard is created in Grafana for the copy
    And the copy is treated as a plain document

  Scenario: Copy of an unmapped file strips its metadata wherever it lands
    Given an unmapped dashboard file that still carries its "grafana_uid"
    When I copy the file to a folder that is not mapped
    Then the copy has no Grafana metadata
    And the original unmapped file keeps its "grafana_uid"

  Scenario: Copy of an unmapped file into a mapping becomes a new dashboard
    Given an unmapped dashboard file that still carries its "grafana_uid"
    When I copy the file into the "alpha" folder
    Then the copy carries no inherited "grafana_uid"
    And the copy is registered as a NEW dashboard in Grafana with its own uid
    And the original unmapped file's dashboard is not restored or duplicated
