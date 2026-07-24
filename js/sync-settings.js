/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Sync Actions handlers (vanilla JS, no build step) — saga Ch2 Course 2.
 *
 * Only "Sync from Grafana" (pull) is wired here: it is a synchronous, local-scale
 * run (homelab instances finish in one request), so the click POSTs
 * /apps/grafana_sync/sync/pull, disables the button while in flight, and flashes the
 * run counts. "Sync to Grafana" (push) + "Purge" are rendered disabled in the
 * template until the writeback release, so they carry no handler yet — this file
 * grows a branch each when its engine lands (mirroring the n8n master's
 * sync-settings.js, which also gains the async status-poll then).
 */
(function () {
	'use strict';

	function init() {
		var root = document.getElementById('grafana-sync-manual');
		if (!root || root.dataset.bound === '1') {
			return;
		}
		root.dataset.bound = '1';

		root.addEventListener('click', function (e) {
			var btn = e.target.closest('.js-run');
			if (!btn || btn.disabled) {
				return;
			}
			var row = btn.closest('.grafana-sync-manual__row');
			// Only the pull row is live in this release; ignore any other .js-run.
			if (!row || row.dataset.direction !== 'pull') {
				return;
			}
			pull(btn);
		});
	}

	// Pull is synchronous: the POST reconciles every mapping inline and returns the
	// counts, which we surface in the status line + a toast.
	function pull(btn) {
		var prev = btn.textContent;
		btn.disabled = true;
		btn.textContent = t('grafana_sync', 'Syncing…');

		api('POST', OC.generateUrl('/apps/grafana_sync/sync/pull'))
			.then(function (res) {
				if (res && res.status === 'error') {
					throw new Error(res.message || t('grafana_sync', 'Sync failed.'));
				}
				flash('success', summary(res));
			})
			.catch(function (err) {
				flash('error', (err && err.message) || t('grafana_sync', 'Sync failed.'));
			})
			.then(function () {
				btn.disabled = false;
				btn.textContent = prev;
			});
	}

	function summary(res) {
		res = res || {};
		var line = t('grafana_sync', 'Synced {succeeded} dashboard(s); {failed} error(s).', {
			succeeded: res.succeeded || 0,
			failed: res.failed || 0,
		});
		if (res.pruned) {
			line += ' ' + t('grafana_sync', 'Pruned {pruned}.', { pruned: res.pruned });
		}
		if (res.message) {
			line += ' ' + res.message;
		}
		return line;
	}

	function api(method, url) {
		return fetch(url, {
			method: method,
			headers: {
				'requesttoken': OC.requestToken,
				'Accept': 'application/json',
			},
		}).then(function (res) {
			return res.json().then(function (data) {
				if (!res.ok) {
					return Promise.reject(new Error(data && data.message ? data.message : 'HTTP ' + res.status));
				}
				return data;
			});
		});
	}

	var flashTimer = null;
	function flash(kind, text) {
		var el = document.getElementById('grafana-sync-manual-status');
		if (!el) {
			return;
		}
		el.textContent = text;
		el.className = 'msg ' + kind;
		if (flashTimer) {
			clearTimeout(flashTimer);
		}
		flashTimer = setTimeout(function () {
			el.textContent = '';
			el.className = 'msg';
		}, 6000);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
