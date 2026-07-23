<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

return [
	'routes' => [
		// Admin-only: test the saved Grafana URL + token against an
		// authenticated endpoint (GET /api/folders).
		['name' => 'config#testConnection', 'url' => '/testconnection', 'verb' => 'GET'],
	],
];
