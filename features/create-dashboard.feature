# Creating dashboards from Nextcloud. These scenarios are the human-readable spec
# for the "author in NC, live in Grafana" flow: a .grafana.json written over WebDAV
# into a mapped folder fires NodeWrittenEvent → the create listener → the dashboard
# appears in Grafana. The Grafana side is asserted over its REST API; the NC stamp over
# DAV PROPFIND of nc:metadata-grafana_uid.

@todo
Feature: Create a dashboard from Nextcloud
  As a Nextcloud user
  I want to create Grafana dashboards by making files
  So that I can author dashboards without opening the Grafana UI

  Background:
    Given the app is connected to Grafana

  Scenario: New file in a mapped sync folder becomes a real dashboard
    Given a folder mapped as "sync" to the Grafana folder "demo"
    When I create a new ".grafana.json" file in that folder via the Files "New" menu
    Then a matching dashboard is created in Grafana
    And the dashboard is created in the "demo" folder
    And the file is stamped with the dashboard's "grafana_uid"

  Scenario: A dashboard file created outside any mapped folder stays unmanaged
    Given a folder that is not mapped
    When I create a ".grafana.json" file in that folder
    Then no dashboard is created in Grafana
    And the file has no "grafana_uid" metadata
    And the file is treated as a plain document (unmapped state)
