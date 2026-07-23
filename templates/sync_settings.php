<?php
/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * "Sync Actions" panel — the single home for every action button in the section,
 * rendered last (below Folder mappings), laid out to match the n8n master's
 * sync_settings.php so the two apps look the same and reduce into a shared base:
 *
 *   • Manual bulk sync — "Sync to Grafana" / "Sync from Grafana"
 *   • Purge — remove the dashboard files this app created (Nextcloud side only)
 *   • Connection test — "Test connection" (wired by admin-test.js)
 *
 * NB (honest UI, saga Ch2 Course 1): the bulk-sync + purge buttons are the master's
 * layout rendered **disabled** — they move real dashboards, and the sync engine that
 * does that lands in a later release (Course 2/3). They're here so the admin page is
 * finalized and shows exactly what's coming; each enables (and gains its handler,
 * ported from the master) when its engine arrives. **Test connection works today.**
 *
 * @var \OCP\IL10N $l
 */

// Tooltip on every not-yet-live button — one string, so the promise is consistent.
$soon = $l->t('Available once dashboard sync lands (a later release). Test connection works now.');
?>
<div class="section">
<div id="grafana-sync-manual" class="grafana-sync-manual">
	<h3><?php p($l->t('Sync Actions')); ?></h3>

	<p class="settings-hint">
		<?php p($l->t('Run a one-shot bulk sync at any time. Sync to/from Grafana and Purge arrive with dashboard sync (a later release) — until then these buttons are disabled. Test connection works now.')); ?>
	</p>

	<div class="grafana-sync-manual__row" data-direction="push">
		<button type="button" class="button js-run" disabled title="<?php p($soon); ?>"><?php p($l->t('Sync to Grafana')); ?></button>
		<span class="grafana-sync-manual__hint"><?php p($l->t('(two-way sync mappings only)')); ?></span>
	</div>

	<div class="grafana-sync-manual__row" data-direction="pull">
		<button type="button" class="button js-run" disabled title="<?php p($soon); ?>"><?php p($l->t('Sync from Grafana')); ?></button>
	</div>

	<div class="grafana-sync-manual__footer">
		<span id="grafana-sync-manual-status" class="msg"></span>
	</div>

	<p class="settings-hint grafana-sync-actions__sep">
		<?php p($l->t('Reset the Nextcloud side. Purge removes the dashboard files this app created (sync & link). Grafana is never touched, and unmapped/standalone files are kept — get the rest back any time with “Sync from Grafana”.')); ?>
	</p>

	<div class="grafana-sync-manual__row" data-action="purge">
		<button type="button" class="button js-purge" disabled title="<?php p($soon); ?>"><?php p($l->t('Purge Nextcloud files')); ?></button>
		<span id="grafana-sync-purge-status" class="msg"></span>
	</div>

	<p class="settings-hint grafana-sync-actions__sep">
		<?php p($l->t('Check that Nextcloud can reach Grafana — this just tests the connection, nothing is synced.')); ?>
	</p>

	<div id="grafana-sync-test" class="grafana-sync-test-wrap">
		<button type="button" id="grafana-sync-test-btn" class="button"><?php p($l->t('Test connection')); ?></button>
		<span id="grafana-sync-test-status" class="msg"></span>
	</div>
</div>
</div>

