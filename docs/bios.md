# BIOS files

The systems that ask for one, and how to give it to them.

A few systems ask for a BIOS file, and the ones that do look for it by the
name their core expects. Point the system folder at a folder holding them
and they are handed to the emulator as a game starts:

| System | File |
| --- | --- |
| ColecoVision | `colecovision.rom` |
| PC Engine CD | `syscard3.pce` |
| Sega 32X | `32X_G_BIOS.BIN`, `32X_M_BIOS.BIN`, `32X_S_BIOS.BIN` |
| Game Boy, Game Boy Color | `gb_bios.bin`, `gbc_bios.bin` |
| Game Boy Advance | `gba_bios.bin` |
| Master System, Game Gear | `bios.sms`, `bios.gg` |
| Mega Drive | `bios_MD.bin` |
| Atari Lynx | `lynxboot.img` |
| PlayStation | `scph1001.bin`, `scph5501.bin`, `scph5500.bin`, `scph5502.bin` |

Only ColecoVision really needs one; for the rest the file is optional, and
a missing one is quietly left out rather than keeping a game from starting
— the player says so once, as the game launches. The PlayStation runs
without one too, but poorly, so its BIOS is strongly recommended. The
names are matched without regard to case, so `SCPH1001.BIN` serves as
`scph1001.bin`.

## The administration settings page

Administration settings → Arcade has a BIOS section that shows, for every
system that asks, which files are there and which are missing — seen the
way the administrator's own player would see them. A missing file can be
uploaded right there: it lands in the System folder, renamed to the
spelling the core asks for, and only names some core actually asks for
are taken, so the folder cannot become a place to keep files in general.
The section appears once a System folder is set in the personal settings;
without one there is nowhere to put anything, so it is not shown.

## The instance-wide store

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
