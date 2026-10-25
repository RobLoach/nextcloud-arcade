# NextCloud Arcade

Play retro console games directly in [NextCloud](https://nextcloud.com/), through
[Nostalgist.js](https://nostalgist.js.org/) and the RetroArch libretro cores
compiled to WebAssembly. ROMs are streamed from your files, and run in the browser.

[![NextCloud Arcade](img/screenshot-thumbnail.jpg)](img/screenshot.png)

![A game running](screenshots/game.png)

![The games library](screenshots/games-library.png)

![The library as a list](screenshots/games-list.png)

![The personal settings](screenshots/configuration.png)

![The administration settings](screenshots/administration.png)

## Features

- Seventeen systems, from the ColecoVision to the PlayStation.
- Emulation in the browser; nothing leaves the server.
- A games library with box art, play statistics and tags.
- Save states and battery saves, per user.
- Gamepad, touch and keyboard controls.
- Plays ROMs straight from the Files app.
- Personal settings for every player, down to the keys.

## Dependencies

- Nextcloud 34 or 35
- PHP 8.3 or newer
- Background jobs on cron (recommended)

## Installation

1. Put the app into `apps/`:

	```sh
	cd /path/to/nextcloud/apps
	git clone https://github.com/robloach/nextcloud-arcade.git arcade
	```

2. Enable it:

	```sh
	occ app:enable arcade
	```

3. If ROMs were uploaded prior to the app being enabled, they still carry a
	generic mimetype. Run the following to fix the mimetype:
    ```sh
    occ maintenance:mimetype:update-db
    ```

5. Open Administration → Arcade, and set the default Games folder.

## Updating

To update the application, use:
```sh
cd /path/to/nextcloud/apps/arcade
git pull
```

## Documentation

| Page | Who it is for |
| --- | --- |
| [Using Arcade](docs/usage.md) | Playing a game, and everything inside the player |
| [The games library](docs/library.md) | Browsing the library, and what Arcade adds to Files |
| [Settings](docs/settings.md) | Administrators, and anyone wondering what a setting does |
| [Systems](docs/systems.md) | Which ROMs play, on which core, with which BIOS |
| [Development](docs/development.md) | Changing the code |

## License

*NextCloud Arcade* is licensed under [AGPLv3](LICENSE). Supporting library licenses captured in [AUTHORS](AUTHORS.md).
