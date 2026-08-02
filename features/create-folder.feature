# HOW A FOLDER BECOMES A GRAFANA FOLDER, AND HOW YOU CAN TELL THAT IT IS ONE.
#
# ── WHY FOLDERS GET THEIR OWN FEATURE FILES AT ALL ───────────────────────────────
#
# In the n8n sibling a "folder" is a Nextcloud convenience: n8n maps by TAG and has
# no folder concept, so there is nothing on the far side for a folder gesture to
# mean. Grafana has REAL folders, so every folder gesture is a question about two
# systems, exactly as a file gesture is. Splitting `<action>-dashboard` from
# `<action>-folder` is what makes the two readable side by side — and it is what
# made the state of this surface legible at all (see STATUS below).
#
# ── THE ASYMMETRY ────────────────────────────────────────────────────────────────
#
#     every mirrored Grafana folder  →  a folder in Nextcloud     (automatic)
#     SOME Nextcloud folders         →  a folder in Grafana       (opt-in only)
#
# A folder created inside a mapped folder is an ORDINARY FOLDER. Nothing is sent,
# nothing is inferred, and it can hold anything the user likes — notes, exports,
# screenshots, a subfolder of references. **A mapped folder is a real folder and
# must stay usable for ordinary things.** A mapping that silently turned every
# subfolder into a Grafana folder would make the mapped folder unusable for
# anything but dashboards, which is not a trade a user agreed to.
#
# ── THE OPT-IN IS THE `grafana` TAG ──────────────────────────────────────────────
#
# Assigning the `grafana` tag to a folder says "make this a Grafana folder" — a
# deliberate act with a name, exactly as "+ New → Grafana dashboard" is for files.
# The tag is ALSO how the app marks the folders it mirrors, so both directions share
# one visible marker: **if it carries the tag, it is a Grafana folder, whoever made
# it one.** A user cannot tell — and should not have to — whether a folder started
# life in Grafana or was opted in from Nextcloud.
#
# THE MAPPED FOLDER ITSELF NEEDS NO TAG. It is already bound to a Grafana folder by
# the mapping; that IS its identity. The tag answers the question the mapping does
# not: which of the folders *underneath* it are Grafana folders. Same shape as the
# penpot sibling, where a mapped team folder is untagged and the projects inside it
# carry the `penpot` tag.
#
# WHY A TAG AND NOT A CHECKBOX. The current schema has a per-mapping "Sync
# subfolders" flag, and it is the wrong shape twice over: it is all-or-nothing for a
# whole mapping, and it infers intent from a folder's mere existence. It is also
# inert — `syncSubfolders` is stored and validated but no code path reads it, so it
# is a promise the app does not keep. A tag is per-folder, is a first-class
# Nextcloud gesture with an event (`TagAssignedEvent`), and survives a rename or a
# move in a way a name convention or a path-scoped flag cannot.
#
# WHY A TAG AND NOT A NAME CONVENTION: the same reason the app tracks dashboards by
# `grafana_uid` rather than by filename. Names change; markers should not.
#
# ── THE TAG DECORATES; THE ID DECIDES ────────────────────────────────────────────
#
# `grafana_folderUid` on the folder is what the app acts on. The tag is the visible
# badge, re-stamped by every pull — which is why a missing tag is never a state the
# app has to repair specially, and why removing the tag cannot destroy anything.
#
# ── STATUS: THE WHOLE FOLDER SURFACE IS UNBUILT ──────────────────────────────────
#
# `GrafanaClient` has exactly three folder methods — `listFolders`,
# `resolveFolderUidByTitle`, `listDashboards` — all READS. There is no createFolder,
# renameFolder, deleteFolder or moveFolder anywhere in `lib/`, and nothing
# subscribes to `TagAssignedEvent`. So every scenario requiring a folder write is
# @unbuilt, not @todo: no test could pass, because there is nothing to call.
#
# That distinction only became visible when folders were split out of the file
# features. While "moving a dashboard into a subfolder" and "creating the subfolder"
# lived in one file under one file-level @todo, an entire missing subsystem looked
# like a handful of missing tests.
#
# The scenarios that ARE testable today are the ones whose correct outcome is that
# nothing happens in Grafana — which is also the default path, and the one that
# keeps a mapped folder usable.
#
# NOTE ON REQUIREMENTS: `TagAssignedEvent` needs Nextcloud 32. `appinfo/info.xml`
# currently declares 31 (for IDeclarativeSettingsFormWithHandlers) — building this
# means raising that floor, which is a decision for the PR that builds it, not for
# the one that specifies it.

