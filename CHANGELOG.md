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

- Store listing now ships screenshots — the mirrored folder from both sides, the two openers, the recycle bin and the three admin panels — wired into `info.xml` with thumbnails.
- Dragging parked dashboards out of the Grafana recycle-bin folder now brings their trashed Nextcloud folder back with them, keeping the ids and URLs they left with. Nothing noticed before, so the next sync wrote a second copy of every file beside the one still sitting in the trash.
- Mapping a folder in **link** mode when it already holds dashboard files now warns you first, says how many, and offers to move them out instead. A link mapping holds pointers, so those files cannot survive there — before, the mapping was made anyway and left a folder the app had two contradictory answers about.
- The admin panel asks destructive questions in a proper Nextcloud dialog now, themed like the rest of the instance, instead of the browser's grey alert box.
- Copying a folder now duplicates the dashboards inside it: each copy is a new dashboard in a new Grafana folder, and the originals are untouched. Copying a folder used to do nothing in Grafana at all.
- Copying a folder of linked dashboards out of its mapping is refused, with a message. Only a copy *into* a link mapping was ever caught, so the three pointers inside landed in the destination as if someone had authored them there.
- **Sync to Grafana** now makes dashboards of files that have never been pushed — map a folder that already holds `.grafana` files, press the button, and they become real. It used to skip them and say so only in the log.
- A dashboard moved between folders in Grafana now takes its file with it. The mirror was placed correctly when first created and then never moved again, so it stayed where it was through every later sync.
- **Sync to Grafana** no longer skips dashboards in subfolders. It only ever looked at the top level of a mapped folder, on the one button that exists for declaring Nextcloud the source of truth.
- A copy is named once: the filename, the JSON title and the Grafana title all say `Fleet Health (1)`.
- Dashboards in one Grafana folder may share a title; their files take a numbered suffix and keep it.
- Copying a dashboard next to itself produced a file the app could not see: no dashboard in Grafana, nothing on click, and the original's uid still inside it. The copy now lands correctly named and becomes its own dashboard.
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
- `.grafana` files show a Grafana icon, and managed files carry a pill matching their mode.
- Admin panels for the connection, folder mappings, sync settings and sync actions.
- Headless setup with `occ`: the token, the connection test, and mappings.

### Removed

- **The per-mapping Format option (JSON vs YAML) is gone.** Nothing ever read it: there was no YAML serializer, and the only thing it changed was a string in the mapping's config. Dashboards are JSON. A `format` already saved on a mapping is ignored, so nothing needs doing.
- **The "when you save a dashboard file" timing option is gone.** Nextcloud → Grafana writeback now runs in the background where that works and during the save where it does not, decided per instance. Nothing to configure, and no setting to get wrong.
- **The admin "Purge Nextcloud files" button is gone.** It was disabled and had no implementation behind it, so it read as a feature that was merely switched off. Purge means one thing now: emptying the Nextcloud trash, which finishes the delete the trash gesture started.

### Changed

- The admin panel's field help is one sentence per field now, and the base-URL example no longer assumes you run Grafana in Kubernetes.
- The README, the app-store description and the copyright headers were rewritten for someone who has not read the source. The README called tag sync *planned* while it had been shipping for weeks.

- **Removing a mapping no longer costs you anything.** Its sync dashboard files stay where they are and become unmapped — they used to be moved to the trash, which with the recycle bin off *permanently deleted every dashboard in the folder* in Grafana. Linked files still go, both folders are kept on both sides, and Grafana is never contacted.
- Removing a mapping in link mode works at all: it used to fail outright and leave the mapping in place, because the app refuses to delete a linked file and the teardown was asking it to.
- The delete confirmation now says what the mode actually costs — a link mapping's files are removed, a sync mapping's are kept. One message covered both and only described the sync half.
- Refusing to map the recycle-bin folder now says so plainly: *"cannot be mapped because it is the recycle bin"*.
- The recycle-bin toggle and its folder name moved out of Sync Settings into their own **Recycle Bin** section. They decide whether deleting is reversible, which is not a sync setting and is too consequential to read as a footnote to the pull schedule.
- **BREAKING:** dashboard files are named `.grafana`, not `.grafana.json`. Nextcloud only ever reads one file extension, so the compound one meant every save wrote the wrong file type for the app to correct afterwards, and a copy made beside its source was named something the app could not recognise at all. Outside Nextcloud a `.grafana` file needs telling once which editor opens it.

