# Notes, decisions and history for this feature: AGENTS.md#admin-mapping

Feature: Admin configures folder mappings
  As a Nextcloud admin
  I want to map Grafana folders to Nextcloud folders with a mode
  So that I can automate the connection and mappings (e.g. in k8s)

  Background:
    Given the app is enabled
    # WHAT AN UNSET FIELD BECOMES IS A FACT ABOUT THE FORM, not about one
    # scenario, so it is declared once here. Stated per-scenario it was silently
    # optional: a scenario that asserted "unset fields at their defaults" without
    # declaring any compared against whatever the step happened to assume, and
    # disagreed with the app.
    #
    # The Grafana folder is the only required field. The Nextcloud folder's default
    # is prose rather than a value because that is genuinely what it is: left blank
    # it is materialised from the Grafana folder's TITLE at create and stored.
    And an unset field on the mapping form defaults to:
      | nc folder  | the Grafana folder's name |
      | mode       | link                      |
      | format     | json                      |
      | groups     |                           |
      | storage    | admin folder              |
      | subfolders | off                       |

    # A mapping is one fact, so it is one sentence plus a table of what is in it —
    # the same table whether it is pre-state or the action. A blank cell means the
    # admin left that field alone, so the app's own default applies.
    # notes: AGENTS.md#the-preconditions

  @admin @occ @ui
  Scenario Outline: Creating a mapping saves the form
    Given no Grafana folders are mapped
    When the admin maps the Grafana folder "<uid>" with:
      | nc folder  | <nc folder>  |
      | mode       | <mode>       |
      | format     | <format>     |
      | groups     | <groups>     |
      | storage    | <storage>    |
      | subfolders | <subfolders> |
    Then the mapping matches the form, unset fields at their defaults

    # The mode x format matrix, one row per combination.

    Examples: every mode, and every serialization format
      | uid     | nc folder | mode | format | groups | storage      | subfolders |
      | observe | observe   | sync | json   |        | team folder  |            |
      | secrets | secrets   | link | json   |        | team folder  |            |
      | network | network   | sync | yaml   |        | admin folder |            |
      | build   | build     | link | yaml   |        | admin folder |            |

    Examples: and the fields that have a default
      | uid       | nc folder | mode | format | groups | storage | subfolders |
      | defaulted |           |      |        |        |         |            |
      | grouped   | grouped   | sync |        | admin  |         |            |
      | nested    | nested    | sync |        |        |         | on         |

    # notes: AGENTS.md#creating-a-mapping-saves-the-form

  @admin @occ @ui
  Scenario Outline: A mapping the app cannot honour is refused, and says why
    Given no Grafana folders are mapped
    When the admin maps the Grafana folder "<uid>" with:
      | nc folder | <nc folder> |
      | mode      | <mode>      |
      | format    | <format>    |
    Then the mapping is rejected
    And the refusal explains "<reason>"
    And there are exactly 0 configured mappings

    Examples: every field that carries a rule of its own
      | uid     | nc folder | mode  | format | reason             |
      |         | observe   | sync  | json   | grafana_folder_uid |
      | observe | observe   | bogus | json   | mode must be       |
      | observe | observe   | sync  | toml   | format must be     |

    # notes: AGENTS.md#a-mapping-the-app-cannot-honour-is-refused-and-says-why

  @admin @occ @ui
  Scenario: A Grafana folder may only be mapped once
    Given a mapping with the following values:
      | grafana folder | observe |
      | nc folder      | observe |
    When the admin maps the Grafana folder "observe" with:
      | nc folder | elsewhere |
    Then the mapping is rejected
    And the refusal explains "already uses the Grafana folder"
    And there is exactly 1 configured mapping
    # A Grafana folder is what a mapping IS, so mapping it twice would make two
    # mappings mean the same thing and every dashboard in it would belong to both.
    # notes: AGENTS.md#a-grafana-folder-may-only-be-mapped-once

  @admin @occ @ui @unbuilt
  Scenario: Two mappings may not target the same Nextcloud folder
    Given a mapping with the following values:
      | grafana folder | observe |
      | nc folder      | shared  |
    When the admin maps the Grafana folder "secrets" with:
      | nc folder | shared |
    Then the mapping is rejected
    And the refusal explains "already"
    And there is exactly 1 configured mapping
    # UNBUILT, AND THE GAP IS REAL: assertFolderUnique checks the GRAFANA uid and
    # says nothing about the Nextcloud folder, so today this is accepted and the
    # two mappings then prune each other's dashboards forever.
    # notes: AGENTS.md#two-mappings-may-not-target-the-same-nextcloud-folder

  @admin @occ @ui
  Scenario: The Grafana root can be mapped via the reserved "/" folder
    Given no Grafana folders are mapped
    When the admin maps the Grafana folder "/" with:
      | nc folder | dashboards |
      | mode      | sync       |
    Then the mapping matches the form, unset fields at their defaults
    # The Grafana root ("General") holds dashboards with no folder and has no real
    # uid, so the picker offers a reserved "/" entry for it.
    # notes: AGENTS.md#the-grafana-root-can-be-mapped-via-the-reserved-folder

  @decision
  Scenario: There is no way to change a mapping except its groups
    # @decision, NOT @unbuilt: there is no operation here to test, and that is the
    # whole design. Immutability is not enforced by guards that reject a change —
    # it is enforced by the API SHAPE. `MappingService::updateGroups()` takes an id
    # and groups, the PUT endpoint takes `nc_groups` and nothing else, and there is
    # no update command. A caller cannot express a change to the Grafana folder, the
    # Nextcloud folder, the storage backend, subfolder-sync, the mode or the format,
    # so there is no rejection to observe.
    #
    # This used to be four `When`s in a row against a full-mapping update() that
    # checked four fields — which left mode and format editable by omission, and
    # meant the card PUT every field on every save.
    #
    # To change any of them: remove the mapping and add it again. That makes the
    # migration cost visible rather than hiding it behind a dropdown.
    # notes: AGENTS.md#there-is-no-way-to-change-a-mapping-except-its-groups

  @admin @occ @ui
  Scenario Outline: The groups a mapped folder is shared with can be changed
    Given the Nextcloud groups "design,sales" exist
    And a mapping with the following values:
      | grafana folder | observe   |
      | nc folder      | <folder>  |
      | groups         | design,admin |
      | storage        | <storage> |
    When the admin changes that mapping's groups to "<groups>"
    Then the mapping's groups are "<groups>"

    # THE FOLDER NAME DIFFERS PER STORAGE KIND ON PURPOSE. Removing a mapping
    # deletes nothing, so a folder outlives the mapping that made it — and a later
    # row reusing the name would inherit a folder of the wrong kind.

    Examples: on a Team Folder
      | folder                  | storage      | groups             |
      | Groups On A Team Folder | team folder  | design,admin,sales |
      | Groups On A Team Folder | team folder  | design             |
      | Groups On A Team Folder | team folder  | sales              |
      | Groups On A Team Folder | team folder  |                    |

    Examples: and on an admin-owned folder
      | folder                   | storage      | groups             |
      | Groups On A Plain Folder | admin folder | design,admin,sales |
      | Groups On A Plain Folder | admin folder | design             |
      | Groups On A Plain Folder | admin folder | sales              |
      | Groups On A Plain Folder | admin folder |                    |

    # NARROWING AND CLEARING ARE THE POINT. The old code only ever added: it wrote
    # the listed groups and left the rest alone, so a group could be granted and
    # never revoked, and "set the groups to nothing" silently did nothing at all.
    # notes: AGENTS.md#the-groups-a-mapped-folder-is-shared-with-can-be-changed

  @admin @occ @ui @team-folder
  Scenario: Groups are read from the folder, not from the mapping
    Given the Nextcloud groups "design,sales" exist
    And a mapping with the following values:
      | grafana folder | observe          |
      | nc folder      | Shared Elsewhere |
      | groups         | design           |
      | storage        | team folder      |
    When the Team Folder "Shared Elsewhere" is shared with the group "sales" outside this app
    Then the mapping's groups are "design,sales"
    # THE REASON THE WHOLE CHANGE EXISTS. Three apps in this family can map to one
    # folder; while each stored its own list, every sync stamped that list over the
    # others' and they fought forever, none of them wrong. Reading the groups off
    # the folder makes the folder the single answer, so all three — and the Files
    # UI, and occ — can edit the same sharing without contending.
    #
    # Driven through groupfolders' OWN occ command, so the share is made by
    # something that is not this app. Core ships no command that creates a plain
    # group share (checked against a live Nextcloud), which is why this is written
    # on a Team Folder.
    # notes: AGENTS.md#groups-are-read-from-the-folder-not-from-the-mapping

  # ── the optional Grafana recycle-bin folder ────────────────────────────────
  # Off by default: a move-out or delete is a true Grafana delete. Turned on, the
  # admin names an existing Grafana folder to act as the bin, so a delete MOVES
  # the dashboard there with its uid intact and a restore returns the same
  # dashboard. It is a setting of the app, not a property of a mapping — which is
  # why the bin folder may not itself be mapped.
  # notes: AGENTS.md#the-recycle-bin-folder

  @recycle-bin @todo
  Scenario: The recycle-bin folder is off by default and can be enabled with a folder name
    Given the app is enabled
    Then the Grafana recycle-bin folder is off
    When the admin enables the Grafana recycle-bin folder and sets it to "nextcloud-trash"
    Then the Grafana recycle-bin folder is on and set to "nextcloud-trash"

  @recycle-bin @unbuilt
  Scenario: The recycle-bin folder cannot also be a mapped folder
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    When the admin maps the Grafana folder "nextcloud-trash" with:
      | nc folder | trash |
      | mode      | sync  |
    Then the mapping is rejected
    And there are exactly 0 configured mappings
