# Settings

For the administrator, and for anyone wondering what a setting does. What
the settings change in play is in [the usage guide](usage.md).

There is no Save button anywhere: every setting, personal or
administrative, is saved the moment it is changed. Folder settings have a
browse button that opens the Nextcloud file picker.

## Personal settings

Personal settings → Arcade:

| Setting | Default | Description |
| --- | --- | --- |
| Smooth video filtering | Off | Bilinear filtering instead of sharp pixels |
| Capture input globally | On | Send gamepad and keyboard input to the game while playing |
| Pixel-perfect scaling | Off | Scale by whole pixels, with borders |
| Rewind | Off | Go back through the game while the rewind key is held; costs some performance |
| Pause in the background | Off | Stop the game while its tab is hidden |
| Continue on start | Off | Load the latest save as a game starts, without asking |
| Save every | Never | Write the Auto save state while playing, from 30 seconds to 10 minutes |
| Run-ahead | Off | Hide input lag, up to 3 frames, at the cost of CPU |
| Fast-forward speed | 3× | Speed of the fast-forward button, 1× to 5× |
| Volume | 100% | How loud the game plays, 0 to 100 |
| Audio latency | 64 ms | Raise it if the sound crackles |
| Controls | [see the usage guide](usage.md#the-keyboard) | The key of every button and of the player itself |
| Games library folder | `/Games` | Scanned for the games library page. Scan, beside it, scans it afresh now -- games copied into it outside Nextcloud included -- and says how many it holds |
| Thumbnails folder | empty | Images used as game thumbnails, and where fetched box art lands |
| Saves folder | empty | Save states and battery saves, in your own files, in RetroArch's layout. Without one, a game cannot be saved |
| System folder | empty | Where BIOS files are read from |
| Screenshots folder | empty | Where the screenshot button saves images; left empty, they are downloaded |

## Where saves are kept

With a saves folder set, saves are written to your own files, laid out
the way RetroArch keeps its own: battery saves under `saves/`, save
states and their screenshots under `states/`, each filed under the name
of the core that plays the game. So a `Mario.sfc` saves into

```
Saves/manifest.server
Saves/saves/Snes9x/Mario.srm
Saves/states/Snes9x/Mario.state1
Saves/states/Snes9x/Mario.state1.png
Saves/states/Snes9x/Mario.state.auto
```

with slots 0 to 2 as `.state`, `.state1` and `.state2`, numbered as
RetroArch numbers them, and the automatic one as
`.state.auto`. They sync to your devices like anything else. Without a
saves folder, a game cannot be saved at all, and the player says so.

Games played on the same core share its folder, so two games of the same
name on it — a `Tetris.gb` and a `Tetris.gbc` — share their saves, as they
do in RetroArch. Deleting one of them leaves the saves alone for as long
as the other is still there. A zip is filed under the core the player
found inside it, which is noted with the game, so the Files sidebar and
the cleanup find it there too; a game whose system cannot be told at all
saves straight into `saves/` and `states/`.

A zipped game's saves are named after the game inside the zip, as
RetroArch names them: `Super Street Fighter II.zip` holding
`Super Street Fighter II (USA).sfc` saves as
`saves/Snes9x/Super Street Fighter II (USA).srm`. Renaming the zip keeps
its saves.

A battery save the game did not change is not written again — it is read
out of the game once a minute, and every write would be a new version of
the file.

Changing the saves folder does not move existing saves.

### RetroArch Cloud Sync

The saves folder is what RetroArch's
[Cloud Sync](https://docs.libretro.com/guides/retroarch-cloud-sync/) syncs
with: every save and delete made in Arcade is recorded in
`manifest.server`, which is all RetroArch reads to know what changed. To
play the same games with the same saves in RetroArch:

1. Set a saves folder.
2. Create an app password for RetroArch under Personal settings →
   Security.
3. In RetroArch, open Settings → Saving → Cloud Sync, turn on Cloud Sync,
   and choose the `webdav` backend.
4. Set the Cloud Storage URL to the saves folder over WebDAV, with the
   trailing slash — the RetroArch Cloud Sync section of the personal settings shows yours:
   `https://cloud.example.com/remote.php/dav/files/USERNAME/Saves/`
5. Enter your user name and the app password, keep Sync Saves on, and
   restart RetroArch.

Saves copied into the folder some other way — the desktop client, an
upload in Files — are only in the manifest once something records them,
and RetroArch does not fetch them until then. That happens by itself:
opening a game does, at most once an hour, and the weekly background job
does for everybody. `occ arcade:cloudsync` does it from the command line.
Saves that are gone from the folder are recorded as deleted, so RetroArch
deletes its copy too. A file the manifest already has, and that has not
been touched since the manifest was written, is taken at its word;
`--full` hashes them all.

Keep RetroArch's "Sort Saves into Folders by Core Name" and "Sort Save
States into Folders by Core Name" on, as they are by default; saves it
already filed without them, straight in `saves/` or `states/`, are read
and written where they are. Arcade offers slots 0 to 2 and the automatic
slot, and lists any other slot RetroArch saved into — slot 5, or 990 — to
be loaded or deleted.

A save state belongs to the core that made it, at the version that made
it. The cores Arcade runs are RetroArch's own, built for the browser, so
a state usually loads on either side; one from a much older or newer
build of the core may not. Battery saves are plain files, and always
carry over.

## Administration settings

Administration settings → Arcade holds what is the same for everybody:

| Setting | Default | Description |
| --- | --- | --- |
| Folder defaults | as above | What new users start with, and each can still change. Scan, beside the library folder, scans every user's library afresh in the background |
| Look up box art | On | Whether the server may ask the libretro thumbnail server at all |
| Work out ROM checksums | Off | Whether to read a whole ROM to hash it, when the upload brought no checksum |
| Games listed at most | 5000 | How many games one library scan lists |
| Folders deep at most | 6 | How far into a library folder the scan goes |
| How long a scan is kept | 1 day | How long an unchanged scan may be reused, from 1 hour to 1 week |
| Core options | Core default | Options of the emulator cores, which users cannot change |
| Picture shown for each system | Box art | Box art, title screen, screenshot or logo, per system, for everybody |

At the bottom, **Maintenance** says how Arcade stands and offers what an
administrator would otherwise do with occ, one button each:

| Task | Does | occ |
| --- | --- | --- |
| Database | Makes whatever tables and columns of Arcade are missing, and touches nothing that is there | `occ arcade:repair` |
| ROM file types | Gives ROMs uploaded before Arcade was enabled their file type, so they play from the Files app | `occ maintenance:mimetype:update-db` |
| Saves of deleted games and users | Looks for them when asked, as that goes through every user's saves, then removes them; removing asks to be pressed twice | `occ arcade:cleanup` |
| Saves left by earlier versions | Removes what versions before 0.48.8 kept in the app data; asks to be pressed twice | `occ arcade:cleanup --legacy` |

Box art, checksums and the three scan limits are scalars of the instance,
so they live in a declarative settings form that the server renders and
saves itself; the rest of the page is the app's own markup.

An option of a core belongs to the core rather than to whoever is playing,
so those are the administrator's alone; left on "Core default", the core
decides. Looking up box art is the only thing in the app that has the
server itself fetch from the internet, which is why it can be turned off
for the instance: with it off, the button is gone from the personal
settings and the endpoint refuses.

The kind of picture a system is shown with is set here for everybody,
alongside the core options, rather than by each user. Box art, title
screen, screenshot and logo name the kind of picture a system is drawn
with; which file each comes out of is [box art
matching](usage.md#box-art).

## BIOS files

[Which files each system asks for](systems.md) is a short list, and the
ones that ask look for the file by the name their core expects. Point the
personal System folder at a folder holding them and they are handed to
the emulator as a game starts. The names are matched without regard to
case, so `SCPH1001.BIN` serves as `scph1001.bin`.

The administration page has a BIOS section that shows, for every system
that asks, which files are there and which are missing — seen the way the
administrator's own player would see them. A missing file can be uploaded
right there: it lands in the System folder, renamed to the spelling the
core asks for, and only names some core actually asks for are taken, so
the folder cannot become a place to keep files in general. The section
appears once a System folder is set in the personal settings; without one
there is nowhere to put anything, so it is not shown.

A BIOS is the one thing a player cannot make for themselves, so an
administrator can also put one where every player reaches it, instead of
every user finding their own copy:

```sh
occ arcade:bios                      # what is asked for, and what is held
occ arcade:bios /path/to/gb_bios.bin # offer this one to everybody
occ arcade:bios --remove gb_bios.bin # take it back
```

The same rule about names applies here. A player's own system folder
comes first; what it has not got is taken from the instance. The settings
page shows the store's files as the fallback they are, and cannot remove
them — the store belongs to `occ arcade:bios`.

## Background jobs

The app queues its slow work as background jobs, so nothing of it happens
while a page waits:

- Reading what a game says about itself — system, title, region,
  checksum — when a ROM is written, and for the whole library when it is
  rescanned.
- Fetching missing box art, when the button in the personal settings asks
  for it.
- Scanning every user's library, when the Scan button of the
  administration settings asks for it.
- Reading ROMs again after an upgrade that reads them better — the
  titles of Super Nintendo games, say — for the games read before.
- A weekly cleanup sweep, the same work as `occ arcade:cleanup` below,
  and putting every Cloud Sync manifest right, as `occ arcade:cloudsync`
  does.

A setup check on the administration overview says whether background jobs
run the way the app needs them: it warns when they are set to AJAX, have
never run, or have not run for an hour, and says when Arcade has work
waiting in the queue.

## occ commands

| Command | Does |
| --- | --- |
| `occ arcade:bios` | List, add or remove the BIOS files the instance offers to every player |
| `occ arcade:cleanup` | Remove save states of games and users that no longer exist, and what was played of them; `--legacy` also removes the saves versions before 0.48.8 left in the app data, which nothing reads; `--dry-run` only reports |
| `occ arcade:cloudsync` | Record in each user's Cloud Sync manifest the saves that got into the saves folder some other way, and the ones that are gone; `--user`, `--full` |
| `occ arcade:repair` | Make the database tables and columns of Arcade that are missing |
| `occ arcade:status` | Report the games with saves and the play activity of every user, and what earlier versions left in the app data; `--json` for machines |
| `occ arcade:uninstall` | Remove the settings, save states and mimetypes of the app, before removing the app itself; `--dry-run`, `--force` |

What the app stores, and why `occ arcade:uninstall` should run before
`occ app:remove`, is in [the development guide](development.md#what-the-app-stores).

## Performance

The emulator cores are WebAssembly files of a few megabytes that the
browser downloads and compiles on every launch. On Apache, the app ships
an `.htaccess` in `img/cores/` that sets the `application/wasm` mimetype
(streaming compilation), a week-long immutable `Cache-Control`, and
compression — no configuration needed.

On nginx, add the equivalent to the Nextcloud server block:

```nginx
location ~ ^/apps/arcade/img/cores/ {
    types { application/wasm wasm; }
    add_header Cache-Control "public, max-age=604800, immutable";
    gzip on;
    gzip_types application/wasm application/javascript;
}
```

A memory cache (Redis or APCu) makes the games library page faster, since
that is where the scanned library is cached. Without one, Arcade keeps
the scan in files of its app data instead: slower than memory, far
quicker than walking the library folder again on every request, and
never used once a memory cache is configured. The weekly background job
removes the cache files that expired.

The script the Files app loads carries no more than what it takes to
register the player with the file viewer; the emulator itself is fetched
the first time a game is opened. The core of a system is asked for as
soon as a game starts, rather than after the ROM has been read, so the
two downloads overlap.

### Content Security Policy

The app adds the allowances Nostalgist.js needs — WebAssembly compilation
(`wasm-unsafe-eval`) and `blob:` and `data:` sources for scripts, workers,
frames, connections, images and media. They go on every page a user who
may use the app could see, since the player also runs inside the Files
app, and on a public share page only when that share really holds a game;
every other page of the instance keeps its default policy. No changes to
the Nextcloud server are required.

## Troubleshooting

**ROMs from before the app open as plain files.** They kept their generic
mimetype. The repair step runs on install and on upgrades, or by hand:

```sh
occ maintenance:mimetype:update-db
```

Until then they still open from the Arcade page and the **Play with
Arcade** file menu entry, which go by the file extension.

**Games stay unrecognized.** The reading happens in background jobs, so
check the setup check above: an instance on AJAX background jobs, or one
whose cron has stalled, never reads anything.

**A zip shows no player.** By design: zips are only claimed inside the
games library, since a `.zip` outside it says nothing about what is
inside. See [the library guide](library.md#in-the-files-app).

**A deleted game and its saves.** A game deleted into the trash keeps its
save states and battery save, since it can be restored with the same file
id. They go when the trash lets go of it. The weekly cleanup sweep — or
`occ arcade:cleanup` — picks up whatever the event listeners could not
catch, such as a whole folder of games deleted in one go. It also forgets
how long a game that is gone for good was played; one in the trash keeps
that too.

**A table of Arcade is missing.** An upgrade that went wrong half way can
leave Arcade without a table or a column, and `occ arcade:status` says
which. Repair the database under Administration settings → Arcade →
Maintenance, or run `occ arcade:repair`: whatever is missing is made, and
nothing that is there is touched.