- Supports Nextcloud **34**, and every major in the supported range is now actually executed by the integration suite — after two patch releases of Nextcloud turned out to disagree about behaviour the app relies on.

- **BREAKING:** requires Nextcloud **32** (was 31). Nextcloud 31 was never once run by the test suite, so supporting it was a claim rather than a fact; 32 is the oldest version the suite now proves.

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

- Restoring a folder from a Team Folder's trash now reaches Grafana. Only single files did — a folder came back in Nextcloud while its dashboards stayed in the recycle bin, and the next sync trashed the files again.

- Emptying the Grafana recycle bin now clears the trashed **folders** whose dashboards it destroyed, not just individual files. A folder trashed in Nextcloud is one trash entry, so every mirror inside it was invisible to the reconcile and the entry sat there forever offering a restore that could reconnect to nothing.
- A trashed folder that also holds something this app never managed — a spreadsheet, a note — keeps its trash entry when its dashboards are purged in Grafana, so the file with no dashboard is still there to restore. The dashboard files themselves go, because their dashboards did.
- Trashing a folder in a link mapping now tells you why it was refused. The refusal was already there and correct; the message never reached the client, so the Files app showed a bare failure.
- Dragging a dashboard file onto one that already exists **inside the same mapping** left two dashboards in Grafana with one file between them — and the tags you had put on the destination stayed with the copy that no longer had a file. The overwrite now keeps one dashboard, whichever folders the two files were in.
- **Restoring a dashboard file from the trash permanently deleted the dashboard it was restoring**, then silently replaced it with a new one — a different URL, and no history. Restoring now brings back the dashboard you had.
- Deleting a dashboard file on an instance with the Nextcloud trash turned off now deletes the dashboard, instead of hiding it in the recycle-bin folder behind a file that can never come back for it.
- Restoring a file whose parked dashboard was deleted in Grafana meanwhile now builds a new dashboard, instead of quietly re-creating one at an id that names nothing — or overwriting whatever someone else had since put there.
- Authoring a dashboard file into a link-mapped folder is refused wherever the write comes from. The WebDAV guard let a file created through the Files "New" menu straight through.
- Answering "keep the new version" when a dashboard file lands on one that already exists no longer deletes the dashboard being replaced. The arrival takes over the dashboard that was already there and contributes only its contents, so one overwrite can no longer leave a second dashboard behind for the next sync to write back beside it.
- Keeping BOTH versions of such a collision now gives the arriving file its own dashboard, instead of pointing two files at one.
- Restoring a dashboard file with the recycle bin off rebuilt the dashboard at the id the delete had just destroyed, rather than making a new one — the file's body carries that id inside it.
- Dragging a dashboard back out of the recycle-bin folder in Grafana now brings its trashed file back, instead of leaving a second copy beside a trash entry for the original.
- Restoring a dashboard file from a Team Folder's trash never reached Grafana: with the recycle bin on the dashboard stayed parked, and the next sync trashed the file again — restore, wait, watch it vanish. It comes back properly now.
- A dashboard deleted out of the Grafana recycle-bin folder now clears its trashed file in Nextcloud too, instead of leaving a trash entry that could not be restored to anything.
- Dragging a dashboard file out of its mapped folder always deleted the dashboard, even with the recycle bin on — the same removal you get from the trash now parks it in the bin folder instead, and moving the file back brings the same dashboard out again.
- A dashboard file moved back into a mapping stayed marked unmapped, so later gestures treated a live mirror as though it belonged to nothing.
- Emptying a Team Folder's trash never reached Grafana: the dashboard stayed parked in the recycle-bin folder for good, with nothing to say so. It is deleted now, like emptying any other trash.
- A linked dashboard could be copied, deleted or renamed from Nextcloud. Each looked like it worked and was undone by the next sync; all three are refused with a message now, as editing one already was.
- A dashboard file could be renamed to nothing but spaces, leaving its filename, its JSON title and its Grafana title disagreeing three ways with nothing to say so.
- A linked dashboard could be dragged into another mapped folder. Nothing said no, and the next sync moved it back — the move is now refused with a message, as moving one out already was.
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
