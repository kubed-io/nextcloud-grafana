# Folder mappings are metadata on the folder, so membership is resolved by where a
# file lives. (How the app reacts when you MOVE a file across that boundary is in
# move.feature — a sync file moved out becomes "unmapped"; a link can't leave.)
#
# The resolver matches the deepest mapped folder that encloses a file, so nested
# mappings work and the nearest enclosing one wins. Each scenario lands a real file
# over WebDAV and reads the resulting grafana_mapping stamp back, so these are
# server-observable assertions of the mapping resolver.
#
# @todo — the mapping engine lands with the sync chapter; executable spec only.
@todo
Feature: Mapping membership is resolved by folder
  As a Nextcloud admin
  I want mappings to be per-folder metadata
  So that membership is predictable and folders can nest

  # Same precondition as every behavioural feature: the scenarios add a mapping
  # (needs the app enabled) and land a file in it, which fires the create-on-land
  # listener that registers the dashboard in Grafana and stamps the grafana_mapping
  # we assert on — so we need the full connection, not just enablement.
  Background:
    Given the app is connected to Grafana

  Scenario: A file's mapping is the folder it lives in
    Given a folder mapped to the Grafana folder "demo"
    When a managed dashboard file lives in that folder
    Then the file belongs to the "demo" mapping

  Scenario: A file outside every mapped folder belongs to no mapping
    Given a folder that is not mapped
    When a dashboard file lives in that folder
    Then the file belongs to no mapping
    And it is "untracked" if it has no Grafana uid, or "unmapped" if it carries one

  Scenario: Folder mappings are metadata, so a mapped folder can nest in another
    Given a folder mapped to the Grafana folder "outer"
    And a subfolder of it mapped to the Grafana folder "inner"
    When a dashboard file lives in the subfolder
    Then it belongs to the "inner" mapping, not "outer"
    And the nearest enclosing mapping wins
