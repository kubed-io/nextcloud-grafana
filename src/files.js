/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Files-app integration for grafana_sync.
 *
 * POC stub. The real Files-row surface — a custom `application/grafana+json`
 * mimetype/icon plus an "Open in Grafana" default file action built from the
 * dashboard `uid` — lands with the sync chapters (the master's Phase 5). For now
 * this bundle only announces itself, so the build pipeline, the
 * LoadAdditionalScripts wiring, and `dist/grafana_sync-files.js` all exist and are
 * exercised end to end before there is behaviour to hang on them.
 */
(function () {
	'use strict'
	console.info('[grafana_sync] files bundle loaded (POC stub — dashboard actions land with sync)')
})()
