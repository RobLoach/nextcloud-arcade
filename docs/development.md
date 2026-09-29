# Development

For whoever changes the code: building the app, what lives where, and
what it stores.

The rest of `docs/` is for people who use the app: [usage.md](usage.md)
is the player's guide, [library.md](library.md) covers browsing and the
Files integration, [settings.md](settings.md) is the administrator's
guide, and [systems.md](systems.md) is the reference of systems, cores,
extensions and BIOS files.

```sh
npm install
npm run build     # production bundles into js/
npm run watch     # rebuild on change
npm run cores     # extract the cores from the submodule
npm run l10n:extract  # collect the strings to translate

composer install
composer test     # the unit test suite
composer psalm    # static analysis
```

Every push and pull request runs the same through GitHub Actions: PHP
linting on 8.3 and 8.4, the test suite, static analysis, and a check that
`appinfo/info.xml` validates against the app store schema and agrees with
`package.json` on the version. The heavy jobs — the JavaScript build and
the server smoke test — run only on alpha, beta and rc tags, since the
committed bundles change rarely and a container install takes minutes.

`build/smoke-test.sh` is that smoke test, and it runs locally too: it
starts a real Nextcloud container (or uses one it is given), installs the
app, seeds ROMs, walks the endpoints a browser would, and does it all
again through an app upgrade. Unit tests and Psalm only see the app's own
code; the bugs this catches live in the server it runs inside.

The built bundles in `js/` are committed, so rebuild and commit them along
with any change to `src/` — and bump the patch version whenever the
bundles change, so browser caches roll instead of serving stale scripts.

## Project structure

```
appinfo/info.xml            App metadata, repair steps, settings registration
lib/CoreMap.php             Systems: extensions, mimetypes, cores, folder aliases
lib/CoreOptions.php         The core options offered in the settings
lib/AppInfo/Application.php Mimetype registration and event listeners
lib/Controller/             Page, library, settings and save state endpoints
lib/Listener/               Files and Viewer script loading, Content Security Policy
lib/Preview/                Box art as the Nextcloud preview of a ROM
lib/Migration/              Repair steps: mimetypes on install, caches on disable
lib/Command/                The occ commands: cleanup, uninstall, bios, status
lib/Activity/               What was played and saved, for the Activity stream
lib/Notification/           The word under the bell when a box art run is done
lib/BackgroundJob/          Looking for box art and reading ROMs, away from the browser
lib/Controls.php            What the keyboard does, and what it does by default
lib/RomHeader.php           The name a cartridge gives itself
build/translationtool.phar  Collects the strings to translate
build/smoke-test.sh         The app inside a real Nextcloud container
l10n/                       Translations, as Nextcloud reads them
lib/Service/                Settings, library, save states, thumbnails, history
lib/Settings/               Personal settings section
src/main.js                 The app page: player or games library
src/library.js              Games library views and pagination
src/librarypad.js           Browsing the library with a gamepad
src/viewer.js               The Viewer handler, loaded on every Files page
src/fileaction.js           The "Play with Arcade" entry in the file menu
src/files.js                The Arcade tab of the Files sidebar
src/session.js              Everything a running game needs, loaded on demand
src/player.js               Launcher, ROM fetching, zip extraction, SRAM
src/toolbar.js              Player control bar
src/panels/                 Save states, screenshots and resume panels
src/api.js                  Save state endpoints shared by the panels
src/icons.js                The icons of the player
src/touch.js                Virtual gamepad
src/settings.js             Personal settings page
src/systems.js              System lookup shared by the frontend
src/keys.js                 Keys, as the browser and RetroArch each name them
tests/unit/                 Unit tests of the logic that has no dependencies
templates/                  App page and settings markup
css/player.css              The player overlay, also loaded inside Files
img/cores/                  Emulator cores
```

## HTTP endpoints

All of them are user-scoped and require a session.

