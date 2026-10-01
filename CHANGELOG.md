# Changelog

All notable changes to NextCloud Arcade. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the app uses
[semantic versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.43.2] - 2026-10-01

### Added
- An Overclock option for the NES core, which runs the processor twice per
  frame so the games that slow down when the screen fills up need not:
  off, or the extra cycles given to either half of the frame, since games
  disagree about which one they tolerate.
- What the emulator core itself has to say is now passed on to the player
  rather than left in the browser console. Only what can be acted on, each
  thing once, and a few at most, so a core having a bad time cannot paper
  the screen over.

### Changed
- Everything the library and the player say is now said with the same
  notification the rest of Nextcloud uses — the one that slides in at the
  top right — instead of a line of text inside the player's own chrome.
- The buttons in the player's top-right corner step aside while a message
  is on screen, so the message can be read. They come back as soon as it
  goes, and a message that waits to be dismissed never takes them away:
  that one asks for a button that lives there.
- A message confirming something done — a game saved, a game restarted —
  clears after three seconds. Anything that went wrong stays the usual
  seven.
- The automatic save made on a timer no longer announces itself. Nobody
  asked for it, and it arrives in the middle of a game. A save that fails
  still says so, whoever asked for it.
- Refresh on the library page is turned off while a rescan is already
  running, and says why.
- A BIOS file is matched by name without regard to case everywhere, the
  `occ arcade:bios` command included, which used to refuse a spelling the
  player was happily serving. The store keeps whatever arrives under the
  one spelling the cores ask for.

### Fixed
- The player's messages were not appearing at all. The toast was asked for
  by CSS selector, which the toaster the server ships reads as an element
  id; finding nothing, it threw, and took down whatever was reporting —
  so loading a save state said it had failed after it had worked.

## [0.42.0] - 2026-09-27

### Fixed
- The battery save written while playing is no longer lost when the game
  is closed: the last upload is waited for instead of being cut short by
  the emulator shutting down.
- Holding the save or load key no longer writes a broken save state: the
  repeats are ignored while one is still going.
- A game closed while it was still loading is stopped properly. It used to
  keep running unseen, with its sound, its keys and its uploads.
- Escape leaves fullscreen when the game is fullscreen, instead of leaving
  fullscreen and quitting the game in the same press. In the Files viewer
  Escape closes the viewer again.
- Closing no longer walks away from a save that failed: it says so and
  asks to be pressed again to leave without saving.
- Restarting a paused game no longer leaves the button saying "Resume"
  over a running game, which also kept the chrome and the autosave stuck.
- The screenshots button now appears as soon as a game has its first
  screenshot, and the panel shows the ones taken since it opened.
- A panel that cannot reach the server says so and offers to try again,
  rather than opening empty.
- Text typed into the library search is no longer wiped by a listing that
  arrives while typing.
- A key binding left waiting for a key no longer swallows the keyboard of
  the whole page; it gives up on its own, and can be left with Escape, a
  click elsewhere or by moving on.
- A key that only the browser would ever see -- Control, Alt or Meta --
  is refused as a hot key instead of being saved as one that can never
  work, and a key bound twice within the same kind is pointed out.

### Changed
- The player and the library can be used from the keyboard throughout:
  Space and Enter work the buttons of the chrome again, focus follows what
  was pressed instead of being thrown away by every sort, page or filter,
  and the chrome that fades over an idle game leaves the tab order with it.
- What the app has to say is said where a screen reader hears it: the
  player's status line, the library's result count, its empty state and
  the first-run suggestions all announce themselves; toggles say whether
  they are on; each save slot and screenshot names what its buttons act on.
- The settings switches stay legible in forced-colours mode, and what is
  wrong with a key binding is written in the page rather than only in a
  tooltip.
- Motion is dropped where the system asks for less of it.

## [0.41.1] - 2026-09-27

### Fixed
- A battery save written before a saves folder was configured is found
  again: save states survived that move all along, battery saves did not.
  Deleting one now clears it from both places, so a delete stays final.

### Changed
- The documentation states each fact in one place and links to it from
  the rest; several claims that had drifted from the code were corrected.
- Housekeeping across the newest code: shared helpers where three copies
  had appeared, a dead cache-unpacking branch removed, and caches opened
  once per request instead of twice.

