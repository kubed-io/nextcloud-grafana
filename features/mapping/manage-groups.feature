# Notes, decisions and history for this feature: ../AGENTS.md#mappingmanage-groups

Feature: Changing who a mapped folder is shared with
  As a Nextcloud admin
  I want to change a mapped folder's groups after the fact
  So that access can follow the team without rebuilding the mapping

  Background:
    Given the app is enabled

    # The one field of a mapping that is editable; everything else is fixed at
    # creation. notes: ../AGENTS.md#mappingmanage-groups

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
    # notes: ../AGENTS.md#groups-are-read-from-the-folder-not-from-the-mapping
