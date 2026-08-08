# Notes, decisions and history for this feature: ../AGENTS.md#connectionconnection

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

  # notes: ../AGENTS.md#the-connection-test-says-which-of-the-two-token-problems-it-is
  @admin @occ @ui
  Scenario Outline: The connection test says which of the two token problems it is
    Given the admin has set the Grafana base URL
    And <the token state>
    When the admin tests the connection
    Then the connection test reports a failure
    And the connection test says <the message>

    Examples: the two failures, which have different fixes
      | the token state                       | the message             |
      | no service-account token is set       | the token is not set    |
      | an invalid service-account token is set | the token was rejected |