## [0.41.0] - 2026-09-27

### Added
- The app answers Nextcloud's capabilities endpoint, so a client can ask
  what it supports -- the systems it plays, the features it offers and the
  limits an administrator set -- without guessing.

## [0.40.1] - 2026-09-27

### Security
- The emulator's Content Security Policy allowances on public share pages
  now apply only to shares that actually hold a game; every other share
  keeps the instance's default policy.
- The library and folder-suggestion endpoints are rate limited, generously
  enough that no player ever meets the limit, and the suggestion scan is
  bounded in the database query itself.
- Fetched box art is checked before it is stored: size, content type and
  the first bytes all have to look like an image.
- Save state endpoints check that the game is among the user's files;
  deleting saves of a game that is already gone still works.

### Changed
- The app now falls back to the local memory cache when no distributed one
  is configured, and the admin overview says so when there is none at all
  -- without one, the library was rescanned on every request.
- The fallback images of games without box art are cached against the
  screenshots and saves folders, saving a folder listing per game on
  every library request.
- Smaller savings across the board: folder etags and save folders are
  resolved once per request, the preview index survives memcached's size
  cap, and the weekly sweep only asks about users who have signed in.

## [0.40.0] - 2026-09-27

### Changed
- Pausing the game while the tab is in the background and saving the
  game automatically when closing the player are now off by default.
  Only users who never touched either toggle are affected; a choice
  made in the settings stays as it was.

### Fixed
- Saving a single setting no longer clears the others: the onboarding's
  "Use this folder" button used to wipe every folder and toggle that was
  not part of its request.
- Replacing a box art image under the same name shows the new picture in
  the library right away: preview URLs are now versioned by the
  thumbnails folder, where before the browser kept the old image out of
  its cache for a day -- `/core/preview` is served immutable.

## [0.39.9] - 2026-09-27

### Added
- The cleanup sweep of `occ arcade:cleanup` also runs weekly on its own,
  so saves left behind by trash expiry -- which the server announces to
  nobody -- no longer wait for an administrator to notice.
- The frontend gained its first unit tests (vitest), run on every push.

### Changed
- The file picker no longer uses a deprecated dialogs API.

## [0.39.8] - 2026-09-27

### Fixed
- Emptying the trash bin now cleans up the save states, battery save and
  registry entry of the games it lets go; trashing a game keeps them, so a
  restored game picks its saves right back up. Deleting a game without a
  trash bin cleans up immediately again (a type error had silently broken
  that), and `occ arcade:cleanup` no longer removes the saves of games
  sitting restorably in the trash.

### Changed
- The dialogs library moved to its current major, and builds stay
  byte-reproducible with per-chunk styles.
- BIOS file sizes in the administration read like the library's ("512 KB").
- The app description now tells the whole story.

## [0.39.7] - 2026-09-27

### Fixed
- The "Saved" message on the settings pages no longer disappears early
  when two changes are saved within a few seconds of each other.
- Opening a game no longer asks the server for its screenshot list twice.

### Changed
- Duplicated logic across the app was consolidated behind shared helpers;
  no behavior changes beyond the fixes above.

## [0.39.6] - 2026-09-27

### Added
- The Activity app is told when a game starts, when a session longer than
  a minute ends, and when a save state is written. The stream is the
  player's own, seen by nobody else, and can be muted in the Activity
  settings.
- A notification under the bell when a box art run finishes, saying what
  it found. The player says a word, once, when a system wanted BIOS files
  and none were found.
- The games library can be browsed with a gamepad: the d-pad or left
  stick moves between games, A launches the one in focus, B backs out to
  the search field, and L1/R1 turn the pages.
- `build/smoke-test.sh` installs the app into a real Nextcloud, seeds
  ROMs, walks the endpoints and upgrades in place. CI runs it on alpha,
  beta and rc tags, alongside the JavaScript build.

### Changed
- A filled save state slot is labeled with its date alone; the screenshot
  already says which game it is.
- The admin BIOS section lists only the files the cores ask for. The
  listing of stray files nothing asks for is gone.

## [0.39.5] - 2026-09-27

