/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Folder-mapping admin handlers (vanilla JS, no build step). Laid out to match the
 * n8n master so the two apps look the same:
 *
 *   col 1: Grafana folder (picker) · Nextcloud folder
 *   col 2: Mode · Format · Team Folder
 *   col 3: Groups
 *   row 4: Save / Sync / Delete
 *
 * The Grafana folder list is fetched once on init and merged into every card's
 * <select>. Team Folder, Groups, mode and format all persist with the mapping;
 * the per-folder Sync button and the folder-provisioning those groups/team-folder
 * describe arrive with the (not-yet-built) sync engine.
 */
(function () {
	'use strict';

	var MAP_BASE = '/apps/grafana_sync/mappings';
	var FOLDERS_URL = '/apps/grafana_sync/folders';

	// Card glyphs (info / save / sync / delete), read from the root element's
	// data-icons attribute (server-filled from img/icons/).
	var ICONS = {};
	// Grafana folders the token can see: [{uid, title, parentUid}]. Filled by
	// loadFolders(); empty until then (and if Grafana is unreachable).
	var FOLDERS = [];

	function init() {
		var root = document.getElementById('grafana-sync-mappings');
		if (!root || root.dataset.bound === '1') {
			return;
		}
		root.dataset.bound = '1';

		try {
			ICONS = JSON.parse(root.dataset.icons || '{}');
		} catch {
			ICONS = {};
		}

		var list = root.querySelector('.grafana-sync-mappings__list');
		var addBtn = document.getElementById('grafana-sync-mappings-add');

		list.addEventListener('click', function (e) {
			var btn = e.target.closest('button');
			if (!btn) { return; }
			var card = btn.closest('.grafana-sync-mappings__card');
			if (!card) { return; }
			if (btn.classList.contains('js-save')) {
				saveCard(card);
			} else if (btn.classList.contains('js-sync')) {
				syncCard(card);
			} else if (btn.classList.contains('js-delete')) {
				deleteCard(card);
			}
		});

		addBtn.addEventListener('click', function () {
			list.appendChild(buildEmptyCard());
		});

		loadFolders();
	}

	function availableGroups() {
		var root = document.getElementById('grafana-sync-mappings');
		try { return JSON.parse(root.dataset.groups || '[]'); } catch { return []; }
	}

	function tfAvailable() {
		var root = document.getElementById('grafana-sync-mappings');
		return root.dataset.tfAvailable === '1';
	}

	// Fetch the Grafana folder list and merge it into every card's picker. Failure
	// is non-fatal: the server-rendered options (each card's saved folder) stay, and
	// new cards fall back to a "type a uid" text input.
	function loadFolders() {
		api('GET', OC.generateUrl(FOLDERS_URL))
			.then(function (res) {
				FOLDERS = (res && Array.isArray(res.folders)) ? res.folders : [];
				Array.prototype.forEach.call(
					document.querySelectorAll('#grafana-sync-mappings .js-grafana-folder'),
					function (sel) {
						if (sel.tagName === 'SELECT') { fillFolderSelect(sel, sel.dataset.uid || sel.value); }
					}
				);
			})
			.catch(function () { FOLDERS = []; });
	}

	// Rebuild a <select> from FOLDERS, keeping selectedUid selected even if it is not
	// (or no longer) in the fetched list.
	function fillFolderSelect(sel, selectedUid) {
		if (!FOLDERS.length || sel.tagName !== 'SELECT') { return; }
		var seen = false;
		sel.innerHTML = FOLDERS.map(function (f) {
			var isSel = f.uid === selectedUid;
			if (isSel) { seen = true; }
			return '<option value="' + escapeHtml(f.uid) + '" data-title="' + escapeHtml(f.title)
				+ '"' + (isSel ? ' selected' : '') + '>' + escapeHtml(f.title) + ' (' + escapeHtml(f.uid) + ')</option>';
		}).join('');
		if (!seen && selectedUid) {
			var opt = document.createElement('option');
			opt.value = selectedUid;
			opt.dataset.title = sel.dataset.title || '';
			opt.selected = true;
			opt.textContent = (sel.dataset.title ? sel.dataset.title + ' ' : '') + '(' + selectedUid + ')';
			sel.insertBefore(opt, sel.firstChild);
		}
	}

	function escapeHtml(s) {
		return String(s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function readCard(card) {
		var mode = card.querySelector('.js-mode').value === 'link' ? 'link' : 'sync';
		var format = card.querySelector('.js-format').value === 'yaml' ? 'yaml' : 'json';
		var folderEl = card.querySelector('.js-grafana-folder');
		var uid;
		var title = '';
		if (folderEl.tagName === 'SELECT') {
			uid = folderEl.value.trim();
			var opt = folderEl.options[folderEl.selectedIndex];
			title = opt ? (opt.dataset.title || '') : '';
		} else {
			uid = folderEl.value.trim();
		}
		var groups = [];
		Array.prototype.forEach.call(
			card.querySelectorAll('.js-groups input[type="checkbox"]:checked'),
			function (cb) { groups.push(cb.value); }
		);
		var tfEl = card.querySelector('.js-use-team-folder');
		var subEl = card.querySelector('.js-sync-subfolders');
		return {
			id: card.dataset.id || '',
			grafana_folder_uid: uid,
			grafana_folder_title: title,
			nc_folder: card.querySelector('.js-nc-folder').value.trim(),
			mode: mode,
			format: format,
			nc_groups: groups,
			use_team_folder: tfEl ? tfEl.checked : true,
			sync_subfolders: subEl ? subEl.checked : false,
		};
	}

	function saveCard(card) {
		var data = readCard(card);
		if (!data.grafana_folder_uid) {
			cardStatus(card, 'error', t('grafana_sync', 'Pick a Grafana folder first.'));
			return;
		}
		if (!data.nc_folder) {
			cardStatus(card, 'error', t('grafana_sync', 'Name the Nextcloud folder first.'));
			return;
		}
		var isNew = !data.id;
		var url = OC.generateUrl(MAP_BASE + (isNew ? '' : '/' + encodeURIComponent(data.id)));
		api(isNew ? 'POST' : 'PUT', url, data)
			.then(function (res) {
				if (res.mapping && res.mapping.id) {
					card.dataset.id = res.mapping.id;
				}
				cardStatus(card, 'success', t('grafana_sync', 'Saved.'));
			})
			.catch(function (err) {
				cardStatus(card, 'error', err.message || t('grafana_sync', 'Save failed.'));
			});
	}

	// Per-folder sync isn't wired yet — the button exists for parity with n8n. Show a
	// neutral note rather than hitting a missing endpoint.
	function syncCard(card) {
		if (!card.dataset.id) {
			cardStatus(card, 'error', t('grafana_sync', 'Save the mapping first.'));
			return;
		}
		cardStatus(card, '', t('grafana_sync', 'Per-folder sync isn’t available yet — coming with the sync engine.'));
	}

	function deleteCard(card) {
		var id = card.dataset.id || '';
		if (!id) { card.remove(); return; }

		if (!window.confirm(t('grafana_sync', 'Remove this mapping? The Nextcloud folder and the Grafana dashboards are kept.'))) {
			return;
		}
		var url = OC.generateUrl(MAP_BASE + '/' + encodeURIComponent(id));
		api('DELETE', url)
			.then(function () {
				card.remove();
				flash('success', t('grafana_sync', 'Removed.'));
			})
			.catch(function (err) {
				cardStatus(card, 'error', err.message || t('grafana_sync', 'Delete failed.'));
			});
	}

	// Per-mapping status, shown to the right of the card's buttons. Sticky — it stays
	// until the next action or a page reload (no auto-dismiss).
	function cardStatus(card, kind, text) {
		var el = card.querySelector('.js-card-status');
		if (!el) { return; }
		el.textContent = text;
		el.className = 'js-card-status' + (kind ? (' msg ' + kind) : '');
	}

	var DESC = {
		folder: t('grafana_sync', 'The Grafana folder to mirror. Its dashboards become the files in the Nextcloud folder. Bound by uid, so a rename in Grafana never breaks the mapping.'),
		nc: t('grafana_sync', 'Name of the Nextcloud folder the dashboards appear in.'),
		mode: t('grafana_sync', 'Sync: the full dashboard body lives here and edits push back to Grafana. Link: a read-only pointer that opens the dashboard in Grafana.'),
		format: t('grafana_sync', 'JSON: the classic Grafana dashboard model (.grafana.json). YAML: the newer k8s-style dashboard schema (.grafana.yaml).'),
		subfolders: t('grafana_sync', 'Off (default): subfolders are local Nextcloud folders only — the dashboard stays in the mapped folder. On: a subfolder mirrors to a Grafana subfolder the moment a dashboard lands in it. Saved with the mapping; the mirroring engine lands in a later release.'),
		tf: t('grafana_sync', 'On = an ownerless Team Folder (groupfolders). Off = a folder in the admin account shared to the groups. Saved with the mapping; the folder is provisioned when the sync engine lands.'),
		groups: t('grafana_sync', 'Which Nextcloud groups the folder is shared with. Saved with the mapping; applied when the sync engine provisions the folder.'),
	};
	function info(tip) {
		var e = escapeHtml(tip);
		return ' <span class="grafana-sync-info" tabindex="0" role="note" aria-label="' + e + '" data-tip="' + e + '">'
			+ (ICONS.info || '') + '</span>';
	}

	// The Grafana folder control for a new card: a <select> when we have the folder
	// list, else a plain text input so a uid can still be entered by hand.
	function folderControl() {
		if (FOLDERS.length) {
			return '<select class="js-grafana-folder">'
				+ FOLDERS.map(function (f) {
					return '<option value="' + escapeHtml(f.uid) + '" data-title="' + escapeHtml(f.title) + '">'
						+ escapeHtml(f.title) + ' (' + escapeHtml(f.uid) + ')</option>';
				}).join('')
				+ '</select>';
		}
		return '<input type="text" class="js-grafana-folder" placeholder="' + escapeHtml(t('grafana_sync', 'Grafana folder uid')) + '" />';
	}

	function buildEmptyCard() {
		var card = document.createElement('div');
		card.className = 'grafana-sync-mappings__card';
		var tfAttrs = tfAvailable() ? ' checked' : ' disabled';
		var groupBoxes = availableGroups().map(function (g) {
			return '<label class="grafana-sync-groups__item"><input type="checkbox" value="'
				+ escapeHtml(g) + '" /> ' + escapeHtml(g) + '</label>';
		}).join('');
		card.innerHTML = ''
			+ '<div class="grafana-sync-mappings__grid">'
			+   '<div class="grafana-sync-field gf-folder"><label>' + t('grafana_sync', 'Grafana folder') + info(DESC.folder) + '</label>'
			+     folderControl() + '</div>'
			+   '<div class="grafana-sync-field gf-nc"><label>' + t('grafana_sync', 'Nextcloud folder') + info(DESC.nc) + '</label>'
			+     '<input type="text" class="js-nc-folder" placeholder="' + escapeHtml(t('grafana_sync', 'dashboards')) + '" /></div>'
			+   '<div class="grafana-sync-field gf-subfolders"><label class="grafana-sync-checkbox">'
			+     '<input type="checkbox" class="js-sync-subfolders" /> ' + t('grafana_sync', 'Sync subfolders') + info(DESC.subfolders) + '</label></div>'
			+   '<div class="grafana-sync-field gf-mode"><label>' + t('grafana_sync', 'Mode') + info(DESC.mode) + '</label>'
			+     '<select class="js-mode">'
			+       '<option value="sync" selected>' + t('grafana_sync', 'Sync') + '</option>'
			+       '<option value="link">' + t('grafana_sync', 'Link') + '</option>'
			+     '</select></div>'
			+   '<div class="grafana-sync-field gf-format"><label>' + t('grafana_sync', 'Format') + info(DESC.format) + '</label>'
			+     '<select class="js-format">'
			+       '<option value="json" selected>' + t('grafana_sync', 'JSON') + '</option>'
			+       '<option value="yaml">' + t('grafana_sync', 'YAML') + '</option>'
			+     '</select></div>'
			+   '<div class="grafana-sync-field gf-tf"><label class="grafana-sync-checkbox">'
			+     '<input type="checkbox" class="js-use-team-folder"' + tfAttrs + ' /> ' + t('grafana_sync', 'Team Folder') + info(DESC.tf) + '</label></div>'
			+   '<div class="grafana-sync-field gf-groups"><label>' + t('grafana_sync', 'Groups') + info(DESC.groups) + '</label>'
			+     '<div class="js-groups grafana-sync-groups">' + groupBoxes + '</div></div>'
			+   '<div class="grafana-sync-mappings__actions">'
			+   '<button type="button" class="button js-save" title="' + t('grafana_sync', 'Save') + '" aria-label="' + t('grafana_sync', 'Save') + '">'
			+     (ICONS.save || '') + '</button>'
			+   '<button type="button" class="button js-sync" title="' + t('grafana_sync', 'Sync') + '" aria-label="' + t('grafana_sync', 'Sync') + '">'
			+     (ICONS.sync || '') + '</button>'
			+   '<button type="button" class="button js-delete" title="' + t('grafana_sync', 'Delete') + '" aria-label="' + t('grafana_sync', 'Delete') + '">'
			+     (ICONS.delete || '') + '</button>'
			+     '<span class="js-card-status"></span>'
			+   '</div>'
			+ '</div>';
		return card;
	}

	function api(method, url, body) {
		var opts = {
			method: method,
			headers: { 'requesttoken': OC.requestToken, 'Accept': 'application/json' },
		};
		if (body !== undefined) {
			opts.headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify(body);
		}
		return fetch(url, opts).then(function (res) {
			// Parse the body as text first, then JSON-decode only if it actually is
			// JSON — a proxy/HTML error page or an empty body must surface as the real
			// HTTP failure, not a "JSON parse error" from an unconditional res.json().
			return res.text().then(function (text) {
				var data = null;
				if (text) {
					try { data = JSON.parse(text); } catch { data = null; }
				}
				if (!res.ok) {
					var msg = (data && data.message) ? data.message : ('HTTP ' + res.status);
					return Promise.reject(new Error(msg));
				}
				return data || {};
			});
		});
	}

	var flashTimer = null;
	function flash(kind, text) {
		var el = document.getElementById('grafana-sync-mappings-status');
		if (!el) { return; }
		el.textContent = text;
		el.className = kind ? ('msg ' + kind) : 'msg';
		if (flashTimer) { clearTimeout(flashTimer); }
		flashTimer = setTimeout(function () { el.textContent = ''; el.className = 'msg'; }, 5000);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
