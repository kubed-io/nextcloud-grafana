<?php
/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Classic "Test connection" panel: one button that hits an authenticated Grafana
 * endpoint to prove the saved URL + token work. JS + CSS are loaded by
 * AdminTest::getForm() via Util::addScript() / addStyle() so they pick up the
 * Nextcloud CSP nonce (inline <script> is blocked by the strict CSP).
 *
 * @var \OCP\IL10N $l
 */
?>
<div class="section">
	<h3 class="grafana-sync-test__heading"><?php p($l->t('Test connection')); ?></h3>
	<div id="grafana-sync-test" class="grafana-sync-test-wrap">
		<button type="button" id="grafana-sync-test-btn" class="button">
			<?php p($l->t('Test connection')); ?>
		</button>
		<span id="grafana-sync-test-status" class="msg"></span>
	</div>
</div>