Feature: A folder as a Grafana folder — the opt-in, and the tag that marks it
  As a Nextcloud user
  I want to choose which of my folders are Grafana folders
  So that a mapped folder stays usable for ordinary things

  Background:
    Given the app is connected to Grafana
    And a folder mapped as "sync" to the Grafana folder "alpha"

  # ── the permissive half, and it has to come first ────────────────────────────────

  @user @in-nextcloud @gesture @ui @todo
  Scenario: A new folder inside a mapped folder is just a folder
    When I create a folder "Just My Notes" inside the "alpha" folder
    Then the folder carries no "grafana_folderUid"
    And the folder does not carry the "grafana" tag
    And no folder named "Just My Notes" is created in Grafana

  @user @in-nextcloud @gesture @ui @todo
  Scenario: An untagged subfolder still holds dashboards under the parent mapping
    Given a folder "Scratch" inside the "alpha" folder that carries no "grafana" tag
    When I create a ".grafana.json" file in that subfolder
    Then a dashboard is created in the "alpha" Grafana folder
    And the file is managed "sync" under the "alpha" mapping

  @user @in-nextcloud @gesture @ui @todo
  Scenario: A folder created outside every mapping is not the app's business
    When I create a folder that is not inside any mapped folder
    Then Grafana is not contacted

  # ── opting in ────────────────────────────────────────────────────────────────────

  @user @in-nextcloud @gesture @ui @occ @unbuilt
  Scenario: Tagging a folder "grafana" creates the folder in Grafana
    Given a folder "Client Work" inside the "alpha" folder
    When I assign the "grafana" tag to it
    Then Grafana holds a folder named "Client Work"
    And the Nextcloud folder carries a "grafana_folderUid"

  # THE REASON TO ALLOW OPTING IN LATE. A folder someone has been filling with
  # dashboards becomes a Grafana folder WITH its contents, rather than forcing the
  # decision up front. Before the tag those dashboards belonged to the parent
  # mapping's folder — a folder inside a mapping is still inside the mapping — and
  # one re-parent moves the lot without re-creating or re-id'ing anything.
  @user @in-nextcloud @gesture @ui @occ @unbuilt
  Scenario: A folder opted in late brings the dashboards already inside it
    Given a folder "Late Opt In" inside the "alpha" folder holding two managed "sync" dashboard files
    When I assign the "grafana" tag to it
    Then Grafana holds a folder named "Late Opt In"
    And both dashboards are re-parented into that Grafana folder
    And both keep their "grafana_uid"

  # The common path, because the pull tags every folder it mirrors. A second create
  # here would leave two Nextcloud folders claiming one Grafana folder.
  @user @in-nextcloud @gesture @ui @occ @unbuilt
  Scenario: Tagging a folder that is already a Grafana folder changes nothing
    Given a mirrored Grafana folder "Already Mine" under the "alpha" folder
    When I assign the "grafana" tag to it
    Then Grafana holds exactly one folder named "Already Mine"
    And the Nextcloud folder keeps its "grafana_folderUid"

  # Fail locally and take the tag back off, so the user can rename and re-tag — a
  # two-step they control, rather than a half-created state they have to discover.
  @user @in-nextcloud @gesture @ui @occ @unbuilt
  Scenario: A folder tagged as a Grafana folder must have a usable name first
    Given a folder inside the "alpha" folder whose name Grafana will not accept
    When I assign the "grafana" tag to it
    Then the app refuses and explains what is wrong with the name
    And the tag is not left applied
    And no folder is created in Grafana

  # Tags are instance-wide, so this is not an error to report — no mapping could be
  # resolved for that folder even in principle. Stripping a user's own tag off a
  # folder this app has no business touching would be a worse surprise than an
  # inert label.
  @user @in-nextcloud @gesture @ui @occ @unbuilt
  Scenario: Tagging a folder outside every mapping does nothing at all
    Given a folder "Holiday Photos" outside every mapped folder
    When I assign the "grafana" tag to it
    Then Grafana is not contacted
    And the tag is left where the user put it

  # The mapped folder's identity is the mapping. Tagging it is redundant, and acting
  # on the tag would create a second Grafana folder alongside the one it is already
  # bound to.
  @user @in-nextcloud @gesture @ui @occ @unbuilt
  Scenario: Tagging the mapped folder itself creates nothing
    When I assign the "grafana" tag to the mapped folder
    Then no new folder is created in Grafana
    And the mapping still points at the "alpha" folder

  # ── opting out does not destroy anything ─────────────────────────────────────────

  # Untagging is unmapping, not deleting — the same rule as moving a dashboard out of
  # a mapping. Destroying a Grafana folder and everything in it because someone
  # removed a label would be the worst kind of surprise, and Grafana has no undo.
  @user @in-nextcloud @gesture @ui @occ @unbuilt
  Scenario: Removing the "grafana" tag does not delete the Grafana folder
    Given a mirrored Grafana folder "Keep Me" under the "alpha" folder
    When I remove the "grafana" tag from it
    Then Grafana still holds a folder named "Keep Me"
    And the dashboards inside it are untouched

  # ── the tag as the shared marker ─────────────────────────────────────────────────

  @grafana @in-grafana @occ @ui @unbuilt
  Scenario: A folder created in Grafana arrives as a tagged folder
    Given a Grafana folder "Bubbles" exists under the "alpha" folder
    When the "alpha" mapping is pulled
    Then a Nextcloud folder "Bubbles" exists under the mapped folder
    And it carries a "grafana_folderUid"
    And it carries the "grafana" tag

  # The tag decorates; `grafana_folderUid` decides. Because the id never went
  # anywhere, the pull re-stamps the badge on every run.
  @grafana @in-grafana @occ @ui @unbuilt
  Scenario: A Grafana folder that lost its tag gets it back on the next pull
    Given a mirrored Grafana folder "Retagged" under the "alpha" folder
    And I remove the "grafana" tag from it
    When the "alpha" mapping is pulled
    Then the folder carries the "grafana" tag again

  # The permissive rule, restated from the pull's side: a pull must not tag or claim
  # folders the user made for their own purposes.
  @grafana @in-grafana @occ @ui @unbuilt
  Scenario: A pull never tags a folder the user made for something else
    Given a folder "Scratch" inside the "alpha" folder that carries no "grafana" tag
    When the "alpha" mapping is pulled
    Then the folder still carries no "grafana" tag
    And it still carries no "grafana_folderUid"

  # ── the reserved folder ──────────────────────────────────────────────────────────
  # The recycle-bin folder holds parked dashboards and dashboards Nextcloud does not
  # manage (see delete-dashboard.feature). Letting a tagged folder resolve to it
  # would put the app's own scratch space under user control.
  @user @in-nextcloud @gesture @ui @occ @recycle-bin @unbuilt
  Scenario: A folder cannot be opted in under the recycle-bin folder's name
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    And a folder "nextcloud-trash" inside the "alpha" folder
    When I assign the "grafana" tag to it
    Then the app refuses and explains that the name is reserved
    And the recycle-bin folder is untouched
