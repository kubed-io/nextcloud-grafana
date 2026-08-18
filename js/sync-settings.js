/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Sync Actions handlers (vanilla JS, no build step).
 *
 * Both bulk directions are wired here through one `.js-run` click handler, which
 * reads its direction off the row's `data-direction`. Each is a synchronous,
 * local-scale run (homelab instances finish in one request), so the click POSTs
 * /apps/grafana_sync/sync/{pull,push}, disables the button while in flight, and
 * flashes the run counts.
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
			var direction = row && row.dataset.direction;
			// pull = Grafana → NC (populate); push = NC → Grafana (writeback). Both live.
			if (direction !== 'pull' && direction !== 'push') {
				return;
			}
			run(btn, direction);
		});
	}

	// Both directions are synchronous: the POST reconciles every mapping inline and
	// returns the counts, which we surface in the status line + a toast.
	function run(btn, direction) {
		var prev = btn.textContent;
		btn.disabled = true;
		btn.textContent = direction === 'push'
			? t('grafana_sync', 'Pushing…')
			: t('grafana_sync', 'Syncing…');

		api('POST', OC.generateUrl('/apps/grafana_sync/sync/' + direction))
			.then(function (res) {
				if (res && res.status === 'error') {
					throw new Error(res.message || t('grafana_sync', 'Sync failed.'));
				}
				flash('success', summary(res, direction));
			})
			.catch(function (err) {
				flash('error', (err && err.message) || t('grafana_sync', 'Sync failed.'));
			})
			.then(function () {
				btn.disabled = false;
				btn.textContent = prev;
			});
	}

	function summary(res, direction) {
		res = res || {};
		var line = direction === 'push'
			? t('grafana_sync', 'Pushed {succeeded} dashboard(s); {failed} error(s).', {
				succeeded: res.succeeded || 0,
				failed: res.failed || 0,
			})
			: t('grafana_sync', 'Synced {succeeded} dashboard(s); {failed} error(s).', {
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
			// Parse the body as text first, then JSON-decode only if it actually is
			// JSON — a proxy/HTML error page or an empty body (common on 500s) must
			// surface as the real HTTP failure, not a "JSON parse error" from an
			// unconditional res.json(). Same pattern as mapping-settings.js.
			return res.text().then(function (text) {
				var data = null;
				if (text) {
					try { data = JSON.parse(text); } catch { data = null; }
				}
				if (!res.ok) {
					return Promise.reject(new Error(data && data.message ? data.message : 'HTTP ' + res.status));
				}
				return data || {};
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
