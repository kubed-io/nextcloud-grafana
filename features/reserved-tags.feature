# Reserved Grafana tag — the optional, per-dashboard EXCLUDE switch.
#
# A mapping binds ONE Grafana tag (ANY name — e.g. "team:flows", "myfoobarflows"; the
# "nextcloud:" prefix some examples use is just a convention, NOT required) to a
# folder + a mode (`sync` / `link`). That mode is AUTHORITATIVE for every dashboard
# in the mapping — there is no per-dashboard sync/link override. The only reserved
# tag the app honours is the exclude:
#
#   Grafana:ignore  — exclude this one. Two facets:
#                 • never-pulled dashboard → no Nextcloud file at all;
#                 • a file already IN a mapped folder → "ignored" mode (it stays put,
#                   keeps its id, is archived in Grafana, and the sync skips it).
#
# Authority is one-directional. The app NEVER writes Grafana:ignore onto dashboards in
# Grafana; it only READS it (if present) as a per-dashboard exclude at pull time. You add
# it yourself when you want the exception. The Nextcloud-side `Grafana:sync` / `Grafana:link`
# system tags the app stamps on managed files are AUTHORITATIVE + automatic and just
# mirror each file's mode (see the Tagging feature / file-type.feature) — they are
# not an override mechanism.
#
# So Grafana:ignore is 100% optional: the mapping does everything on its own; the
# Grafana-side ignore tag is just the escape hatch to leave one dashboard out.
#
# The never-pulled ignore and the in-folder `ignored` mode are live (saga §14.8 B).
# The un-tag RESTORE — removing Grafana:ignore unarchives the dashboard and returns the
# file to the mapping's mode — is live too (saga §14.18), driven by a
# TagUnassignedEvent listener.

@todo
Feature: The Grafana:ignore reserved tag excludes individual dashboards
  As an Grafana admin
  I want to exclude individual dashboards with the Grafana:ignore tag
  So that one mapping can still leave specific dashboards out

  Background:
    Given the app is connected to Grafana
    And a folder mapped as "sync" to the Grafana tag "team:flows"

  Scenario: With no reserved tag, a dashboard takes the mapping's mode
    Given Grafana has a dashboard tagged "team:flows" with no reserved tag
    When the "team:flows" mapping is pulled
    Then that dashboard's file is in "sync" mode (the mapping mode)

  Scenario: Grafana:ignore on a never-pulled dashboard creates no file
    Given Grafana has a dashboard tagged "team:flows" and "Grafana:ignore"
    When the "team:flows" mapping is pulled
    Then that dashboard is not pulled into Nextcloud
    And no file is created for it

  Scenario: Grafana:ignore on a file already in a mapped folder gives it "ignored" mode
    Given a managed "sync" dashboard file in the "team:flows" folder
    When I tag it "Grafana:ignore"
    Then the file's mode becomes "ignored"
    And the file stays in the mapped folder and keeps its "n8n_id"
    And the dashboard is archived in Grafana
    And subsequent pulls/pushes for "team:flows" skip it

  Scenario: Removing Grafana:ignore returns the file to the mapping's mode
    Given a managed "sync" dashboard file in the "team:flows" folder
    And I tag it "Grafana:ignore"
    When I remove the "Grafana:ignore" tag
    Then the file's mode becomes "sync"

  Scenario: A mapping tag needs no "nextcloud:" prefix
    Given a folder mapped as "sync" to the Grafana tag "myfoobarflows"
    And Grafana has a dashboard tagged "myfoobarflows"
    When the "myfoobarflows" mapping is pulled
    Then that dashboard's file is created in "sync" mode

  Scenario: The app never writes reserved tags onto Grafana dashboards
    Given Grafana has a dashboard tagged "team:flows" with no reserved tag
    When the "team:flows" mapping is pulled
    Then the dashboard in Grafana still carries only its original tags
    And the app has not added any "Grafana:sync", "Grafana:link", or "Grafana:ignore" tag to it
