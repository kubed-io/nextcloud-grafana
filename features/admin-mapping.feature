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

  @admin @occ @ui
  Scenario: Map Grafana folders to Nextcloud folders across both modes
    When the admin adds these mappings:
      | grafana folder | folder  | mode |
      | observe        | observe | sync |
      | secrets        | secrets | link |
    Then there are 2 configured mappings
    And the mapping for grafana folder "observe" is in "sync" mode
    And the mapping for grafana folder "secrets" is in "link" mode

  # The Nextcloud folder name is OPTIONAL — Grafana has real folders, so the common case
  # is "same name on both sides". When the NC folder is omitted, it is **resolved to the
  # Grafana folder's name AT CREATE and stored** (not defaulted lazily). Because mappings
  # are immutable, resolving once on create is enough, and it means the saved mapping (and
  # the admin list) shows BOTH folder fields with a value — you can see at a glance they
  # match precisely because you left the Nextcloud name blank.
  # @todo — new step phrases (no step defs yet); the behaviour is covered by MappingTest.
  @admin @occ @ui @todo
  Scenario: The Nextcloud folder is stored as the Grafana folder name when omitted at create
    When the admin adds a mapping for grafana folder "observability" with no Nextcloud folder
    Then the stored mapping for grafana folder "observability" has Nextcloud folder "observability"
    And both folder fields are set in the saved mapping (nothing left blank)

  # Four fields are IMMUTABLE once a mapping exists — each would otherwise force a live
  # migration that's easier to avoid by re-creating the mapping:
  #   - the Grafana folder + the Nextcloud folder — re-pointing either would rename/move both
  #     trees of already-synced files and re-stamp metadata (doubly fiddly if BOTH change);
  #   - the **Team Folder** flag — switching the storage backend (ownerless Team Folder ⇄
  #     admin-owned shared folder) would migrate the provisioned folder + its shares (the n8n
  #     master reinforces this same rule);
  #   - **subfolder-sync** — flipping it restructures the far-side folder tree (ON→OFF flattens
  #     mirrored Grafana subfolders + re-parents dashboards; OFF→ON lazily grows them). Immutable
  #     for now; the saga records what a safe on-the-fly flip could look like later.
  # To change any of them, delete the mapping and add a new one. Mode / format / groups stay
  # editable.
  # @todo — immutability can't be driven over occ (no update command); MappingServiceTest
  # provides the real coverage. Kept here as the executable spec for when a REST/UI step lands.
  @admin @occ @ui @todo
  Scenario: A mapping's folders, Team Folder, and subfolder-sync cannot be changed after it is created
    Given a mapping from grafana folder "observe" to Nextcloud folder "observe"
    When the admin tries to change that mapping's Nextcloud folder to "elsewhere"
    Then the change is rejected as immutable
    When the admin tries to change that mapping's Grafana folder to "secrets"
    Then the change is rejected as immutable
    When the admin tries to change that mapping's Team Folder setting
    Then the change is rejected as immutable
    When the admin tries to change that mapping's subfolder-sync setting
    Then the change is rejected as immutable
    And the admin can still change that mapping's mode, format, and groups

  # New-model invariant: a mapping's mode is exactly sync or link.
  @admin @occ @ui
  Scenario: A mapping mode must be sync or link
    When the admin adds a mapping with an unknown mode for grafana folder "build"
    Then the mapping is rejected
    And there are 0 configured mappings

  # Difference #2 — the serialization cut is a per-mapping field. It defaults to the
  # classic JSON dashboard model and can opt into the newer k8s-style YAML schema.
  @admin @occ @ui
  Scenario: A mapping records its serialization format, defaulting to json
    When the admin adds these mappings:
      | grafana folder | folder    | mode |
      | network        | network   | sync |
    Then the mapping for grafana folder "network" is in "json" format
    When the admin adds a "yaml" mapping for grafana folder "observe" in folder "observe"
    Then the mapping for grafana folder "observe" is in "yaml" format

  # A folder can map to exactly one location — a duplicate uid is rejected.
  @admin @occ @ui
  Scenario: A Grafana folder may only be mapped once
    When the admin adds these mappings:
      | grafana folder | folder  | mode |
      | observe        | observe | sync |
    And the admin adds a "json" mapping for grafana folder "observe" in folder "elsewhere"
    Then the mapping is rejected
    And there are 1 configured mappings

  # Subfolder sync (saga Ch2, revised): a mapping carries an optional "Sync subfolders"
  # flag (default OFF), chosen AT CREATE (it is immutable afterwards — see above). ON = a
  # Nextcloud subfolder mirrors to a Grafana subfolder the moment a dashboard lands in it
  # (lazy, presence-driven — no hidden child mappings, no manual trigger tag). The flag
  # persists like any other mapping field; the folder-mirroring engine that acts on it lands
  # in a later Course, so the "on" behaviour is @todo.
  @admin @occ @ui @unbuilt
  Scenario: A mapping records its "Sync subfolders" flag at create, defaulting to off
    When the admin adds these mappings:
      | grafana folder | folder  | mode |
      | observe        | observe | sync |
    Then the mapping for grafana folder "observe" has subfolder sync off
    When the admin adds a "sync" mapping for grafana folder "network" in folder "network" with subfolder sync on
    Then the mapping for grafana folder "network" has subfolder sync on

  # The Grafana root ("General") holds dashboards with no folder. It has no real uid, so the
  # folder picker offers a reserved "/" entry for it. Mapping "/" pulls the no-folder
  # dashboards; "/" → Nextcloud "/" with subfolder sync on mirrors the WHOLE instance
  # (see reconcile.feature). @todo — needs the reserved-root handling.
  @admin @occ @ui @todo
  Scenario: The Grafana root can be mapped via the reserved "/" folder
    When the admin adds these mappings:
      | grafana folder | folder     | mode |
      | /              | dashboards | sync |
    Then the mapping for grafana folder "/" is in "sync" mode

  # ── the optional Grafana recycle-bin folder (opt-in id-preserving delete/move-out) ──
  # OFF by default: a move-out / delete is a true Grafana delete (see move-dashboard.feature +
  # delete-dashboard.feature). Turned ON, the admin names an existing Grafana folder to act as the
  # trash: instead of deleting, the app MOVES the dashboard there (keeping its uid), so a
  # restore / move-back-in returns the SAME dashboard — Grafana's answer to n8n's archive.
  # The bin folder has special meaning, so it may not itself be used in a folder mapping.
  # @todo — the delete/move engine that reads these settings lands in Course 4 · Slice 2.
  @admin @occ @ui @recycle-bin @todo
  Scenario: The recycle-bin folder is off by default and can be enabled with a folder name
    Given the app is enabled
    Then the Grafana recycle-bin folder is off
    When the admin enables the Grafana recycle-bin folder and sets it to "nextcloud-trash"
    Then the Grafana recycle-bin folder is on and set to "nextcloud-trash"

  @admin @occ @ui @recycle-bin @unbuilt
  Scenario: The recycle-bin folder cannot also be a mapped folder
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    When the admin adds a "sync" mapping for grafana folder "nextcloud-trash" in folder "trash"
    Then the mapping is rejected
    And there are 0 configured mappings
