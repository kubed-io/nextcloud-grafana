# The "admin makes the Grafana connection" use case — the app's "I'm logged in"
# gate, a prerequisite to every other feature. The admin points the app at Grafana
# (base URL), provides a service-account token, and tests the connection to confirm
# the URL + token are valid and Grafana is reachable.
#
# The test deliberately hits an AUTHENTICATED Grafana endpoint (GET /api/folders),
# not the unauthenticated /api/health, so a green result proves the token itself is
# valid — not merely that the host is up.
#
# (Obtaining the token is out of the app's scope — that's the Grafana admin's job;
# in the tests it's minted as setup, see tests/integration/bin/mint-grafana-token.sh.)

Feature: Admin configures the Grafana connection
  As a Nextcloud admin
  I want to point the app at my Grafana and verify the connection
  So that every sync feature has a valid, tested connection to rely on

  Background:
    Given the app is installed and enabled

  @admin @occ @ui
  Scenario: Set up and verify the connection
    When the admin sets the Grafana base URL
    And the admin provides the Grafana service-account token
    And the admin tests the connection
    Then the connection is verified

  # A sensitive token field renders blank whether or not a token is stored, so the
  # Test connection result is the admin's diagnostic — and it must tell the two
  # failure modes apart: "you haven't added a token" vs "the token you added was
  # rejected". Same distinct messages on the button and the occ command.
  @admin @occ @ui
  Scenario: The connection test tells an unset token apart from a rejected one
    Given the admin has set the Grafana base URL
    And no service-account token is set
    When the admin tests the connection
    Then the connection test reports a failure
    And the connection test says the token is not set
    When the admin provides an invalid service-account token
    And the admin tests the connection
    Then the connection test reports a failure
    And the connection test says the token was rejected
