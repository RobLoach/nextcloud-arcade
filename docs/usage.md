# Using Arcade

For the player: getting games in, getting pictures for them, and everything
inside the player. Browsing is in [the library guide](library.md), and every
setting named here is explained in [the settings guide](settings.md).

## First run

Open a ROM in the Files app and it plays in the file viewer; open the
Arcade page and pick one from your games library folder. Every folder
Arcade reads or writes is one you name yourself, and a game cannot be
saved until the saves folder among them is set — the player says so until
one is. [The personal settings](settings.md#personal-settings) list all of
them, with what each starts out as.

## Adding games

Upload ROMs into the library folder. Which extensions belong to which
system, how zips and `.bin` files are placed, and what folder names count
is all in [the systems reference](systems.md).

The app teaches Nextcloud a mimetype for every system it runs, so ROMs
uploaded while the app is enabled are filed correctly as they arrive. ROMs
that were already there keep their generic mimetype until the repair step
runs — see [Troubleshooting](settings.md#troubleshooting).

In the background, the app also reads what a cartridge says about itself.
Game Boy, Game Boy Color, Game Boy Advance, Super Nintendo, Mega Drive,
32X, Atari Lynx, Neo Geo Pocket and Virtual Boy headers carry the name the
console shows; Super Nintendo and Mega Drive carry the region the game was
sold in as well. That name is what box art is matched on when the file name
finds nothing, so a ROM called `rom1.gb` still gets the cover of Super
Mario Land.

## Box art

Thumbnails are matched by file name. With a thumbnails folder of `Thumbs`,
`Games/NES/Mario.nes` uses `Thumbs/NES/Mario.png` and falls back to
`Thumbs/Mario.png`. PNG, JPEG, WebP and GIF are supported.

Platform folders work too, both with the libretro-thumbnails
`Named_*` subfolders — so a pack from
[libretro-thumbnails](https://github.com/libretro-thumbnails) can be dropped
in unchanged — and with the images straight in the platform folder:

```
Thumbs/Nintendo - Nintendo Entertainment System/Named_Boxarts/Mario.png
Thumbs/Nintendo - Nintendo Entertainment System/Named_Titles/Mario.png
Thumbs/Nintendo - Nintendo Entertainment System/Named_Snaps/Mario.png
Thumbs/Nintendo - Nintendo Entertainment System/Named_Logos/Mario.png
Thumbs/Nintendo - Nintendo Entertainment System/Mario.png
```

Platform folders are matched by their No-Intro name as above, or by a short
name like `SNES`. The library picks the image that suits the size it is
drawing: box art in the grid, logos in the list and table, falling back to
title screens and screenshots.

A game with no image of its own shows itself instead: the most recent of
the screenshots taken of it and the screenshots of its save states. That
needs no configuration, and it keeps up as the game is played.

Matching is forgiving. An identical file name wins, and otherwise region and
revision tags, articles, punctuation and accents are ignored, so
`Batman Returns.zip` finds `Batman Returns (USA).png` and
`The Legend of Zelda.nes` finds `Legend of Zelda, The (USA) (Rev 1).png`.
Titles joined with "and", "+" or "&" match each other, so
`Super Mario All-Stars and Super Mario World (Europe).zip` finds
`Super Mario All-Stars + Super Mario World.png`. When several images fit,
the most widely released one is used — World before USA before Europe
before Japan. Names containing `&*/:` and friends match
the underscores libretro-thumbnails replaces them with.

### Fetching what is missing

The thumbnails folder setting has a button that goes looking for the box
art of the games that have none, downloading it from the libretro
thumbnail server into that folder. It runs as a background job, so it
carries on after the page is closed, says how it went when the page is
opened again, and leaves [a word under the bell](library.md#in-the-files-app)
when it is done. The button is only there while an administrator allows
[the lookup](settings.md#administration-settings) for the instance.

## Playing

### The keyboard

The keys work the controller, and a few reach for the player itself. All
of them can be changed in the personal settings, and a key that works a
button of the controller is left to the game.

| Button | Key |
| --- | --- |
| Up, Down, Left, Right | Arrow keys |
| A | X |
| B | Z |
| X | S |
| Y | A |
| L | Q |
| R | W |
| Select | Right Shift |
| Start | Enter |

| Action | Key |
| --- | --- |
| Pause and resume | Space |
| Fast-forward | T |
| Rewind (hold) | Backspace |
| Fullscreen | F |
| Save to slot 1 | F2 |
| Load slot 1 | F4 |
| Screenshot | P |
| Close the game | Escape |

Rewind does nothing until it is switched on in
[the personal settings](settings.md#personal-settings). Its key is watched
by RetroArch itself, which is why holding it rewinds and releasing it plays
on.

### The control bar

A control bar overlays the bottom of the player with pause/resume, a save
state menu, mute, fast-forward, screenshot, and fullscreen. On touch
devices it also toggles a virtual gamepad: an eight-way D-pad, so diagonals
work with one thumb, with A/B/X/Y, L/R, Start and Select.

A plugged-in controller works in the player through RetroArch, which reads
gamepads itself; its buttons can be remapped in the RetroArch menu.

Anything the player has to say — a slot saved, a state that would not
load, a BIOS the game went without, a complaint from the core itself —
appears as the same notification the rest of Nextcloud uses, at the top
right, in fullscreen as well. The buttons in that corner step aside while
it is up. A confirmation goes after a few seconds; a problem stays longer,
and can be dismissed.

### Save states

The save state menu has three slots per game, each with a screenshot
thumbnail; a filled slot is labeled with its date alone, since the
screenshot already says which game it is. Saving or loading a slot closes
the menu and returns to the game.

Above the slots sits the Auto slot, which the player writes itself, at an
interval while playing if one is set. When a game has save states, the
player offers to continue from the most recent one at launch — or loads it
straight away, if that is turned on. A slot made from a different dump of
the same game is marked stale, and the player warns before loading it.

States are per user and per game, so every NextCloud user has their own
saves, even for a shared ROM. A game is known by the id Nextcloud gave the
file, so renaming a ROM or moving it keeps its saves, its battery save and
how long it was played — the folder in the saves folder is brought along
to the new name. Where the files land is in
[the settings guide](settings.md#where-saves-are-kept).

### Battery saves

In-game battery saves (SRAM) are restored when a game starts, and uploaded
every minute and when the page closes, so progress saved through a game's
own save system survives. The save state menu can delete the battery save,
for starting a game over from nothing.

### Screenshots

The screenshot button saves an image of the game to your screenshots
folder, filed under the system — or downloads it, without one. Next to it,
a gallery button opens every screenshot taken of the game, newest first,
where they can be opened in the Files app or deleted. It appears once
there is something to show.

### Public share links

A game shared by link plays for anonymous visitors too. Save states and
battery saves are not available there, since there is no user to store
them for.

## The top-right menu

A top bar holds a close button and a three-dots actions menu:

- **Full screen**
- **RetroArch menu** — core options, control remapping and more
- **Restart**
- **Open sidebar** — or **Details**, where no sidebar can be had
- **Settings** — the personal Arcade settings, in a new tab
- **Download**

Inside the Files viewer, which brings chrome of its own, the RetroArch
menu and Restart stay as buttons of the control bar instead.

All of the chrome fades away over an idle game and comes back at a touch
of the mouse, a key or the screen; it stays while the game is paused or a
panel is open.
