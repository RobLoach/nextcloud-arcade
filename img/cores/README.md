# Emulator cores

The files beside this one are libretro cores built for WebAssembly. They are
**not** part of Nextcloud Arcade and are **not** covered by the app's own
AGPL-3.0-or-later licence. Each is a separate work by its own authors, shipped
here unmodified, and each carries its own terms.

The full text of every licence below is in the app's top-level `LICENSES/`,
which ships with the app. Two of these cores require that their notice
accompany any redistribution: passing this folder on by itself means passing
`LicenseRef-Snes9x.txt` and `LicenseRef-GenesisPlusGX.txt` on with it.

| Core | Systems | Licence | Text | Upstream |
| --- | --- | --- | --- | --- |
| `fceumm_libretro` | Nintendo Entertainment System | GPLv2 | [GPL-2.0-or-later](../../LICENSES/GPL-2.0-or-later.txt) | [libretro-fceumm](https://github.com/libretro/libretro-fceumm) |
| `snes9x_libretro` | Super Nintendo | **Snes9x, non-commercial** | [LicenseRef-Snes9x](../../LICENSES/LicenseRef-Snes9x.txt) | [snes9x](https://github.com/libretro/snes9x) |
| `gambatte_libretro` | Game Boy, Game Boy Color | GPLv2 | [GPL-2.0-or-later](../../LICENSES/GPL-2.0-or-later.txt) | [gambatte-libretro](https://github.com/libretro/gambatte-libretro) |
| `mgba_libretro` | Game Boy Advance | MPLv2.0 | [MPL-2.0](../../LICENSES/MPL-2.0.txt) | [mgba](https://github.com/libretro/mgba) |
| `genesis_plus_gx_libretro` | Mega Drive | **Non-commercial** | [LicenseRef-GenesisPlusGX](../../LICENSES/LicenseRef-GenesisPlusGX.txt) | [Genesis-Plus-GX](https://github.com/libretro/Genesis-Plus-GX) |
| `gearsystem_libretro` | Master System, Game Gear | GPLv3 | [GPL-3.0-or-later](../../LICENSES/GPL-3.0-or-later.txt) | [gearsystem](https://github.com/libretro/gearsystem) |
| `mednafen_pce_fast_libretro` | PC Engine / TurboGrafx-16 | GPLv2 | [GPL-2.0-or-later](../../LICENSES/GPL-2.0-or-later.txt) | [beetle-pce-fast-libretro](https://github.com/libretro/beetle-pce-fast-libretro) |
| `handy_libretro` | Atari Lynx | Zlib | [Zlib](../../LICENSES/Zlib.txt) | [libretro-handy](https://github.com/libretro/libretro-handy) |
| `mednafen_ngp_libretro` | Neo Geo Pocket | GPLv2 | [GPL-2.0-or-later](../../LICENSES/GPL-2.0-or-later.txt) | [beetle-ngp-libretro](https://github.com/libretro/beetle-ngp-libretro) |
| `mednafen_wswan_libretro` | WonderSwan | GPLv2 | [GPL-2.0-or-later](../../LICENSES/GPL-2.0-or-later.txt) | [beetle-wswan-libretro](https://github.com/libretro/beetle-wswan-libretro) |
| `mednafen_vb_libretro` | Virtual Boy | GPLv2 | [GPL-2.0-or-later](../../LICENSES/GPL-2.0-or-later.txt) | [beetle-vb-libretro](https://github.com/libretro/beetle-vb-libretro) |
| `vecx_libretro` | Vectrex | GPLv3 | [GPL-3.0-or-later](../../LICENSES/GPL-3.0-or-later.txt) | [libretro-vecx](https://github.com/libretro/libretro-vecx) |
| `gearcoleco_libretro` | ColecoVision | GPLv3 | [GPL-3.0-or-later](../../LICENSES/GPL-3.0-or-later.txt) | [gearcoleco](https://github.com/libretro/gearcoleco) |
| `pcsx_rearmed_libretro` | PlayStation | GPLv2 | [GPL-2.0-or-later](../../LICENSES/GPL-2.0-or-later.txt) | [pcsx_rearmed](https://github.com/libretro/pcsx_rearmed) |

The Licence column records what libretro's own core-info declares for each
core. Where that says only "GPLv2" or "GPLv3" without saying whether later
versions are allowed, the `-or-later` text is shipped, which is the usual
reading; each upstream project's own licence file governs if the two ever
disagree.

## The two that are not free for commercial use

`snes9x` and `genesis_plus_gx` may not be sold or distributed as part of a
commercial product or activity:

- Snes9x is *"freeware for PERSONAL USE only. Commercial users should seek
  permission of the copyright holders first."* It forbids charging money for
  it, including it in commercial bundles, and using it to promote a commercial
  product.
- Genesis Plus GX: *"Redistributions may not be sold, nor may they be used in
  a commercial product or activity."*

Both also require that their notice and licence accompany any distribution,
commercial or not — which is what the top-level `LICENSES/` is for.

For a discussion around this, see [#52](https://github.com/RobLoach/nextcloud-arcade/issues/52).

## How these get here

`npm run cores` unpacks them from
[retroarch-emscripten-build](https://github.com/arianrhodsandlot/retroarch-emscripten-build),
fetched at the commit pinned in `build/cores.sh`, which repackages the libretro
buildbot's emscripten output. Nothing
in this folder is built from source by this repository, and nothing in it is
modified after unpacking. This file is kept in git and is not touched by that
script.
