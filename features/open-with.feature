# "Open with" — the openers offered for a managed dashboard file, and which one is
# the default click. RELATED to the file type (file-type.feature: it's *because*
# `.grafana.json` is a first-class type that we get custom openers) but a distinct
# concern, because the opener set + default depend on the file's MODE, not its type.
#
# Two openers:
#   - "Open in Grafana"          — jumps to the live dashboard in Grafana. Only meaningful for
#                              sync/link; hidden for unmapped/ignored (nothing to open).
#   - "Open with text editor" — edits the raw JSON. ALWAYS available on any dashboard
#                              file; it's the default for unmapped/ignored.
# Default click: sync/link → Open in Grafana; unmapped/ignored → text editor.
# (Whether editing+saving pushes to Grafana follows the file's mode — see
# create-dashboard.feature / rename-dashboard.feature / the bidirectional sync, not here.)
#
# STATUS: the openers ARE cooked (Course 5) — src/files.js registers "Open in Grafana" +
# "Open with text editor" (+ the "Grafana dashboard" New-menu item), loaded by
# LoadFilesScriptListener. Behat can't click the Files-app JS, so the opener DECISION logic
# is unit-tested in tests/js/files-helpers.test.js (30 cases); the integration steps here
# assert the server-observable state the front-end keys off (the grafana_mode DAV value + the
# live dashboard + raw-JSON readability). The whole feature stays @todo — CI skips it — until
# those occ+WebDAV step definitions are written; the JS unit suite carries the decision proof.
# ── STATUS: @blocked THROUGHOUT, AND THE CAPABILITY IS NAMED ─────────────────────
#
# Every scenario here describes what the Files-app context menu SHOWS — which entry
# is offered, which is hidden, what the default click does. That is browser
# behaviour, and this harness has **no browser**: it drives Nextcloud over WebDAV
# and occ, neither of which renders a menu. No step could assert any of it.
#
# @blocked, not @todo: the code exists and ships (the openers are registered in the
# Files UI), and the JS unit suite covers the entry-visibility logic
# (tests/js/files-helpers.test.js). What is missing is a capability in the harness,
# not a test someone forgot — which is exactly the distinction that keeps these out
# of the @todo work queue.

Feature: Opening a dashboard file (Open in Grafana / Open with text editor)
  As a Nextcloud user
  I want the right openers for a dashboard file, defaulting to the right one for its mode
  So that I'm never sent to a dashboard that isn't there, and can always edit the JSON

  Background:
    Given the app is connected to Grafana

  # ── Open in Grafana ───────────────────────────────────────────────────────────────

  @user @ui @blocked
  Scenario: Open in Grafana opens the live dashboard (sync)
    Given a managed dashboard file in "sync" mode with a live dashboard in Grafana
    When I choose "Open in Grafana" from its context menu
    Then Grafana opens at that dashboard (not a download, not the text editor)

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
