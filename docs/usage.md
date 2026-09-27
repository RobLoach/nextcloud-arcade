# Using Arcade

How a game is found, opened and played, from the Files app and from the Arcade page.

## From the Files app

Open a ROM (for example a `.nes` file) and it starts playing in the file
viewer, which is what the app registers itself with. ROMs are recognized by
their mimetype, which the app teaches Nextcloud for every system it runs.

The file menu has a **Play with Arcade** entry as well, for everything the
viewer cannot take: a zipped ROM, whose `.zip` says nothing about what is
inside, and a ROM whose mimetype Nextcloud has not learned yet. It goes by
the file extension and by the folder the game is stored in, the way the
Arcade page does, and opens the game there — by file id, so the link
survives renames and moves. Zips are only offered inside the games
library; outside of it, an archive is left to Nextcloud as if the app
were not installed.

The Files sidebar has an **Arcade** tab for ROMs: the system, what the
cartridge calls itself, how long the game was played, the saves waiting
in its slots, and a button that plays it.

Some names say nothing about which machine they are for. A `.bin` is a Mega
Drive game, a 32X game, a ColecoVision game, a track of a disc or firmware,
so no system can claim the extension the way `.sfc` is claimed. Extensions
Nextcloud already maps to something of its own are not taken over either:
`.md` is Markdown before it is a Mega Drive dump, and only counts as a game
when its mimetype or its folder says so. Those files
are placed by the first of these that answers: the folder they are in, and
then the file itself, whose first bytes on a cartridge dump carry a mark
saying whose it is. Where the two disagree -- a Mega Drive dump sitting in
an `SNES` folder -- the mark wins, since the folder was only ever a guess — `SEGA` for a Mega Drive game, `SEGA 32X` for a 32X one,
`NES` for a Nintendo one, and so on. The reading happens in the background,
so a `.bin` may be listed without a system for a moment and settle on one
afterwards. The same goes for what is inside a zip.

The system of a zipped game is detected from the file inside the archive, or
from the folder it is stored in. Short names, spelled out names and No-Intro
platform names all work, with or without the maker in front and with a word
like "ROMs" hung off the end, so `Games/SNES/NHL 96.zip`,
`Games/Super Nintendo Games/NHL 96.zip` and
`Games/Nintendo - Super Nintendo Entertainment System/NHL 96.zip` are all
recognized as Super Nintendo.

ROMs uploaded before the app was enabled keep their generic mimetype until
the mimetype repair step runs, which happens on install and on upgrades. It
can also be run manually:

```sh
occ maintenance:repair
occ maintenance:mimetype:update-db
```

Until then they open from the Arcade page, which goes by the file
extension.

ROMs uploaded while the app is enabled are filed correctly as they arrive.
That needs the app to be loaded during the upload itself, and `remote.php`,
which every upload goes through, loads only apps that declare themselves a
`filesystem` app — so Arcade declares it. Nextcloud does not let apps of that
type be enabled for selected groups, so Arcade is enabled for everybody on
the instance or for nobody.

Cartridges carry the name the console shows, and the app reads it: Game Boy,
Game Boy Advance, Super Nintendo and Mega Drive headers all say what the game
is called, and Super Nintendo and Mega Drive say which region it was sold in.
That name is what box art is matched on when the file name finds nothing, so
a ROM called `rom1.gb` still gets the cover of Super Mario Land. It is read
once, in the background, and filed against the file by Nextcloud, along with
the MD5 of the ROM, if the upload brought one: desktop clients send a
checksum, browsers do not. Working one out instead means reading the whole
file, so an administrator has to ask for that. All four are searchable, and
a ROM with nothing to read — an NES cartridge carries no title, and without
checksums there is nothing else to look for — is never opened at all.

Nextcloud reads a file's metadata when the file is written, so ROMs that were
already there when the app arrived have never been asked. The rescan button
of the games library asks for them: it queues a background job that walks the
library fifty games at a time, queuing the reading of each file behind it, and
comes back for the rest until there is nothing left to ask. Nothing of it
happens while the page waits.

Games are given their box art as their Nextcloud preview, so a folder of
ROMs looks like a shelf of games in the Files app. The picture is the one
already in your thumbnails folder, only scaled; a game without one keeps the
icon of its mimetype.

