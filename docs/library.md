# The games library

For the player browsing: the Arcade page, and what the app adds to the
Files app. Playing itself is in [the usage guide](usage.md).

## Views

The page lists the games of your library folder. Three views are
available and the choice is remembered:

| View | Shows |
| --- | --- |
| Grid | Large thumbnails, the default |
| List | Compact rows with small thumbnails |
| Table | Sortable columns: name, system, size, modified, played |

The table sorts by how often and how long each game was played, so the
most played game of the library is one click away.

## Search and filters

Games can be filtered by name, by system and by tag — the collaborative
tags of the Files app, so tagging a ROM there filters it here. Large
libraries are paged, 24 to 240 games per page, and filtering, sorting and
paging all happen over the whole library, not just the page being shown.
Pages are kept for the tab, so switching views, paging back and returning
from a game are drawn from what was already loaded and revalidated in the
background.

The scan of the library folder is cached and keyed on the folder's ETag,
so it is only walked again when something in it changes. The refresh
button in the header forces a rescan, and also queues a background job
that reads the games nothing has been read of yet, a chunk at a time,
until there is nothing left to ask. How many games are listed and how
deep the folder is walked is up to
[the administrator](settings.md#administration-settings).

## Favorites and recently played

Favorites and the games played last are shown in rows above the library,
so picking up where you left off is one click, whether the game was
started here or from the Files app. The star on a game card is the same
star as the one in the Files app: starring a game here shows it in the
Files favorites, and a ROM starred in Files is a favorite here. Because
Files keeps it by file id, a game stays a favorite when it is renamed or
moved. The favorites row shows the games of your library folder; a ROM
starred somewhere else is still starred, it just has no place in the
library to be shown in.

## Browsing with a gamepad

The library can be browsed with a controller in hand, so nobody on the
couch has to reach for the mouse. Plugging a pad in is the whole setup.

| Control | Does |
| --- | --- |
| D-pad or left stick | Move between games |
| A | Launch the game in focus |
| B | Back out to the search field |
| L1 / R1 | Turn the pages |

## The first run

The first time the page opens with no library to show — the folder
missing, or empty — the app looks through your files for folders that
already hold ROMs and offers them, each with a button that makes it the
library folder. There is also a link to pick a folder yourself in the
personal settings. Upload some games and rescan if it finds nothing.

## In the Files app

- A ROM opens in the file viewer and starts playing, since the app
  registers the player for every mimetype it teaches Nextcloud.
- The file menu has a **Play with Arcade** entry for everything the
  viewer cannot take: a zipped ROM, whose `.zip` says nothing about what
  is inside, and a ROM whose mimetype Nextcloud has not learned yet. It
  opens the game on the Arcade page — by file id, so the link survives
  renames and moves.
- Zips are only offered inside the games library; outside of it, an
  archive is left to Nextcloud as if the app were not installed.
- The Files sidebar has an **Arcade** tab for ROMs: the system, what the
  cartridge calls itself, how long the game was played, the saves waiting
  in its slots, and a button that plays it.
- Games are given their box art as their Nextcloud preview, so a folder
  of ROMs looks like a shelf of games. The picture is the one already in
  your thumbnails folder, only scaled; a game without one keeps the icon
  of its mimetype.
- What you play lands in your own Activity stream: that a game was
  played, for how long, and when a slot was saved.
- A notification appears under the bell when
  [a box art run](usage.md#fetching-what-is-missing) finishes.
