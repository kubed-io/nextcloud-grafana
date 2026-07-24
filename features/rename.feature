# Three-way name agreement in sync mode: filename stem ⇄ JSON "title" ⇄ Grafana name.
# The stable link is the dashboard uid, so none of these break the connection.
# LIVE: rename/edit go over WebDAV; the file-locked reconcile runs in
# ReconcileNameJob, so the steps drain that job class with the occ worker before
# asserting both the file (PROPFIND/GET) and Grafana (REST) sides.

@todo
Feature: Renaming keeps file, JSON, and Grafana in agreement
  As a Nextcloud user
  I want renames to propagate everywhere
  So that the file name, its JSON name, and the Grafana dashboard name never drift

  Background:
    Given the app is installed and enabled

  Scenario: Renaming the file updates the backend JSON name and Grafana
    Given a managed "sync" dashboard file named "Old Name.grafana.json"
    When I rename the file to "New Name.grafana.json"
    Then the JSON "title" field inside the file becomes "New Name"
    And the dashboard is renamed to "New Name" in Grafana

  Scenario: Editing the JSON name renames the file and updates Grafana
    Given a managed "sync" dashboard file
    When I edit the file and change the JSON "title" field to "Renamed In JSON"
    Then the file is renamed to "Renamed In JSON.grafana.json"
    And the dashboard is renamed to "Renamed In JSON" in Grafana

  Scenario: Renaming never breaks the link
    Given a managed "sync" dashboard file with a known "grafana_uid"
    When the file is renamed by any of the above means
    Then the "grafana_uid" metadata is unchanged
