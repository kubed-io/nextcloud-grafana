# "Admin makes a mapping" — the folder-mapping list in admin settings, driven over
# the CLI (the same operations the Settings panel performs).
#
# THE KEY DIFFERENCE FROM THE n8n TEMPLATE: Grafana has real folders. Where the
# n8n app had to bind an n8n *tag* to a Nextcloud folder (n8n has no folders), a
# Grafana mapping binds a Grafana **folder** (by uid) to a Nextcloud folder — a
# plain folder-to-folder mirror, no tagging scheme to maintain. The dashboards
# inside that Grafana folder become the `.grafana.json` files in the NC folder,
# and nested Grafana folders mirror to nested NC folders (the "General"/root area
# maps to the mapping's root). Modes are sync / link (see the saga).
#
# Note on the "grafana folder" column: the mapping stores a folder **uid**, which
# in real Grafana is opaque. This config-only feature does not check the uid
# against a live Grafana (a mapping is pure config until the sync chapter), so the
# steps use the folder name as its own uid — the mapping CRUD is what's under test.
Feature: Admin configures folder mappings
  As a Nextcloud admin
  I want to map Grafana folders to Nextcloud folders with a mode
  So that I can automate the connection and mappings (e.g. in k8s)

  Background:
    Given the app is enabled

  Scenario: Map Grafana folders to Nextcloud folders across both modes
    When the admin adds these mappings:
      | grafana folder | folder  | mode |
      | observe        | observe | sync |
      | secrets        | secrets | link |
    Then there are 2 configured mappings
    And the mapping for grafana folder "observe" is in "sync" mode
    And the mapping for grafana folder "secrets" is in "link" mode

  # The Nextcloud folder name is OPTIONAL — Grafana has real folders, so the common case
  # is "same name on both sides". When the NC folder is omitted, it defaults to the Grafana
  # folder's name, so mapping a whole instance is one column of Grafana folders.
  Scenario: The Nextcloud folder defaults to the Grafana folder name when omitted
    When the admin adds a mapping for grafana folder "observability" with no Nextcloud folder
    Then the mapping for grafana folder "observability" targets the Nextcloud folder "observability"

  # The folder names are IMMUTABLE once a mapping exists — changing which Grafana folder a
  # mapping points at, or which Nextcloud folder it targets, would require a live migration
  # of already-synced files (rename/move both trees, re-stamp metadata) that is fiddly and
  # error-prone, especially if BOTH change at once. So we forbid it: to "re-point" a mapping,
  # delete it and add a new one. Mode / format / groups / subfolder-sync stay editable.
  Scenario: A mapping's folder names cannot be changed after it is created
    Given a mapping from grafana folder "observe" to Nextcloud folder "observe"
    When the admin tries to change that mapping's Nextcloud folder to "elsewhere"
    Then the change is rejected as immutable
    When the admin tries to change that mapping's Grafana folder to "secrets"
    Then the change is rejected as immutable
    And the admin can still change that mapping's mode, format, groups, and subfolder-sync

  # New-model invariant: a mapping's mode is exactly sync or link.
  Scenario: A mapping mode must be sync or link
    When the admin adds a mapping with an unknown mode for grafana folder "build"
    Then the mapping is rejected
    And there are 0 configured mappings

  # Difference #2 — the serialization cut is a per-mapping field. It defaults to the
  # classic JSON dashboard model and can opt into the newer k8s-style YAML schema.
  Scenario: A mapping records its serialization format, defaulting to json
    When the admin adds these mappings:
      | grafana folder | folder    | mode |
      | network        | network   | sync |
    Then the mapping for grafana folder "network" is in "json" format
    When the admin adds a "yaml" mapping for grafana folder "observe" in folder "observe"
    Then the mapping for grafana folder "observe" is in "yaml" format

  # A folder can map to exactly one location — a duplicate uid is rejected.
  Scenario: A Grafana folder may only be mapped once
    When the admin adds these mappings:
      | grafana folder | folder  | mode |
      | observe        | observe | sync |
    And the admin adds a "json" mapping for grafana folder "observe" in folder "elsewhere"
    Then the mapping is rejected
    And there are 1 configured mappings

  # Subfolder sync (saga Ch2, revised): a mapping carries an optional "Sync subfolders"
  # flag (default OFF). ON = a Nextcloud subfolder mirrors to a Grafana subfolder the
  # moment a dashboard lands in it (lazy, presence-driven — no hidden child mappings, no
  # manual trigger tag). The flag persists like any other mapping field; the folder-
  # mirroring engine that acts on it lands in a later Course, so this scenario is @todo.
  @todo
  Scenario: A mapping records a "Sync subfolders" flag, defaulting to off
    When the admin adds these mappings:
      | grafana folder | folder  | mode |
      | observe        | observe | sync |
    Then the mapping for grafana folder "observe" has subfolder sync off
    When the admin enables subfolder sync for grafana folder "observe"
    Then the mapping for grafana folder "observe" has subfolder sync on

  # The Grafana root ("General") holds dashboards with no folder. It has no real uid, so the
  # folder picker offers a reserved "/" entry for it. Mapping "/" pulls the no-folder
  # dashboards; "/" → Nextcloud "/" with subfolder sync on mirrors the WHOLE instance
  # (see reconcile.feature). @todo — needs the reserved-root handling.
  @todo
  Scenario: The Grafana root can be mapped via the reserved "/" folder
    When the admin adds these mappings:
      | grafana folder | folder     | mode |
      | /              | dashboards | sync |
    Then the mapping for grafana folder "/" is in "sync" mode

  # ── the optional Grafana recycle-bin folder (opt-in id-preserving delete/move-out) ──
  # OFF by default: a move-out / delete is a true Grafana delete (see move.feature +
  # delete.feature). Turned ON, the admin names an existing Grafana folder to act as the
  # trash: instead of deleting, the app MOVES the dashboard there (keeping its uid), so a
  # restore / move-back-in returns the SAME dashboard — Grafana's answer to n8n's archive.
  # The bin folder has special meaning, so it may not itself be used in a folder mapping.
  # @todo — the delete/move engine that reads these settings lands in Course 4 · Slice 2.
  @todo
  Scenario: The recycle-bin folder is off by default and can be enabled with a folder name
    Given the app is enabled
    Then the Grafana recycle-bin folder is off
    When the admin enables the Grafana recycle-bin folder and sets it to "nextcloud-trash"
    Then the Grafana recycle-bin folder is on and set to "nextcloud-trash"

  @todo
  Scenario: The recycle-bin folder cannot also be a mapped folder
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    When the admin adds a "sync" mapping for grafana folder "nextcloud-trash" in folder "trash"
    Then the mapping is rejected
    And there are 0 configured mappings
