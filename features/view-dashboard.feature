# Notes, decisions and history for this feature: AGENTS.md#view-dashboard

Feature: Looking at a dashboard file
  As someone with dashboards mirrored into Nextcloud
  I want to see them for what they are, and see what the app knows about them
  So that a mapped folder reads as dashboards rather than as anonymous JSON files

  Background:
    Given the app is connected to Grafana

  # THIS FILE REPLACED "Grafana dashboard is a first-class file type", which
  # described a CONSTRUCT — a mimetype, a property set, an index — rather than
  # anything a person does. Each of those is the end state of something else:
  #
  #   the mimetype being registered   is what ENABLING THE APP leaves behind
  #                                   -> lifecycle.feature
  #   the metadata on a file          is what CREATING or SYNCING one leaves behind
  #                                   -> asserted by those behaviours, and shown here
  #
  # What is left is the only part anyone actually performs: looking at the thing.
  # notes: AGENTS.md#view-dashboard

  @user @ui @todo
  Scenario: A mapped folder shows its dashboards as dashboards
    Given a folder mapped as "sync" to the Grafana folder "flows"
    And Grafana has dashboards in the "flows" folder
    And the "flows" mapping has been synced
    When the user views the contents of the mapped folder
    Then the mapped folder shows the dashboards with the Grafana icon
    # ONE SCENARIO, DELIBERATELY. Behat cannot read rendered pixels, so the icon is
    # proven the only way it can be: the file carries the app's own mimetype rather
    # than application/json, and Nextcloud maps that mimetype to the app's glyph.
    # Elaborating past that would be testing Nextcloud's icon renderer.
    # notes: AGENTS.md#a-mapped-folder-shows-its-dashboards-as-dashboards

  @user @gesture @todo
  Scenario Outline: Viewing the DAV properties on a file shows Grafana specific details
    Given a mapping with the following values:
      | grafana folder | <grafana folder> |
      | nc folder      | <nc folder>      |
      | mode           | <mode>           |
    And a dashboard "<dashboard>" mirrored into that folder
    When a WebDAV client requests the file's properties
    Then the response carries the properties the app manages:
      | property                       | value               |
      | nc:metadata-grafana_uid        | the dashboard's uid |
      | nc:metadata-grafana_mapping    | the mapping's id    |
      | nc:metadata-grafana_mode       | <stored mode>       |
      | nc:metadata-grafana_version    | set                 |
      | nc:metadata-grafana_syncedHash | set                 |

    Examples: both modes a mapping can hold
      | mode | stored mode | grafana folder | nc folder | dashboard |
      | sync | sync        | bananacat      | observe   | fuzzler   |
      | link | reference   | applepie       | pointers  | wobbler   |

    # `link` stores as "reference" — the literal string "link" is `is_callable()`,
    # which crashes core's PROPFIND. That is the only place in this app where a
    # wire value differs from the name of the thing it carries, so the row spells
    # out both: what the admin chose, and what a DAV client reads back.
    #
    # THE TABLE IS THE FIVE KEYS A MIRROR ARRIVES WITH — what `stampSynced` writes
    # when a file lands. `grafana_folderUid` and `grafana_apiVersion` are registered
    # but written by nothing yet (the subfolder and YAML courses bank them), so
    # naming them here would assert a value the app never sets.
    #
    # NO `storage` ROW, DELIBERATELY. Naming a field in a table is a claim that it
    # matters to the outcome, and what a mirror publishes over DAV is identical on
    # an admin-owned folder and a Team Folder. So the mapping takes the app's own
    # default, an admin-owned folder — the one backend that exists on every
    # install. `storage` is named only where provisioning IS the behaviour, in
    # admin-mapping.feature, and a scenario that wants a Team Folder asks for one
    # there.
    #
    # TWO ROWS, WHERE THERE USED TO BE FOUR. A mapping only ever produces `sync` or
    # `link`; `unmapped` and `ignored` are what a file BECOMES — by being moved out
    # of its folder, or hand-tagged `grafana:ignore` — so neither can be reached
    # from a mapping form, and neither belongs to a scenario shaped like one. Their
    # modes are asserted where those behaviours live: move-dashboard.feature and
    # reserved-tags.feature.
    #
    # `set` means present and non-empty. A Grafana version int and a body hash are
    # the sync engine's own bookkeeping; pinning a literal would assert its
    # internals rather than the fact under test, which is that the app publishes
    # them and a client can read them.
    # notes: AGENTS.md#viewing-the-dav-properties-on-a-file-shows-grafana-specific-details

  @user @gesture @todo
  Scenario: What the app manages, only the app changes
    Given a managed dashboard file
    When a client tries to change "nc:metadata-grafana_uid" via PROPPATCH
    Then the change is rejected — the sync engine owns these properties
    And the property still names the dashboard it named before
    # A REFUSAL SOMEONE CAN PROVOKE, so it earns a scenario: any DAV client can
    # attempt this. The identity of a mirror is the app's to write; a client that
    # could edit it could silently re-point a file at a different dashboard.
    # notes: AGENTS.md#what-the-app-manages-only-the-app-changes

  # grafana_mode is indexed, so "find every sync / unmapped / ignored file" is a
  # fast query. @blocked, and the missing capability is named: there is no proven
  # DAV REPORT search over `nc:metadata-*` to drive this against. Confirm that
  # exists and this becomes an ordinary @todo.
  # notes: AGENTS.md#finding-dashboards-by-their-mode
  @user @gesture @blocked
  Scenario: Finding dashboards by their mode
    Given a "sync" dashboard file and a "link" dashboard file in the same user's storage
    When a DAV REPORT searches for files where "nc:metadata-grafana_mode" is "sync"
    Then only the sync file is returned
