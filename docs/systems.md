# Systems

For anyone wondering whether their ROMs will play, and on what. How to
supply a BIOS file is in [the settings guide](settings.md#bios-files).

One proven libretro core is used per system, and they all ship with the
app, so there is nothing to choose or install.

| System | Core | Extensions | BIOS files |
| --- | --- | --- | --- |
| Nintendo Entertainment System | fceumm | `nes`, `fds`, `unf`, `unif` | — |
| Super Nintendo | snes9x | `sfc`, `smc` | — |
| Game Boy | gambatte | `gb` | `gb_bios.bin` |
| Game Boy Color | gambatte | `gbc` | `gbc_bios.bin` |
| Game Boy Advance | mgba | `gba` | `gba_bios.bin` |
| Sega Genesis / Mega Drive | genesis_plus_gx | `md`, `gen`, `smd` | `bios_MD.bin` |
| Sega Master System | genesis_plus_gx | `sms` | `bios.sms` |
| Sega Game Gear | genesis_plus_gx | `gg` | `bios.gg` |
| Sega 32X | picodrive | `32x` | `32X_G_BIOS.BIN`, `32X_M_BIOS.BIN`, `32X_S_BIOS.BIN` |
| PC Engine / TurboGrafx-16 | mednafen_pce_fast | `pce` | `syscard3.pce` |
| Atari Lynx | handy | `lnx` | `lynxboot.img` |
| Neo Geo Pocket | mednafen_ngp | `ngp`, `ngc` | — |
| WonderSwan | mednafen_wswan | `ws`, `wsc` | — |
| Virtual Boy | mednafen_vb | `vb` | — |
| Vectrex | vecx | `vec` | — |
| ColecoVision | gearcoleco | `col` | `colecovision.rom` |
| PlayStation | pcsx_rearmed | `chd`, `pbp` | `scph1001.bin`, `scph5501.bin`, `scph5500.bin`, `scph5502.bin` |

A BIOS is optional for most of these: a missing file is quietly left out
rather than keeping a game from starting, and the player says so once, as
the game launches. ColecoVision will not start without its file, and the
PlayStation runs without one, but poorly, so its BIOS is strongly
recommended.

## Notes

**PlayStation** takes only the single-file disc formats, `.chd` and
`.pbp`. A `.cue` names its tracks in other files and a `.bin` or `.iso`
does not say whose it is, so those wait until multi-file games can be
handed over whole.

**Zipped ROMs** are extracted in the browser. The system of a zipped game
is detected from the file inside the archive, or from the folder it is
stored in.

**Folder names** place a game when its extension does not. Short names,
spelled out names and No-Intro platform names all work, with or without
the maker in front and with a word like "ROMs" hung off the end, so
`Games/SNES/NHL 96.zip`, `Games/Super Nintendo Games/NHL 96.zip` and
`Games/Nintendo - Super Nintendo Entertainment System/NHL 96.zip` are all
recognized as Super Nintendo.

**`.bin` and `.rom`** say nothing about which machine they are for: a
`.bin` is a Mega Drive game, a 32X game, a ColecoVision game, a track of
a disc or firmware, so no system can claim the extension the way `.sfc`
is claimed. Those files are placed by the first of these that answers:
the folder they are in, and then the file itself, whose first bytes on a
cartridge dump carry a mark saying whose it is. Where the two disagree —
a Mega Drive dump sitting in an `SNES` folder — the mark wins, since the
folder was only ever a guess. The reading happens in the background, so a
`.bin` may be listed without a system for a moment and settle on one
afterwards. The same goes for what is inside a zip.

**`.md`** is Markdown to Nextcloud before it is a Mega Drive dump, and
extensions the server already maps to something of its own are not taken
over, so a `.md` only counts as a game when its mimetype or its folder
says so.

## Where the cores come from

The cores are built by
[retroarch-emscripten-build](https://github.com/arianrhodsandlot/retroarch-emscripten-build),
which is a git submodule of this repository. To extract them again from
the submodule, run:

```sh
npm run cores
```
