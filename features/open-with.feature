# Notes, decisions and history for this feature: AGENTS.md#open-with

Feature: Opening a dashboard file (Open in Grafana / Open with text editor)
  As a Nextcloud user
  I want the right openers for a dashboard file, defaulting to the right one for its mode
  So that I'm never sent to a dashboard that isn't there, and can always edit the JSON.

  Background:
    Given the app is connected to Grafana

  # ── Open in Grafana ───────────────────────────────────────────────────────────────

  @user @ui @blocked
  Scenario: Open in Grafana opens the live dashboard (sync)
    Given a managed dashboard file in "sync" mode with a live dashboard in Grafana
    When I choose "Open in Grafana" from its context menu
    Then Grafana opens at that dashboard, not a download and not the text editor

  @user @ui @blocked
  Scenario: Open in Grafana is hidden when there is no live dashboard (unmapped)
    Given a managed dashboard file in "unmapped" mode
    Then "Open in Grafana" is hidden from its context menu

  @user @ui @blocked
  Scenario: Open in Grafana is hidden when there is no live dashboard (ignored)
    Given a managed dashboard file in "ignored" mode
    Then "Open in Grafana" is hidden from its context menu

  # ── Open with text editor ──────────────────────────────────────────────────────

  @user @ui @blocked
  Scenario Outline: Open with text editor is available on every dashboard file
    Given a managed dashboard file in "<mode>" mode
    When I choose "Open with text editor" from its context menu
    Then the file's raw JSON opens in the text editor

    Examples:
      | mode     |
      | sync     |
      | unmapped |
      | ignored  |

  # link integration is uncertain (it has no create-on-land path); its opener
  # logic is covered concretely by tests/js/files-helpers.test.js instead.
  @user @ui @blocked
  Scenario Outline: Open with text editor — link (covered by JS unit tests)
    Given a managed dashboard file in "<mode>" mode
    When I choose "Open with text editor" from its context menu
    Then the file's raw JSON opens in the text editor

    Examples:
      | mode |
      | link |

  # ── Default click action follows the mode ───────────────────────────────────────

  @user @ui @blocked
  Scenario Outline: The default click opens the right thing for the mode
    Given a managed dashboard file in "<mode>" mode
    When I click the file in the Files app
    Then it opens with "<opener>" by default

    Examples:
      | mode     | opener      |
      | sync     | Grafana         |
      | unmapped | text editor |
      | ignored  | text editor |

  # link: covered by the JS unit tests (see note above).
  @user @ui @blocked
  Scenario Outline: The default click — link (covered by JS unit tests)
    Given a managed dashboard file in "<mode>" mode
    When I click the file in the Files app
    Then it opens with "<opener>" by default

    Examples:
      | mode | opener |
      | link | Grafana    |