| Method | Route | Purpose |
| --- | --- | --- |
| GET | `/apps/arcade/` | The app page; `?fileId=` plays a game, `?file=` still works for old bookmarks |
| GET | `/apps/arcade/arcade/library` | Games: `offset`, `limit`, `sort`, `order`, `search`, `system`, `tag`, `refresh` |
| GET/POST | `/apps/arcade/arcade/settings` | Personal settings |
| POST | `/apps/arcade/arcade/settings/admin` | The instance settings the admin page keeps |
| GET | `/apps/arcade/arcade/game` | What the app knows about one ROM, for the sidebar tab |
| GET | `/apps/arcade/arcade/suggest` | Folders that already hold ROMs, for the first run |
| GET | `/apps/arcade/arcade/states` | Save state slots of a game |
| GET/POST/DELETE | `/apps/arcade/arcade/state` | A save state slot |
| GET/POST | `/apps/arcade/arcade/state/thumbnail` | The screenshot of a slot |
| GET/POST/DELETE | `/apps/arcade/arcade/sram` | The in-game battery save |
| POST | `/apps/arcade/arcade/recent` | Remember a game as played, and for how long |
| POST | `/apps/arcade/arcade/favorite` | Make a game a favorite, or stop |
| GET/POST | `/apps/arcade/arcade/thumbnails/fetch` | Ask for missing box art, and how it went |
| GET/DELETE | `/apps/arcade/arcade/screenshots` | The screenshots of a game |
| GET | `/apps/arcade/arcade/bios` | A BIOS file, by the name a core asks for |
| GET | `/apps/arcade/arcade/bios/status` | What is held and what is missing, admin only |
| POST/DELETE | `/apps/arcade/arcade/bios` | A BIOS file of the admin's System folder, admin only |

The endpoints that write are rate limited, generously enough that no
player ever meets the limit.

Save states and battery saves are removed along with the game they belong
to, and with the user they belong to — but a game deleted into the trash
keeps them, since it can be restored, with the same file id and the same
name. They go when the trash lets go of it. `occ arcade:cleanup` sweeps up
what event listeners cannot catch, such as a whole folder of games deleted
in one go; `--dry-run` reports without removing. The same sweep also runs
by itself once a week, as a background job, so an instance where nobody
runs the command still cleans up after the trash. States written by versions before
0.14 live in one flat folder instead of one per user; they are still read,
and are cleaned up when their game is deleted.

### Capabilities

A client that wants to know whether an instance has Arcade, and what it
will do, asks the server rather than the app:

```
GET /ocs/v2.php/cloud/capabilities
```

The answer carries an `arcade` key when the app is enabled, and nothing
at all when it is not — the capability is registered in
`Application::register()`, which the server only runs for enabled apps,
so there is no state to clean up on disable:

| Key | What |
| --- | --- |
| `version` | The installed version, as the app manager reports it |
| `systems` | Every system played: `id`, `name`, `extensions`, `core`, and `bios` — whether the system wants one, not whether the instance holds it |
| `features` | `saveStates`, `battery`, `screenshots`, `rewind`, `runAhead`, `gamepad`, `tags`, `activity`, as booleans |
| `limits` | `maxGames` and `maxDepth`, as the administrator has them |

It is a plain `ICapability`, so only a session gets it: every endpoint
above needs one, and a caller without a session has nothing to do with
the answer. It is also built from class constants and the two instance
bounds, which the server has already loaded, so it stays cheap enough
to be embedded in the initial state of every page.

## What the app stores

Outside of the files of a user, the app writes:

| Where | What |
| --- | --- |
| `oc_preferences` | Personal settings, and how a box art run went |
| `oc_appconfig` | Core options, thumbnail types, and the folder defaults of the instance |
| `oc_arcade_plays` | When and how long each game was played, per user and file id |
| `oc_arcade_games` | The games that have save states |
| `oc_jobs` | A queued box art lookup while one is running, and the weekly save state cleanup sweep |
| `oc_mimetypes`, `oc_filecache` | The ROM mimetypes, and the files given them |
| `oc_files_metadata` | The system, title, region, MD5 and CRC32 of each ROM, by file id |
| `appdata_*/arcade/` | Save states and battery saves, for as long as no saves folder is set; the BIOS store of the instance |

Favorites are not in that list: they are the favorites of the Files app,
kept in its own tables under the file id. `occ arcade:uninstall` leaves
them alone, as it leaves any other file of a user alone.

Nextcloud removes the code of an app and nothing else, so `occ app:remove`
would leave all of that behind. Run **`occ arcade:uninstall` first**: it puts
the ROMs back to `application/octet-stream`, drops the settings of every user
and of the instance, removes the save states kept by the app, and cancels
queued work. `--dry-run` reports without removing, `--force` skips the
question. Games, saves, screenshots and thumbnails in the folders of a user
are their own files, and are left alone.

Disabling the app does not do any of that. Nextcloud runs uninstall repair
steps on disable, and it disables apps by itself when a server upgrade leaves
them behind — a library wiped by an upgrade would be a poor welcome back — so
the step that runs then only drops the queued lookups and the caches.
