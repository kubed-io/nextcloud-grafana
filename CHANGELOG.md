# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

<!--
  These ARE the release notes. One line per entry, written for a user — never a
  paragraph. Length tracks impact: functional changes get the most words (still
  one line); refactors/types/tests stay short; CI/devops are shortest. Only
  **BREAKING:** may stretch. Deeper detail lives in the saga or the PR, not here.

  ONLY EVER EDIT THE [Unreleased] SECTION. Every section below it carries a
  version number and is IMMUTABLE — those notes shipped with a release and must
  never be reworded, reordered, or removed. Add new work under [Unreleased].
  See CONTRIBUTING.md / AGENTS.md.
-->

## [Unreleased]

### Added

- Copying a dashboard next to itself produced a file the app could not see: no dashboard in Grafana, nothing on click, and the original's uid still inside it. Nextcloud names that copy differently to us, and the app now reads its spelling too.
- Moving a dashboard into a folder now creates that folder in Grafana, the same as creating one there does. It was the only one of the two gestures that did nothing.
- The scheduled-sync and recycle-bin toggles can be turned on at last. They reverted to off on every save, silently.
- Tags sync both ways: change them in Nextcloud, in the file, or in Grafana, and every surface agrees.
- Mapped folders and their subfolders carry tags too, in both directions. A root (`/`) mapping is the whole instance rather than a folder, so it has none.
- A sync brings tags with it, on the folders and the dashboards alike.
- Scheduled sync from Grafana now runs — the setting existed but nothing read it.
- A dashboard file carries the dashboard's own created and modified dates, not the sync's.
- Three-way rename: the filename, the JSON `title` and the Grafana title stay in agreement.
- Open a dashboard from the Files app — in Grafana, or its raw JSON in the text editor.
- Create a dashboard by making a file in a mapped sync folder; no button needed.
- Copying a dashboard file makes a new dashboard, and never overwrites the original.
- Moving a dashboard file re-parents its dashboard in Grafana, or deletes it when moved out of every mapping.
- Deleting a dashboard file deletes its dashboard in Grafana — or parks it in a recycle-bin folder, if you turn that on.
- Removing a folder mapping trashes its connected files and leaves standalone files alone.
- A link file cannot be overwritten over WebDAV.
- Sync to and from Grafana, from the admin panel or `occ grafana_sync:sync`.
- `.grafana.json` files show a Grafana icon, and managed files carry a pill matching their mode.
- Admin panels for the connection, folder mappings, sync settings and sync actions.
- Headless setup with `occ`: the token, the connection test, and mappings.

### Changed

- **BREAKING:** the `grafana:sync` / `grafana:link` / `grafana:unmapped` pills are gone. The mapping already decides a file's mode and the file still carries it as metadata, so the pills were a second copy nobody could edit. They are deleted on upgrade, which also removes them from the tag picker.

- **BREAKING:** requires Nextcloud **31** (was 30). Nextcloud 30 is end of life.
- **BREAKING:** a mapping is immutable except for its groups. Remove it and add it again to change one.
- A new mapping defaults to an admin-owned folder, not a Team Folder.
- A mapping's mode defaults to `link` when you don't set one, instead of the mapping being refused.
- The Nextcloud folder name is optional and defaults to the Grafana folder's name.
- A mapped folder appears as soon as you save the mapping, and one that cannot be provisioned is not saved at all.
- The groups a mapped folder is shared with are read from the folder, so sharing it anywhere shows up here.
- A `link` mapping's folder is no longer read-only.
- Internal: specified emptying the trash for a whole folder — the purge reaches every dashboard it held.
- Internal: specified folder tags — a folder's tags are one set, changed from either side, stored on the Grafana folder as an annotation.
- Internal: a dashboard file's modified time now specified to follow Grafana's on a tag change too; a folder tag moves no clock on either side.
- Creating a dashboard in a subfolder now creates the matching folders in Grafana, parents included.
- Syncing from Grafana now mirrors its folder tree: dashboards arrive in the matching Nextcloud subfolder instead of all landing in one folder.
- Renaming a mirrored folder in Nextcloud renames it in Grafana too.
- Moving a mirrored folder re-parents it in Grafana, and moving one between sync and link folders is refused.
- Trashing a mirrored folder deletes it in Grafana, parking its dashboards in the recycle-bin folder when that is on.
- A folder in a link mapping can no longer be trashed, the same as a single link file.
- A dashboard file now shows the time Grafana recorded the change, not the moment Nextcloud saved it.
- A folder can hold only one mapping, and the Grafana recycle-bin folder can no longer be mapped at all.
- Creating a dashboard file in a link-mapped folder is now refused instead of leaving an unmanaged file behind.
- Internal: subfolders become Grafana folders by holding a dashboard, replacing the unbuilt `grafana` opt-in tag.
- **BREAKING:** the per-mapping "Sync subfolders" checkbox is gone; subfolders always mirror. Map a leaf folder if you want a flat mirror.
- Renaming or moving a mapped folder no longer disconnects its dashboards.
- Immutable fields on the admin cards no longer say "(fixed)".
- The connection is one Instance card, and it shows whether a token is already stored.
- Test connection moved into Sync Actions.

### Fixed

- Copying a dashboard file overwrote the dashboard it was copied from, instead of making a new one. The copy's contents landed on the original as a new version, and no second dashboard was ever created.
- A dashboard file could be dated a second before its dashboard existed: Grafana's created and modified clocks can come back either side of a second boundary right after a write, and the app believed the earlier one.
- Internal: a Background could only ever declare one folder mapping — a second one silently replaced the first (no behaviour change; test harness only).
- The Sync Settings checkboxes could never be saved. The recycle-bin one is the serious half: it silently reverting meant every trashed dashboard was permanently deleted in Grafana, which has no undo.
- Syncing never brought Grafana subfolders across: the folder list Grafana answers with only holds top-level folders, so the tree walk found no children — nested folders and the dashboards in them now arrive.
- A recycle-bin folder nested inside another folder can now be found, instead of every delete in bin mode being refused.
- Trashing a folder did nothing in Grafana at all. Nextcloud fires one delete event for a folder and none for the files inside it, so every dashboard under it stayed live — the folder gesture now reaches all of them.
- Emptying the Nextcloud trash left a purged folder's parked dashboards in Grafana forever, for the same reason.
- Restoring a folder from the trash now brings its dashboards back, instead of leaving the delete one-way.
- A restored dashboard goes back into the subfolder it came from, not the top of its mapping.
- Emptying the Nextcloud trash never deleted parked dashboards, so Grafana kept them forever.
- A sync no longer marks every dashboard file as modified.
- Pulled dashboards no longer have their empty JSON objects turned into empty arrays, which corrupted the file and was pushed back to Grafana.
- A link file can no longer be opened in the text editor.
- Test connection tells a missing token from a rejected one, and is no longer reachable without a CSRF token.
- Creating a dashboard from a non-object JSON body errors clearly instead of making an empty dashboard.
- The Grafana glyph in the Files menus themes to the menu colour instead of rendering as a solid yellow tile.