### Changed
- The patch version rolls whenever the bundles are rebuilt, so browsers
  fetch fresh scripts instead of trusting their caches. This release is
  that and nothing else.

## [0.39.4] - 2026-09-26

### Fixed
- BIOS files are matched without regard to case, wherever they are looked
  for: `SCPH1001.BIN` serves as `scph1001.bin`, in the player and on the
  administration page alike.

## [0.39.3] - 2026-09-26

### Changed
- The BIOS section of the administration settings is backed by the
  administrator's own System folder: it shows what a player would find,
  uploads land in that folder under the canonical name, and the section
  only appears once a System folder is set. The instance-wide store that
  `occ arcade:bios` fills stays as the fallback.

## [0.39.2] - 2026-09-26

### Added
- A top bar over the player, with a close button and an actions menu:
  Full screen, the RetroArch menu, Restart, Open sidebar — or Details,
  where no sidebar can be had — and Download. The chrome fades over an
  idle game and comes back at a touch of anything.
- Rewind, and run-ahead to hide input lag, as personal settings; each
  costs something, so both start off.
- The in-game battery save can be deleted from the save states panel.
- More core options for administrators: NES NTSC filtering, the Game Boy
  bootloader and model, Genesis address-error strictness, Super Nintendo
  overclocking.
- The CRC32 of a ROM — the checksum No-Intro lists games by — is stored
  alongside its MD5 when checksums are worked out.

### Changed
- Every route of the app lives under `/arcade` now. The old URLs are
  gone, not redirected.
- A game is played by its file id where there is one, so a play URL
  survives renames and moves; the path form stays as the fallback.
- Zipped ROMs are offered for playing only inside the games library,
  through "Play with Arcade" in the file menu. The Viewer claimed zip
  archives for a moment during this release and deliberately stopped: a
  zip is not guaranteed to be a game.
- The endpoints that write are rate limited, generously enough that no
  player ever meets the limit.

## [0.39.1] - 2026-09-24

### Added
- PlayStation, through the `pcsx_rearmed` core: `.chd` and `.pbp` files.
  Its BIOS is strongly recommended.
- Play statistics in the library: how many times and how long each game
  was played, and sorting by either.
- Atari Lynx, Neo Geo Pocket, Virtual Boy and NES headers are read as
  well.
- An Arcade tab in the Files sidebar: the system, what the cartridge
  calls itself, play time, the saves waiting, and a button that plays it.
- BIOS management on the administration settings page, per system.
- First-run suggestions: when the library folder is missing or empty, the
  folders that already hold ROMs are offered.
- `occ arcade:status` reports the save state storage and play activity of
  every user.
- Previews are warmed right after box art is fetched, so the first paint
  of the library is not a request per game against a cold cache.

### Changed
- The Content Security Policy additions are applied for the users who can
  use the app and the public pages that need them, instead of everywhere.

### Fixed
- Extensions the server already maps are not taken over: `.md` is
  Markdown to Nextcloud before it is a Mega Drive dump. Files an earlier
  version retyped are given their server mimetype back at upgrade, or
  sooner with `occ maintenance:repair`. A `.md` file still counts as a
  game when its mimetype or its folder says so.

## [0.39.0] - 2026-09-23

### Changed
- Everything older versions left behind is converted once, at upgrade,
  instead of being looked for on every request: the `games.json` registries
  go into the `arcade_games` table, save states filed under the hash of
  their path are renamed to the id of their file, states from before they
  were kept per user move in with their user, saves folders from before the
  system was part of the path move under the system, and the play records
  and favorites of the user config go into the `arcade_plays` table and the
  Files app. Whatever cannot be matched is never deleted or overwritten --
  it stays exactly where it is, and `occ arcade:cleanup` counts what is
  left. The perpetual fallbacks that read the old places are gone.

## [0.38.0] - 2026-09-22

### Changed
- The play statistics and the recently played list moved from JSON blobs in
  the user config into a table, `arcade_plays`. Plays and play time are
  counted in the database itself, so two sessions ending together both
  count, and the cap of 200 games with statistics is gone. What the blobs
  held is brought over the first time a user's records are touched.
- The registry of games with save states moved from a `games.json` in the
  app data into a table, `arcade_games`. An existing `games.json` is
  brought over the first time a user's registry is touched, and then
  removed.
