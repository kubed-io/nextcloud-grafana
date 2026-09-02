<?php
/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * "Sync Actions" panel — the single home for every action button in the section,
 * rendered last (below Folder mappings), laid out to match the n8n master's
 * sync_settings.php so the two apps look the same and reduce into a shared base:
 *
 *   • Manual bulk sync — "Sync to Grafana" / "Sync from Grafana"
 *   • Connection test — "Test connection" (wired by admin-test.js)
 *
 * Every button here is LIVE — a control that asks for something it cannot do reads as
 * a feature that works. Purge means one thing in this app, and it is the Nextcloud one:
 * emptying the trash (see features/dashboards/purge.feature).
 *
 * @var \OCP\IL10N $l
 */
?>
<div class="section">
<div id="grafana-sync-manual" class="grafana-sync-manual">
	<h3><?php p($l->t('Sync Actions')); ?></h3>

	<p class="settings-hint">
		<?php p($l->t('Run a one-shot bulk sync at any time. Sync from Grafana pulls every mapped folder’s dashboards into Nextcloud; Sync to Grafana pushes your local edits back up.')); ?>
	</p>

	<div class="grafana-sync-manual__row" data-direction="push">
		<button type="button" class="button js-run"><?php p($l->t('Sync to Grafana')); ?></button>
		<span class="grafana-sync-manual__hint"><?php p($l->t('(two-way sync mappings only)')); ?></span>
	</div>

	<div class="grafana-sync-manual__row" data-direction="pull">
		<button type="button" class="button primary js-run"><?php p($l->t('Sync from Grafana')); ?></button>
	</div>

	<div class="grafana-sync-manual__footer">
		<span id="grafana-sync-manual-status" class="msg"></span>
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

