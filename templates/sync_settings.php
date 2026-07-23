<?php
/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * "Sync Actions" panel — all action buttons in one place, rendered last in the
 * section (below Folder mappings). For now that's just the Test-connection button
 * (wired by admin-test.js, loaded from SyncSettings::getForm()); the bulk pull/push
 * buttons join it here when the sync engine lands. Kept parallel to the n8n
 * master's sync_settings.php.
 *
 * @var \OCP\IL10N $l
 */
?>
<div class="section">
<div id="grafana-sync-actions" class="grafana-sync-actions">
	<h3><?php p($l->t('Sync Actions')); ?></h3>

	<p class="settings-hint">
		<?php p($l->t('Check that Nextcloud can reach Grafana — this just tests the connection, nothing is synced. (Bulk sync actions will appear here with the sync feature.)')); ?>
	</p>

	<div id="grafana-sync-test" class="grafana-sync-test-wrap">
		<button type="button" id="grafana-sync-test-btn" class="button">
			<?php p($l->t('Test connection')); ?>
		</button>
		<span id="grafana-sync-test-status" class="msg"></span>
	</div>
</div>
</div>
