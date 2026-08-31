/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Folder-mapping admin handlers (vanilla JS, no build step). Laid out to match the
 * n8n master so the two apps look the same:
 *
 *   col 1: Grafana folder (picker) · Nextcloud folder
 *   col 2: Mode · Team Folder
 *   col 3: Groups
 *   row 4: Save / Sync / Delete
 *
 * The Grafana folder list is fetched once on init and merged into every card's
 * <select>. Mode and Team Folder persist WITH the mapping; GROUPS DO NOT — they
 * are applied to the mapped Nextcloud folder and read back from it, never stored
 * in the mapping row (see MappingService::add and Mapping::toArray, which has no
 * groups key).
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

	// A saved card renders its immutable fields as text carrying data-value; the
	// add-card still renders real controls. Read either.
	function fieldValue(card, selector) {
		var el = card.querySelector(selector);
		if (!el) { return ''; }
		return (el.dataset && typeof el.dataset.value === 'string' ? el.dataset.value : (el.value || '')).trim();
	}

	function readCard(card) {
		var mode = fieldValue(card, '.js-mode') === 'link' ? 'link' : 'sync';
		var folderEl = card.querySelector('.js-grafana-folder');
		var uid = fieldValue(card, '.js-grafana-folder');
		var title = folderEl && folderEl.dataset ? (folderEl.dataset.title || '') : '';
		if (folderEl && folderEl.tagName === 'SELECT') {
			var opt = folderEl.options[folderEl.selectedIndex];
			title = opt ? (opt.dataset.title || '') : '';
		}
		var groups = [];
		Array.prototype.forEach.call(
			card.querySelectorAll('.js-groups input[type="checkbox"]:checked'),
			function (cb) { groups.push(cb.value); }
		);
		var tfEl = card.querySelector('.js-use-team-folder');
		return {
			id: card.dataset.id || '',
			grafana_folder_uid: uid,
			grafana_folder_title: title,
			nc_folder: fieldValue(card, '.js-nc-folder'),
			mode: mode,
			nc_groups: groups,
			use_team_folder: tfEl ? tfEl.checked : true,
		};
	}

	function saveCard(card, purge) {
		var data = readCard(card);
		if (!data.grafana_folder_uid) {
			cardStatus(card, 'error', t('grafana_sync', 'Pick a Grafana folder first.'));
			return;
		}
		// The Nextcloud folder is optional: leave it blank and the backend stores it as the
		// Grafana folder's own name at create (both fields then show in the list).
		var isNew = !data.id;
		// AN EXISTING CARD SENDS ONLY ITS GROUPS. Everything else about a mapping is
		// immutable and the endpoint takes nothing else — sending the rest would be a
		// payload the server is right to ignore, which is exactly how a UI comes to
		// offer an edit that silently does nothing.
		var payload = isNew ? data : { nc_groups: data.nc_groups };
		if (purge) {
			// A COPY, so a retry cannot leave the flag on the card's own state and
			// quietly arm the next save. Passed on the RETRY only, never on the first
			// attempt, so the panel cannot destroy anything the admin has not just been
			// shown a number for.
			payload = Object.assign({}, payload, { purge_dashboards: true });
		}
		var url = OC.generateUrl(MAP_BASE + (isNew ? '' : '/' + encodeURIComponent(data.id)));
		api(isNew ? 'POST' : 'PUT', url, payload)
			.then(function (res) {
				if (res.mapping && res.mapping.id) {
					card.dataset.id = res.mapping.id;
				}
				// Reflect the materialised Nextcloud folder back into the field so a blank
				// entry shows the name the backend filled in.
				if (res.mapping && res.mapping.nc_folder) {
					var ncEl = card.querySelector('.js-nc-folder');
					if (ncEl && 'value' in ncEl && !ncEl.value.trim()) {
						ncEl.value = res.mapping.nc_folder;
					}
				}
				cardStatus(card, 'success', t('grafana_sync', 'Saved.'));
			})
			.catch(function (err) {
				// A link mapping over a folder that already holds dashboard files comes
				// back 422 with a count. Everything else is a dead end and lands in the
				// card's status line; this one becomes a question, because the admin can
				// answer it — and answering it destroys files that do NOT go to the trash.
				if (typeof err.dashboards === 'number' && !purge) {
					confirmPurge(card, err.dashboards, err.folder || data.nc_folder);
					return;
				}
				cardStatus(card, 'error', err.message || t('grafana_sync', 'Save failed.'));
			});
	}

	/**
	 * Ask before destroying dashboard files, and say how many and that they will not
	 * come back.
	 *
	 * THE COUNT AND THE WORD "PERMANENTLY" ARE THE POINT. This is the only gesture in
	 * the app that destroys something outright — a link mirror is a pointer, so a
	 * dashboard file already in the folder cannot survive there, and it may not go to
	 * the trash either: restoring one into a link mapping cannot work, so offering the
	 * restore would be a worse lie than refusing it.
	 *
	 * Cancelling needs no cleanup, and that is a property of the rule rather than an
	 * omission. The admin goes and moves the files, and when they come back the folder
	 * holds none — so the mapping is created with no warning at all.
	 */
	function confirmPurge(card, count, folder) {
		var msg = n(
			'grafana_sync',
			'"{folder}" already holds {count} dashboard file. Mapping it in link mode will permanently delete it — it will not go to the trash and cannot be recovered. Move it elsewhere first if you want to keep it.',
			'"{folder}" already holds {count} dashboard files. Mapping it in link mode will permanently delete them — they will not go to the trash and cannot be recovered. Move them elsewhere first if you want to keep them.',
			count,
			{ folder: folder, count: count }
		);

		window.GrafanaSync.confirmDestructive({
			title: t('grafana_sync', 'Delete these dashboard files?'),
			text: msg,
			confirm: n('grafana_sync', 'Delete {count} file', 'Delete {count} files', count, { count: count }),
			onConfirm: function () {
				saveCard(card, true);
			},
			onCancel: function () {
				cardStatus(card, 'error', t('grafana_sync', 'Not saved — the folder still holds dashboard files.'));
			}
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

		// WHAT THE ADMIN LOSES, IN THE WORDS OF THE MODE THEY PICKED. The old message
		// said the folder and the dashboards are kept — true of both modes, and it left
		// out the half that differs: a `link` mapping's files DO go. Saying so per mode
		// is the difference between a warning and a surprise, and the Grafana half — the
		// one an admin actually fears — is still the reassurance it always was.
		var folder = card.dataset.ncFolder || '';
		var grafanaFolder = card.dataset.grafanaFolder || '';
		//
		// ONE STRING LITERAL PER MESSAGE, NOT A CONCATENATION. `t()` is what the l10n
		// extractor reads, and it reads the SOURCE — a message assembled with `+` is
		// not there to be found, so it would ship untranslatable while looking fine.
		var msg = card.dataset.mode === 'link'
			? t('grafana_sync', 'Remove the mapping from {grafanaFolder} to {folder}? Its linked files will be removed from Nextcloud. Both folders are kept, and Grafana is left alone.', { grafanaFolder: grafanaFolder, folder: folder })
			: t('grafana_sync', 'Remove the mapping from {grafanaFolder} to {folder}? Its dashboard files stay in Nextcloud and become unmapped. Both folders are kept, and Grafana is left alone.', { grafanaFolder: grafanaFolder, folder: folder });

		// THE SAME MODAL THE PURGE USES. This was `window.confirm` — the browser's own
		// box, unthemed, unstyled, and a different voice from every other question this
		// panel asks. One confirmation for the whole app, in `js/dialogs.js`.
		window.GrafanaSync.confirmDestructive({
			title: t('grafana_sync', 'Remove this mapping?'),
			text: msg,
			confirm: t('grafana_sync', 'Remove mapping'),
			onConfirm: function () {
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
			+   '<div class="grafana-sync-field gf-mode"><label>' + t('grafana_sync', 'Mode') + info(DESC.mode) + '</label>'
			+     '<select class="js-mode">'
			+       '<option value="sync" selected>' + t('grafana_sync', 'Sync') + '</option>'
			+       '<option value="link">' + t('grafana_sync', 'Link') + '</option>'
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
					var err = new Error(msg);
					// THE REST OF THE BODY TRAVELS WITH IT. A 422 over existing dashboard
					// files carries a count and a folder name, and the caller turns those
					// into a confirmation — reading them back out of the sentence would
					// break the first time the sentence is reworded. Every other refusal
					// carries only `message`, so this is a no-op for them.
					err.status = res.status;
					if (data && typeof data.dashboards === 'number') {
						err.dashboards = data.dashboards;
						err.folder = data.folder || '';
					}
					return Promise.reject(err);
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