- `occ arcade:uninstall` drops both tables, which removing the app would
  leave behind.

## [0.37.0] - 2026-09-22

### Added
- `.bin` and `.rom` files are listed and played. Neither name says which
  machine it is for, so the folder is asked, and then the first bytes of
  the file: a cartridge dump says whose it is, which also tells a 32X game
  from a Mega Drive one. Where the folder and the file disagree, the file
  wins.

## [0.36.1] - 2026-09-22

### Changed
- Back to AGPL-3.0-or-later, which is what the app was under before 0.36.0
  and what Nextcloud itself uses.

## [0.36.0] - 2026-09-22

### Changed
- Licensed GPL-3.0-or-later, where it was AGPL-3.0-or-later.
- The readme is the short of it -- what the app is, how to install it and
  how to use it. Everything else moved to `docs/`.

## [0.35.0] - 2026-09-22

### Added
- Screenshots of the player, the games library in both views, and the two
  settings pages, in the app store listing and the readme.

## [0.34.0] - 2026-09-22

### Added
- A changelog, an authors file, and the app store metadata other apps
  carry: a website, documentation links, a discussion link and a second
  category.

## [0.33.0] - 2026-09-21

### Added
- BIOS files can be offered to every player at once, put there by an
  administrator with `occ arcade:bios`. A player's own system folder comes
  first; what it has not got is taken from the instance.

## [0.32.0] - 2026-09-21

### Added
- The region a cartridge names is tried first when looking for its box art.
- A save state made against a different dump of a ROM is marked in the
  player, so a state that will not load is not a mystery.

### Changed
- The recently played are kept by file id, so a game keeps its place in the
  list when it is renamed or moved.

## [0.31.0] - 2026-09-21

### Changed
- One shared box art lookup for the games library and the Files preview,
  which had already drifted apart.
- The uninstall command and the disable step drop the same caches and jobs,
  from one list.
- The metadata job asks after a chunk of a library at a time rather than all
  of it, on every run.

## [0.30.0] - 2026-09-21

### Added
- Administrators can turn off working out ROM checksums, and it is off to
  start with: checksums that arrive with an upload are still kept.

### Fixed
- Screenshots of save states are found again for games filed under their
  system, which the saves folder has done since 0.19.0.

## [0.29.0] - 2026-09-21

### Added
- Administration settings for looking up box art, how many games a library
  scan lists, how deep it goes and how long it is kept.

### Changed
- The picture each system is shown with is a personal setting now, with the
  administration value as the default.

## [0.28.0] - 2026-09-21

### Added
- Rescanning the games library asks for the ROMs that were already there to
  be read, a background job at a time.

## [0.27.0] - 2026-09-21

### Added
- The name a cartridge gives itself is read from Game Boy, Game Boy Advance,
  Super Nintendo and Mega Drive headers, and is what box art is matched on
  when the file name finds nothing.

### Fixed
- A game deleted into the trash keeps its save states: they go when the
  trash lets go of it, not before.

## [0.26.0] - 2026-09-21

### Added
- A "Play with Arcade" entry in the file menu, for zipped ROMs and for files
  whose mimetype Nextcloud has not learned yet.
- Box art is the Nextcloud preview of a ROM, so a folder of games looks like
  a shelf of games in the Files app.

### Changed
- Save states, battery saves and play time follow a game that is renamed or
  moved, being kept by file id.
- Folder detection understands spelled out names, the maker in front, and a
  word like "ROMs" on the end.

## [0.25.0] - 2026-09-21

### Changed
- Favorites are the stars of the Files app: the same star in both places,
  kept by file id.

## [0.24.0] - 2026-09-21

### Fixed
- ROMs are given their mimetype as they are uploaded. The app declares
  itself a filesystem app, without which it is not loaded during an upload.

## [0.23.0] - 2026-09-21

### Changed
- Nextcloud 34 or newer.

## [0.22.0] - 2026-09-21

### Changed
- Nextcloud 33 or newer, and PHP 8.3 or newer.
- User settings are read and written through `IUserConfig`.

## [0.21.0] - 2026-09-21

