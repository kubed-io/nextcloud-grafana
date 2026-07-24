# Reserved Grafana tag — the optional, per-dashboard EXCLUDE switch.
#
# A mapping binds ONE Grafana FOLDER to a Nextcloud folder + a mode (`sync` / `link`).
# That mode is AUTHORITATIVE for every dashboard in the mapped folder — there is no
# per-dashboard sync/link override. The only reserved TAG the app honours is the
# exclude:
#
#   grafana:ignore  — exclude this one. Two facets:
#                 • never-pulled dashboard → no Nextcloud file at all;
#                 • a file already IN a mapped folder → "ignored" mode (it stays put,
#                   keeps its UID, and the sync skips it).
#
# NO ARCHIVE (saga Ch2 Round 2): the master (n8n) archives an ignored workflow. Our
# ingredient has no reachable archive, so `ignored` simply means "skip it in sync" —
# the dashboard is left fully LIVE in Grafana (fork F, leaning). We do NOT try to
# hide/archive it.
#
# Authority is one-directional. The app NEVER writes grafana:ignore onto dashboards in
# Grafana; it only READS it (if present) as a per-dashboard exclude at pull time. You
# add it yourself when you want the exception. The Nextcloud-side `grafana:sync` /
# `grafana:link` system tags the app stamps on managed files are AUTHORITATIVE +
# automatic and just mirror each file's mode (see file-type.feature) — they are not an
# override mechanism.
#
# So grafana:ignore is 100% optional: the mapping does everything on its own; the
# Grafana-side ignore tag is just the escape hatch to leave one dashboard out.
#
# The never-pulled ignore and the in-folder `ignored` mode are the target. The un-tag
# RESTORE — removing grafana:ignore returns the file to the mapping's mode — is driven
# by a TagUnassignedEvent listener.
#
# DESIGN, NOT WIRED: this feature is @todo — CI skips it — until the pull engine +
# reserved-tag resolver are cooked.

@todo
Feature: The grafana:ignore reserved tag excludes individual dashboards
  As a Grafana admin
  I want to exclude individual dashboards with the grafana:ignore tag
  So that one mapping can still leave specific dashboards out

  Background:
    Given the app is connected to Grafana
    And a folder mapped as "sync" to the Grafana folder "flows"

  Scenario: With no reserved tag, a dashboard takes the mapping's mode
    Given Grafana has a dashboard in the "flows" folder with no reserved tag
    When the "flows" mapping is pulled
    Then that dashboard's file is in "sync" mode (the mapping mode)

  Scenario: grafana:ignore on a never-pulled dashboard creates no file
    Given Grafana has a dashboard in the "flows" folder tagged "grafana:ignore"
    When the "flows" mapping is pulled
    Then that dashboard is not pulled into Nextcloud
    And no file is created for it

  Scenario: grafana:ignore on a file already in a mapped folder gives it "ignored" mode
    Given a managed "sync" dashboard file in the "flows" folder
    When the admin adds the Nextcloud tag "grafana:ignore" to the file
    Then the file's mode becomes "ignored"
    And the file stays in the mapped folder and keeps its "grafana_uid"
    And the dashboard is left fully live in Grafana (no archive)
    And subsequent pulls/pushes for "flows" skip it

  Scenario: Removing grafana:ignore returns the file to the mapping's mode
    Given a managed "sync" dashboard file in the "flows" folder
    And the file has the Nextcloud tag "grafana:ignore"
    When I remove the "grafana:ignore" tag
    Then the file's mode becomes "sync"

  Scenario: The app never writes reserved tags onto Grafana dashboards
    Given Grafana has a dashboard in the "flows" folder with no reserved tag
    When the "flows" mapping is pulled
    Then the dashboard in Grafana still carries only its original tags
    And the app has not added any "grafana:sync", "grafana:link", or "grafana:ignore" tag to it