## From the Arcade page

The app's own page lists the games in your library folder (`/Games` by
default). Three views are available and the choice is remembered:

| View | Shows |
| --- | --- |
| Grid | Large thumbnails, the default |
| List | Compact rows with small thumbnails |
| Table | Sortable columns: name, system, size, modified |

Favorites and the games played last are shown in rows above the library, so
picking up where you left off is one click, whether the game was started
here or from the Files app. The star on a game card is the same star as the
one in the Files app: starring a game here shows it in the Files favorites,
and a ROM starred in Files is a favorite here. Because Files keeps it by
file id, a game stays a favorite when it is renamed or moved. The favorites
row shows the games of your library folder; a ROM starred somewhere else is
still starred, it just has no place in the library to be shown in. How long
each game was played is kept by the app, alongside.

Games can be filtered by name, by system and by tag, and large libraries
are paged (24 to 240 games per page). Filtering, sorting and paging all
happen over the whole library, not just the page being shown. Pages are
kept for the tab, so switching views, paging back and returning from a
game are drawn from what was already loaded and revalidated in the
background.

How many times and how long each game was played is shown in the table
view, and the table sorts by either, so the most played game of the
library is one click away.

The library can be browsed with a gamepad, so nobody with a controller
in hand has to reach for the mouse: the d-pad or the left stick moves
between games, **A** launches the one in focus, **B** backs out to the
search field, and **L1**/**R1** turn the pages. Plugging a pad in is the
whole setup.

The first time the page opens with no library to show — the folder
missing, or empty — the app looks through your files for folders that
already hold ROMs and offers them, each with a button that makes it the
library folder. Upload some games and rescan if it finds nothing.

The scan of the library folder is cached and keyed on the folder's ETag, so
it is only walked again when something in it changes; the refresh button in
the header forces a rescan. Up to 5000 games and six folder levels deep are
listed.

## Player controls

The keyboard works the controller: the arrow keys, X and Z for A and B, S
and A for X and Y, Q and W for the shoulders, Enter for start and the right
shift for select. Space pauses, T fast-forwards, F goes fullscreen, O opens
the save states, P takes a screenshot, Backspace rewinds while held — once
rewind is turned on in the settings — and Escape closes whatever panel is
open. All of them can be changed in the personal settings, and a key that
works a button of the controller is left to the game.

A control bar overlays the bottom of the player with pause/resume, a save
state menu, mute, fast-forward, screenshot, and fullscreen. On touch
devices a virtual gamepad is overlaid too — a D-pad with diagonals,
A/B/X/Y, L/R, Start and Select — toggleable from the control bar.

A top bar sits in the other corner, with a close button and a three-dots
actions menu: **Full screen**, the **RetroArch menu** (core options,
control remapping and more), **Restart**, **Open sidebar** — or
**Details**, where no sidebar can be had — and **Download**. Inside the
Files viewer, which brings chrome of its own, the RetroArch menu and
Restart stay as buttons of the control bar instead. All of the chrome
fades away over an idle game and comes back at a touch of the mouse, a
key or the screen.

Next to the screenshot button, a gallery button opens every screenshot taken
of the game, newest first, where they can be opened in the Files app or
deleted. It appears once there is something to show.

The save state menu has three slots per game, each with a screenshot
thumbnail; a filled slot is labeled with its date alone, since the
screenshot already says which game it is. Saving or loading a slot closes
the menu and returns to the game. Above them sits the Auto slot, which the player writes
itself: when the player is closed, and at an interval while playing if one
is set. A game can always be picked up where it was left that way, and it
is the state the player offers to continue from next time — or loads
straight away, if that is turned on in the settings.

States are stored per user and per game on the server, so every NextCloud
user has their own saves, even for a shared ROM. A game is known by the id
Nextcloud gave the file, so renaming a ROM or moving it to another folder
keeps its saves, its battery save and how long it was played — the folder in
the saves folder is brought along to the new name.

In-game battery saves (SRAM) are restored when a game starts, and uploaded
every minute and when the page closes, so progress saved through a game's own
save system survives. The save state menu can delete the battery save,
for starting a game over from nothing. When a game has save states, the
player offers to continue from the most recent one at launch.

Save states and SRAM are not available on public share links, since there is
no user to store them for.