### Added
- `occ arcade:uninstall` removes everything the app has stored, before the
  app itself is removed.

## [0.20.0] - 2026-09-21

### Changed
- The app is called Arcade.

## [0.19.0] - 2026-09-21

### Changed
- Saves are filed under the system of the game, so two games of the same
  name on different systems do not share a folder.
- Saving is turned off entirely when no saves folder is set, and the player
  says so.

## [0.18.0] - 2026-09-21

### Added
- Every button and player key can be rebound.
- A system folder for BIOS files.
- Core options moved to the administration settings, where they belong to
  the core rather than to whoever is playing.

## [0.17.0] - 2026-09-21

### Changed
- The emulator is fetched when a game opens rather than with every page of
  the Files app.

## [0.16.0] - 2026-09-21

### Added
- An Auto slot the player writes itself, on closing and at an interval.

## [0.15.0] - 2026-09-21

### Added
- Favorites, how long each game was played, and box art downloaded from the
  libretro thumbnail server in the background.

## [0.14.0] - 2026-09-21

### Added
- `occ arcade:cleanup`, for save states whose game or user is gone.

## [0.13.0] - 2026-09-20

### Changed
- Three save slots per game, and the paths they are stored under are worked
  out with less work.

## [0.12.0] - 2026-09-20

### Added
- The player writes a save state when it is closed.

## [0.11.0] - 2026-09-20

### Added
- A screenshot gallery in the player, and a test suite and CI.

## [0.10.0] - 2026-09-20

### Added
- A game without box art is shown with its own most recent screenshot.

## [0.9.0] - 2026-09-20

### Added
- Recently played, and the options of each emulator core.

## [0.8.0] - 2026-09-20

### Changed
- One core per system, fuzzy thumbnail matching, and a cached library scan.

## [0.7.0] - 2026-09-20

### Added
- Filters in the games library, and box art from the libretro thumbnail
  server.

## [0.6.0] - 2026-09-20

### Added
- Grid, list and table views of the games library, with paging.

## [0.5.0] - 2026-09-20

### Added
- Battery saves (SRAM) kept in step, touch controls, and continuing a game
  where it was left.

### Fixed
- The Content Security Policy the emulator needs, and the blob URL errors it
  was causing.

## [0.4.0] - 2026-09-20

### Added
- No-Intro folder names are understood, and a saves folder of your own.

## [0.3.0] - 2026-09-20

### Added
- Zipped ROMs, thumbnails, and save state slots.

## [0.2.0] - 2026-09-20

### Added
- Save states, player controls, and playing from a public share.

## [0.1.0] - 2026-09-20

### Added
- Playing ROMs from the Files app, a games library page, settings, and a
  core for each system.

## [0.0.2] - 2026-09-19

### Fixed
- WebAssembly and `blob:` allowed in the app's Content Security Policy.

## [0.0.1] - 2025-03-07

### Added
- The first version.

[Unreleased]: https://github.com/robloach/nextcloud-arcade/compare/v0.39.5...HEAD
[0.39.5]: https://github.com/robloach/nextcloud-arcade/releases/tag/v0.39.5
[0.39.4]: https://github.com/robloach/nextcloud-arcade/releases/tag/v0.39.4
[0.39.3]: https://github.com/robloach/nextcloud-arcade/releases/tag/v0.39.3
[0.39.2]: https://github.com/robloach/nextcloud-arcade/releases/tag/v0.39.2
[0.39.1]: https://github.com/robloach/nextcloud-arcade/releases/tag/v0.39.1
[0.39.0]: https://github.com/robloach/nextcloud-arcade/releases/tag/v0.39.0
[0.38.0]: https://github.com/robloach/nextcloud-arcade/releases/tag/v0.38.0
[0.37.0]: https://github.com/robloach/nextcloud-arcade/releases/tag/v0.37.0
[0.36.1]: https://github.com/robloach/nextcloud-arcade/releases/tag/v0.36.1
[0.36.0]: https://github.com/robloach/nextcloud-arcade/releases/tag/v0.36.0
[0.35.0]: https://github.com/robloach/nextcloud-arcade/releases/tag/v0.35.0
[0.34.0]: https://github.com/robloach/nextcloud-arcade/releases/tag/v0.34.0
