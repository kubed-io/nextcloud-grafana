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

		// Folder mappings — admin-only CRUD (each handler carries its own
		// #[AuthorizedAdminSetting]). The admin panel hits these endpoints; the occ
		// commands perform the same operations by calling MappingService directly.
		['name' => 'mapping#index', 'url' => '/mappings', 'verb' => 'GET'],
		['name' => 'mapping#create', 'url' => '/mappings', 'verb' => 'POST'],
		['name' => 'mapping#update', 'url' => '/mappings/{id}', 'verb' => 'PUT'],
		['name' => 'mapping#destroy', 'url' => '/mappings/{id}', 'verb' => 'DELETE'],

		// The Grafana folders the token can see — feeds the panel's folder picker.
		['name' => 'mapping#folders', 'url' => '/folders', 'verb' => 'GET'],
	],
];
