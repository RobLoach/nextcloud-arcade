# Changelog

All notable changes to NextCloud Arcade. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the app uses
[semantic versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- RetroArch Cloud Sync. The saves folder is laid out the way RetroArch
  keeps its own -- `saves/` and `states/`, filed under the name of the
  core, with RetroArch's file names -- and every save and delete is
  recorded in the `manifest.server` its Cloud Sync reads. Point RetroArch
  at the folder over WebDAV and a game saved there carries on in Arcade,
  and the other way around. The personal settings say how, with each
  player's own address.
- Any slot RetroArch saved into is listed in the player, and can be
  loaded and deleted there.
- A Maintenance section at the bottom of the administration settings:
  repairing the database, giving ROMs their file types, removing the
  saves of deleted games and users and those earlier versions left in the
  app data -- each saying how things stand before it is pressed.
- `occ arcade:repair`, which makes the database tables and columns of
  Arcade that are missing.
- A Scan button beside the games library folder: in the personal settings
  it scans your library afresh and says how many games it found; in the
  administration settings it scans every user's library in the
  background.
- Opening a game (at most once an hour), the weekly background job, and
  `occ arcade:cloudsync` record in the manifest the saves that got into
  the saves folder some other way — the desktop client, an upload in
  Files — so RetroArch fetches them, and the ones that are gone as
  deleted.
- A progress bar while a game downloads, and then a word that the
  emulator is starting.
- Save states load and save at once. The states of a game are fetched
  as it starts and held for the session; a save is held at once and sent
  to the server in the background, and closing the game waits for it.
  A slot written meanwhile by RetroArch or another tab is fetched anew.
- A battery save the game did not change is no longer sent and written
  again every minute, which made a new version of the file each time.
- A save state the emulator turns down says so in plain words, instead of
  a success message followed by RetroArch's own log line.
- `occ arcade:cleanup --legacy` removes the saves earlier versions left in
  the app data, and `occ arcade:status` says how many there are.
- "Least played", alongside "Most played", for the games you have started
  and not come back to. The ones never started stay behind them either
  way, so it is not a list of everything untouched.
- An empty library now says what to do about it: where the app is looking,
  what it found there, and a way to point it somewhere else — instead of
  an empty page.
- The grid and the list can be sorted. The table had always sorted by its
  own column headings, and the server had always taken a sort and an
  order, but the other two views could only ever ask for name, ascending.
- A hotkey can be left on no key at all, and a whole set of keys can be
  put back to its defaults.
- Mute, in the player's control bar, drawn as whichever of the two it
  would do next.
- The Maintenance section and `occ arcade:status` say how each of
  Arcade's background jobs last went -- scanning every library, the
  weekly cleanup, fetching thumbnails, reading game details -- and when,
  so one that failed shows there rather than only in the log.
- The Arcade tab of the sidebar -- in the Files app, and opened from the
  library -- shows more of a game: the emulator that runs it, the BIOS
  files its system asks for and whether they are found, a picture of
  each save state with a Load button that starts the game in it, and the
  latest screenshots. Opened from the library, it links to the game in
  Files. A game the background pass has not read yet is read there and
  then, so its title and region show straight away.
- Zipped games get the Arcade tab too: a zip, .bin or .rom in the games
  library, or in a folder that names a system. A zip played before says
  its system and emulator from the core that ran it, and which game in
  it the saves are named after.
- Closing a game shows a box like the loading one, saying what it is
  doing -- keeping the in-game save, sending the last save states,
  stopping the emulator -- rather than a frozen game until the library
  appears.
- The Arcade tab shows the game's pictures from the thumbnails folder --
  box art, title screen, snap and logo -- under Thumbnails, below the
  screenshots. The BIOS files come last.
- Screenshots and thumbnails picked in the sidebar on the library page
  open in the Viewer, over the library.
- A Details button on every game of the library -- in the corner of a
  card, at the end of a row of the list and the table -- opening the
  Files sidebar on it, with its tags, shares, versions and Arcade tab,
  without leaving the library. Nextcloud 34 can only show that sidebar in
  the Files app, so there the button opens the game's details in Files.

### Changed
- The offer to continue from the last save sits along the bottom of the
  game and goes by itself after seven seconds, waiting while the pointer
  or the focus is on it.
- Nextcloud 34.0.4 or newer is required.
- Save states, battery saves, their pictures and BIOS files are streamed
  to the player straight from storage, rather than read whole into the
  server's memory first.
- Looking for box art and screenshots asks the database for the images of
  a folder only, rather than listing everything in it.
- The queries of the app's own tables say which columns they read, so
  static analysis catches a misspelled one before it reaches a database.
- The background jobs that go through every user -- scanning every
  library, the weekly cleanup and Cloud Sync check -- let go of each
  user's files once done with them, so on a large instance they no longer
  hold everybody's mounts at once and run out of memory partway.
- ROMs take the file types the shared freedesktop.org database gives
  them: Super Nintendo games are `application/vnd.nintendo.snes.rom` and
  Atari Lynx games `application/x-atari-lynx-rom`, and Neo Geo Pocket
  Color (`.ngc`) and WonderSwan Color (`.wsc`) games have types of their
  own. Files are given the new types at upgrade, and the names earlier
  versions gave are still taken until then.
- The upgrade no longer converts the save states earlier versions kept in
  the app data: nothing reads them any more. The maintenance section
  removes them.
- `occ arcade:status` reports the games with saves and the plays of each
  user; the files and sizes it used to list were those of the app data,
  where saves no longer live.
- Psalm 6, which runs on PHP 8.5.
- Saves are no longer read from the old `Saves/<System>/<Game>/` layout,
  nor from the app data of versions before saves folders: save states
  and battery saves start afresh under the new one. The automatic slot is
  RetroArch's `.state.auto`, and slots 0 to 2 its `.state` to `.state2`,
  numbered from 0 as RetroArch numbers them; the save and load hotkeys
  use slot 0, as RetroArch's quick save does.
- Which picture a system is shown with is now set once for the instance,
  in the administration settings, where it sat alongside a personal copy
  of the same choice. A value an earlier version stored for a user is no
  longer read back, so everybody follows the instance.
- The Master System and the Game Gear now run on gearsystem, which is free
  to pass on, where they ran on the Mega Drive's core, which is not.
- The shelves above the library are "Continue playing" and "Recently
  added", and "Largest first" is no longer offered as a sort — the table
  still sorts by its own Size column.
- The player's chrome is along the top. It used to run along the bottom,
  which on a phone is where the thumbs and the virtual gamepad are: the
  bar sat over START and SELECT and took their taps with it. Given the
  width, it shares the top line with the buttons in the corner; narrower
  than that, the two stack.
- Screenshot moved into the player's actions menu.
- The virtual gamepad's shoulder buttons moved from the top corners to
  just above the D-pad and the face buttons, so the chrome keeps the top
  and the pad keeps the bottom. On the narrowest phones the pad is drawn
  a size smaller, where its two halves used to meet in the middle.
- The volume is set as a percentage rather than in decibels.
- The NES is called the NES wherever it is drawn. The folder its saves
  live in is unchanged, so nothing saved under the old name is lost.
- Shelf cards in the library are drawn as a row to glance along rather
  than as stretched grid cards.
- How long a scan is kept is picked from a few lengths instead of typed
  in as a number of seconds: a scan is dropped as soon as anything in the
  games folder changes, so the exact figure never mattered.
- In the admin settings the core options are listed by the systems they
  run rather than by the name of the core, and each row says how many of
  its options have been moved off the core's default.
- The admin settings put the folders first, size their headings, and say
  what each limit is currently set to.

### Fixed
- A save brought into the saves folder some other way -- the desktop
  client, Files -- could be passed over by the Cloud Sync check for good,
  whenever a save in the player had written the manifest since. The check
  now goes by when it last ran.
- A save made just before its slot was deleted, when the delete then
  failed, stayed only in the browser and was lost with the session. It is
  sent after all.
- The in-game save could stop being kept for the rest of a session after
  two looks found none -- a game paused or out of view is not a game
  without one -- and was not sent on closing either. The look on closing
  is now always taken.
- The Arcade tab read a ROM that has no title to give again every time it
  was opened.
- States fetched ahead from the Save states panel kept downloading after
  the game was closed.
- The emulator core was downloaded twice where the browser would not keep
  it: once for the progress bar, once more to start it.
- Titles read in the background reach the library at once, rather than
  when its scan next runs -- also on servers whose cache is APCu.
- The Sharing tab of the sidebar opened from the library stayed blank on
  Nextcloud 35: away from the Files app the sidebar has no folder or view
  to hand its tabs, and the Sharing tab cannot draw without them. The
  sidebar is now given the game's folder and a view of its own.
- Super Nintendo games never got their title or region: the header sits
  32 KiB into the file, and the read stopped at the 8 KiB a Nextcloud
  file stream hands over at a time. The upgrade has every SNES game
  without a title read again, once, in the background.
- "Play with Arcade" now appears in the file menu of the Files app. It was
  added where @nextcloud/files 3 keeps actions, and the Files app of
  Nextcloud 34 and 35 reads them where version 4 does, so it never showed.
- Scan finds games copied into the library folder outside Nextcloud -- a
  network share, rsync, a drive -- by having Nextcloud look at the folder
  on disk first.
- Putting the Cloud Sync manifest right no longer records a save as
  deleted -- which RetroArch then deletes too -- because it lay deeper
  than the walk went, was a dot-file, or sat on a storage that cannot
  hash; and it notices a save copied in with an older time.
- A game renamed and first opened from the Files sidebar keeps its saves;
  a zipped game deleted once its file was gone has its saves removed from
  the right folder; and deleting a game keeps a save in the shared flat
  folders that another game of the same name uses.
- The maintenance section looks for the saves of deleted games and users
  only when asked, rather than on every visit of the page.
- Nextcloud 35 replaced the Files sidebar, and with it the two ways the
  app used it: "Open sidebar" in the player failed with "Sidebar.open is
  not a function", and the Arcade tab of the sidebar never appeared. Both
  now use the new sidebar, and still the old one on Nextcloud 34.
- The saves of a zipped game are named after the game inside the zip, as
  RetroArch names them, so the two find each other's saves. They were
  named after the zip, and RetroArch's were never found.
- Saves reach the server behind a web application firewall. They were
  sent as a bare application/octet-stream body, which ModSecurity's
  OWASP Core Rule Set refuses as a body type it does not allow, so every
  save was turned away before Nextcloud saw it; they now travel as the
  file of a form, as every browser upload does. The BIOS upload too.
- A folder emptied in the personal settings stays empty. The id kept to
  follow a folder that moves won on every read, so an emptied Saves
  folder came straight back.
- Without a Saves folder the save button is there but switched off, and
  says where to choose one, rather than being missing.
- An instance with no memory cache no longer rescans the games library on
  every request: the scan is kept in files of the app data instead, until
  a memory cache is configured. The administration overview says so as a
  note rather than a warning.
- A save that cannot reach the server says why: the status the server
  answered with, or that it did not answer. When the reason is a table
  of Arcade an upgrade left out, the player says the database needs
  repairing and where an administrator does it, as soon as the game
  opens -- the saves it could not fetch used to go unmentioned.
- Saving a game whose name has a quote or brackets in it, like "Kirby's
  Adventure (USA)", no longer fails behind a web application firewall.
  The name went into the address of every request about the game, where
  a firewall takes a quote and brackets for SQL and refuses the request
  before Nextcloud sees it. The player now sends the name encoded, as
  letters and digits only; the old form is still understood.
- `occ arcade:status` says which table is missing and how to repair it,
  instead of failing with the database's own error.
- The cleanup now finds the app-data folders of users who no longer
  exist. Nextcloud's app data lists only the files of a folder, never the
  folders in it, so the folders it was looking for were never there.
- A save state no longer fails with "The game did not hand over a save
  state" in a game without a battery save. Reading the battery save waited
  a minute for a file such a game never writes, once a minute, and a save
  state asked for meanwhile gave up waiting behind it. It now looks for
  two seconds, and stops looking once the game is seen to keep none.
- What the emulator has to say for itself now reaches the player.
  RetroArch prints nothing unless told to, and it never was, so the
  messages meant to pass its errors on never had any to pass. It is now
  told to print its errors, and the two the browser build prints on
  every launch are left out.
- What the app gives up on is now written to the log. Six places caught
  every possible failure and carried on without a word — among them the
  one that hid two bugs fixed just before this, where a single unreadable
  file cost a whole chunk of the library its titles, regions and systems
  at once and looked exactly like a library nobody had indexed. The
  library still lists, the sidebar still draws and the saves are still
  kept where they are; the difference is that the log now says what was
  lost and why. The places where a miss is an ordinary answer — a path
  that leads nowhere, an expired share token — are left quiet on purpose.
- The emulator cores now carry their own licences. `img/cores/` held a
  single GPL-3.0 file that named no core and was not the licence of
  several of them; snes9x and genesis_plus_gx each require their notice to
  accompany any redistribution, and neither notice was anywhere in the
  tree. Every core's licence text now sits in `img/cores/LICENSES/` with
  `img/cores/README.md` saying which applies to which, and `npm run cores`
  refuses to finish if a core is shipped that nothing accounts for.
- The background reading of ROM headers could stop for a whole library
  and stay stopped. Working out which games had never been looked at
  asked each one for its system, and a ROM carrying metadata that no part
  of this app wrote — anything on the instance may file something
  against a file — answered that with an error rather than an empty
  string. One such file was enough to end the search, so no game was ever
  handed over to be read.
- Nothing read of a ROM reached the library if any one game in it had a
  header giving a system and no title — a `.bin` that names its machine
  and not itself, which is the ordinary kind. Reading the title of such a
  file raised an error that carried off the whole lookup, so every game
  in the library lost its title, its region and its system together, and
  the `.bin` files fell back to being placed by the folder they sat in,
  or not at all.
- A game the library listed could be one the player then refused to open.
  The browser read fewer cartridge marks than the server did, missing the
  Super Nintendo and the Neo Geo Pocket, so a headerless dump of either
  was listed with its system and then turned away as an unsupported ROM.
- A screenshot went nowhere the first time one was taken, when the folder
  it belongs in had never been made: only one level was created at a
  time, so a folder inside a folder that did not exist failed — and the
  message saying so could not be shown either, which is why it looked
  like nothing happened at all.
- Messages from the player are shown inside the player again, so they can
  be read in fullscreen. This server resolves where to put a message with
  a CSS selector, where the one before took the element itself, and the
  mismatch threw rather than degraded.
- The pictures beside each save state are kept by the browser instead of
  being fetched again every time the panel opens.
- An upload carrying a save is given five minutes rather than two, so a
  large save state on a slow line is no longer cut off — an upload cut
  off is a save that was asked for and not made. Everything else is held
  to a minute.
- Battery saves larger than 64 KiB never reached the server. Every upload
  asked to outlive the page, which the Fetch standard refuses above that
  size, so the request failed before it was sent and said so only in the
  browser console — on the timer as much as at the end. A Game Boy
  Advance flash save is 128 KiB, so for those games in-game saving never
  persisted at all.
- A save state or battery save cut short by a client that went away
  mid-upload is refused rather than written over the save that worked.
- A core that stops answering no longer takes the save states panel with
  it. Saving and loading are given a deadline, and so is every request, so
  an operation that cannot finish ends and says so instead of leaving every
  slot shut for the rest of the session. The one emulator now answers one
  call at a time as well: the panel, the screenshot button and the battery
  save each had a gate of their own and none could see the others.
- Pressing Save or Load on one slot while another was working did
  nothing, silently. Every slot is now held while an operation runs.
- Cards in the Favorites and Continue Playing shelves were drawn around
  220px tall whatever they held: a card scrolled off the end of a shelf
  is skipped by the browser and drawn at a fallback height, and the row
  stretched every card beside it to match.

### Removed
- The Sega 32X, whose core could not be passed on freely either.
- The "Save the game automatically when closing the player" setting, and
  the save it made. Closing wrote the same Auto slot the clock writes, and
  the core allows one state operation at a time, so the two raced for it;
  whichever lost came back as "Could not save the game", a failure that
  had not happened and that never reached the server to be looked up. A
  core that never finished answering held the door shut the same way.
  Keeping a game is now the player's to do, through the Save states panel
  or the clock in the settings, and Close just closes. The game's own
  battery save is still flushed on the way out.

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

[Unreleased]: https://github.com/robloach/nextcloud-arcade/compare/v0.43.2...HEAD
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
