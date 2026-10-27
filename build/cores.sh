#!/usr/bin/env bash
#
# Unpack the libretro cores into img/cores/.
#
# The cores come from retroarch-emscripten-build, which repackages the
# libretro buildbot's emscripten output. It is fetched at a pinned commit, so
# the same commit always gives the same cores; bump `rev` to update them.
#
# Run by `npm run cores`, which then runs build/check-core-licences.sh.

set -euo pipefail

cd "$(dirname "$0")/.."

repo='https://github.com/arianrhodsandlot/retroarch-emscripten-build.git'
rev='dd441319a61ba53560509c2d91c7903af719ca31'

# The default core for each supported system, see lib/CoreMap.php.
cores=(
	fceumm
	snes9x
	gambatte
	mgba
	genesis_plus_gx
	gearsystem
	mednafen_pce_fast
	handy
	mednafen_ngp
	mednafen_wswan
	mednafen_vb
	vecx
	gearcoleco
	pcsx_rearmed
)

src="$(mktemp -d)"
trap 'rm -rf "$src"' EXIT

git -C "$src" init -q
git -C "$src" fetch -q --depth 1 "$repo" "$rev"
git -C "$src" checkout -q FETCH_HEAD

mkdir -p img/cores
for core in "${cores[@]}"; do
	unzip -o -q "$src/retroarch/${core}_libretro.zip" -d img/cores
done

echo "Cores: unpacked ${#cores[@]} from retroarch-emscripten-build@${rev:0:7}."
