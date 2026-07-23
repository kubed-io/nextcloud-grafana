<?php
/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Folder-mapping admin UI. One **card per mapping** (a repeating form, not a
 * table). Each field label carries a ⓘ info button (CSS tooltip) explaining it.
 * Server-renders existing cards; JS handles add/save/delete and fills the Grafana
 * folder picker from GET /apps/grafana_sync/folders.
 *
 * Card grid: Grafana folder + Nextcloud folder on row 1, mode + format on row 2,
 * Save/Delete below.
 *
 * @var array{mappings: list<array<string,mixed>>} $_
 * @var \OCP\IL10N $l
 */

/** @var list<array<string,mixed>> $mappings */
$mappings = $_['mappings'] ?? [];

// Per-field help, shown via the ⓘ tooltip on each label.
$desc = [
	'folder' => $l->t('The Grafana folder to mirror. Its dashboards become the files in the Nextcloud folder. Bound by uid, so a rename in Grafana never breaks the mapping.'),
	'nc' => $l->t('Name of the Nextcloud folder the dashboards appear in.'),
	'mode' => $l->t('Sync: the full dashboard body lives here and edits push back to Grafana. Link: a read-only pointer that opens the dashboard in Grafana.'),
	'format' => $l->t('JSON: the classic Grafana dashboard model (.grafana.json). YAML: the newer k8s-style dashboard schema (.grafana.yaml).'),
];

// Inline an SVG glyph from img/icons/ — the single source of truth for the app's
// icons. Trusted, app-owned files, safe to embed verbatim; the licence-comment
// header is stripped so only the <svg> reaches the DOM.
$icons = [];
$icon = static function (string $name) use (&$icons): string {
	if (!array_key_exists($name, $icons)) {
		$path = __DIR__ . '/../img/icons/' . $name . '.svg';
		$svg = is_file($path) ? (string)file_get_contents($path) : '';
		$icons[$name] = trim((string)preg_replace('/^\s*<!--.*?-->\s*/s', '', $svg));
	}
	return $icons[$name];
};

// Renders a ⓘ info button with a hover/focus tooltip (styled in CSS).
$info = static function (string $tip) use ($icon): string {
	$t = \OCP\Util::sanitizeHTML($tip);
	return ' <span class="grafana-sync-info" tabindex="0" role="note" aria-label="' . $t . '" data-tip="' . $t . '">'
		. $icon('info')
		. '</span>';
};
?>
<div class="section">
<div id="grafana-sync-mappings" class="grafana-sync-mappings"
	data-icons="<?php p(json_encode([
		'info' => $icon('info'),
		'save' => $icon('save'),
		'delete' => $icon('delete'),
	])); ?>">
	<h3 class="grafana-sync-mappings__heading"><?php p($l->t('Folder mappings')); ?></h3>
	<p class="settings-hint">
		<?php p($l->t('Each mapping mirrors a Grafana folder into a Nextcloud folder. Hover the ⓘ on a field for details.')); ?>
	</p>

	<div class="grafana-sync-mappings__list">
		<?php foreach ($mappings as $m): ?>
			<?php
			$uid = (string)($m['grafana_folder_uid'] ?? '');
			$title = (string)($m['grafana_folder_title'] ?? '');
			$modeSel = (($m['mode'] ?? '') === 'link') ? 'link' : 'sync';
			$formatSel = (($m['format'] ?? '') === 'yaml') ? 'yaml' : 'json';
			$label = $title !== '' ? $title . ' (' . $uid . ')' : $uid;
			?>
			<div class="grafana-sync-mappings__card" data-id="<?php p($m['id']); ?>">
				<div class="grafana-sync-mappings__grid">
					<div class="grafana-sync-field gf-folder">
						<label><?php p($l->t('Grafana folder'));
			print_unescaped($info($desc['folder'])); ?></label>
						<select class="js-grafana-folder" data-uid="<?php p($uid); ?>" data-title="<?php p($title); ?>">
							<option value="<?php p($uid); ?>" data-title="<?php p($title); ?>" selected><?php p($label); ?></option>
						</select>
					</div>
					<div class="grafana-sync-field gf-nc">
						<label><?php p($l->t('Nextcloud folder'));
			print_unescaped($info($desc['nc'])); ?></label>
						<input type="text" class="js-nc-folder" value="<?php p($m['nc_folder']); ?>" placeholder="dashboards" />
					</div>
					<div class="grafana-sync-field gf-mode">
						<label><?php p($l->t('Mode'));
			print_unescaped($info($desc['mode'])); ?></label>
						<select class="js-mode">
							<option value="sync" <?php if ($modeSel === 'sync') {
								print_unescaped('selected');
							} ?>><?php p($l->t('Sync')); ?></option>
							<option value="link" <?php if ($modeSel === 'link') {
								print_unescaped('selected');
							} ?>><?php p($l->t('Link')); ?></option>
						</select>
					</div>
					<div class="grafana-sync-field gf-format">
						<label><?php p($l->t('Format'));
			print_unescaped($info($desc['format'])); ?></label>
						<select class="js-format">
							<option value="json" <?php if ($formatSel === 'json') {
								print_unescaped('selected');
							} ?>><?php p($l->t('JSON')); ?></option>
							<option value="yaml" <?php if ($formatSel === 'yaml') {
								print_unescaped('selected');
							} ?>><?php p($l->t('YAML')); ?></option>
						</select>
					</div>
					<div class="grafana-sync-mappings__actions">
						<button type="button" class="button js-save" title="<?php p($l->t('Save')); ?>" aria-label="<?php p($l->t('Save')); ?>">
							<?php print_unescaped($icon('save')); ?>
						</button>
						<button type="button" class="button js-delete" title="<?php p($l->t('Delete')); ?>" aria-label="<?php p($l->t('Delete')); ?>">
							<?php print_unescaped($icon('delete')); ?>
						</button>
						<span class="js-card-status"></span>
					</div>
				</div>
			</div>
		<?php endforeach; ?>
	</div>

	<div class="grafana-sync-mappings__footer">
		<button type="button" id="grafana-sync-mappings-add" class="button">
			+ <?php p($l->t('Add mapping')); ?>
		</button>
		<span id="grafana-sync-mappings-status" class="msg"></span>
	</div>
</div>
</div>
