# Authors

NextCloud Arcade is written by [Rob Loach](https://robloach.net), and is
[AGPL-3.0-or-later](LICENSE).

Everybody who has contributed is listed on the
[contributors page](https://github.com/robloach/nextcloud-arcade/graphs/contributors).

## What it is built on

The app is a thin thing wrapped around other people's work. It would not
exist without any of this.

### Emulation

- **[Nostalgist.js](https://github.com/arianrhodsandlot/nostalgist)** by
  arianrhodsandlot — runs a libretro core in the browser and gives it a ROM,
  a save state and a controller. MIT.
- **[RetroArch and the libretro cores](https://github.com/libretro/RetroArch)**,
  compiled to WebAssembly by
  **[retroarch-emscripten-build](https://github.com/arianrhodsandlot/retroarch-emscripten-build)**.
  The cores shipped here, and the systems they run:

  | Core | Systems | Licence |
  | --- | --- | --- |
  | [fceumm](https://github.com/libretro/libretro-fceumm) | Nintendo Entertainment System | GPL-2.0 |
  | [snes9x](https://github.com/libretro/snes9x) | Super Nintendo | Snes9x, non-commercial |
  | [gambatte](https://github.com/libretro/gambatte-libretro) | Game Boy, Game Boy Color | GPL-2.0 |
  | [mgba](https://github.com/libretro/mgba) | Game Boy Advance | MPL-2.0 |
  | [genesis_plus_gx](https://github.com/libretro/Genesis-Plus-GX) | Mega Drive | Non-commercial |
  | [mednafen_pce_fast](https://github.com/libretro/beetle-pce-fast-libretro) | PC Engine / TurboGrafx-16 | GPL-2.0 |
  | [handy](https://github.com/libretro/libretro-handy) | Atari Lynx | Zlib |
  | [mednafen_ngp](https://github.com/libretro/beetle-ngp-libretro) | Neo Geo Pocket | GPL-2.0 |
  | [mednafen_wswan](https://github.com/libretro/beetle-wswan-libretro) | WonderSwan | GPL-2.0 |
  | [mednafen_vb](https://github.com/libretro/beetle-vb-libretro) | Virtual Boy | GPL-2.0 |
  | [vecx](https://github.com/libretro/libretro-vecx) | Vectrex | GPL-3.0 |
  | [gearcoleco](https://github.com/libretro/gearcoleco) | ColecoVision | GPL-3.0 |
  | [gearsystem](https://github.com/libretro/gearsystem) | Master System, Game Gear | GPL-3.0 |
  | [pcsx_rearmed](https://github.com/libretro/pcsx_rearmed) | PlayStation | GPL-2.0 |

  Some of those cores are not free for commercial use. The licence of each
  is in `img/cores/license`, as it comes from the build.

### In the browser

- **[fflate](https://github.com/101arrowz/fflate)** — unpacks a zipped ROM
  without leaving the page. MIT.
- **[@nextcloud/auth](https://github.com/nextcloud-libraries/nextcloud-auth)**,
  **[files](https://github.com/nextcloud-libraries/nextcloud-files)**,
  **[initial-state](https://github.com/nextcloud-libraries/nextcloud-initial-state)**,
  **[l10n](https://github.com/nextcloud-libraries/nextcloud-l10n)**,
  **[router](https://github.com/nextcloud-libraries/nextcloud-router)** and
  **[dialogs](https://github.com/nextcloud-libraries/nextcloud-dialogs)** —
  the browser side of Nextcloud. AGPL-3.0.

### Pictures

- **[libretro-thumbnails](https://github.com/libretro-thumbnails/libretro-thumbnails)**
  — the box art, title screens and screenshots the app can go looking for,
  contributed by the libretro community.
- The controller icon is
  [game-controller-gamepad](https://www.svgrepo.com/svg/255536/game-controller-gamepad)
  from SVG Repo, CC0.

### Around it

- **[Nextcloud](https://github.com/nextcloud/server)** itself, whose file
  handling, previews, tags, metadata and background jobs the app leans on
  throughout. AGPL-3.0.

No ROM is included with the app, and none is distributed by it. The games
are the ones already in your own files.
