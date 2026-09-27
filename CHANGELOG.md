# Changelog

All notable changes to NextCloud Arcade. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the app uses
[semantic versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- The `occ arcade:cleanup` sweep also runs by itself once a week, as a
  background job: trash expiry by cron or `occ trashbin:cleanup` gives
  the app no signal to act on, so what those leave behind is now swept
  up without anyone running the command.

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
