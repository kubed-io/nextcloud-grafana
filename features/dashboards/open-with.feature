# Notes, decisions and history for this feature: ../AGENTS.md#dashboardsopen-with

Feature: Opening a dashboard file
  As a Nextcloud user
  I want the right openers for a dashboard file, and the right one on a plain click
  So that I am never sent to a dashboard that is not there, and can always reach the JSON

  Background:
    Given the app is connected to Grafana

  # @blocked — no browser. Every scenario here is a context menu or a click, and
  # the harness is occ + WebDAV. The logic is unit-tested in
  # tests/js/files-helpers.test.js; what no unit test reaches is the registration.
  # notes: ../AGENTS.md#dashboardsopen-with

  @user @ui @blocked
  Scenario Outline: Open in Grafana is offered exactly when a live dashboard exists
    Given a managed dashboard file in "<mode>" mode
    When I open its context menu
    Then "Open in Grafana" is <offered or hidden>

    Examples: the two modes that name a live dashboard, and the two that do not
      | mode     | offered or hidden |
      | sync     | offered           |
      | link     | offered           |
      | unmapped | hidden            |
      | ignored  | hidden            |

  @user @ui @blocked
  Scenario Outline: The text editor is offered exactly when the file holds the JSON
    Given a managed dashboard file in "<mode>" mode
    When I open its context menu
    Then "Open with text editor" is <offered or hidden>

    Examples: a link holds a pointer, so there is nothing to edit
      | mode     | offered or hidden |
      | sync     | offered           |
      | unmapped | offered           |
      | ignored  | offered           |
      | link     | hidden            |

  @user @ui @blocked
  Scenario Outline: A plain click opens whichever of the two the mode calls for
    Given a managed dashboard file in "<mode>" mode
    When I click the file in the Files app
    Then it opens with "<opener>" by default

    Examples: a live dashboard wins; without one, the JSON is all there is
      | mode     | opener      |
      | sync     | Grafana     |
      | link     | Grafana     |
      | unmapped | text editor |
      | ignored  | text editor |
