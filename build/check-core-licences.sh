#!/usr/bin/env bash
#
# Every core shipped has to carry its own terms.
#
# Two of the cores may not be sold or put in a commercial product, and both
# of those require their notice to travel with the binary. The rest are free
# software that still asks for its notice. So a core that lands in img/cores/
# without an entry saying whose it is and under what terms is a licence
# breach, not an untidy folder -- and the way that happens is somebody adding
# a core to the `cores` script and not to the manifest.
#
# Run by `npm run cores`, so the two cannot drift apart unnoticed.

set -euo pipefail

cd "$(dirname "$0")/.."

readme='img/cores/README.md'
reuse='REUSE.toml'
licenses='LICENSES'

missing=0

for wasm in img/cores/*_libretro.wasm; do
	[ -e "$wasm" ] || continue
	core="$(basename "$wasm" .wasm)"

	if ! grep -qF "$core" "$readme"; then
		echo "error: $core is shipped but $readme does not say whose it is" >&2
		missing=1
	fi
	# The REUSE entries match on a glob, so look for the stem rather than
	# the file name the loop happens to be holding.
	if ! grep -qF "${core%_libretro}_libretro." "$reuse"; then
		echo "error: $core is shipped but $reuse does not license it" >&2
		missing=1
	fi
done

# Every licence the manifest names has to be one that actually travels.
while read -r text; do
	if [ ! -f "$licenses/$text.txt" ]; then
		echo "error: $readme points at $text, which is not in $licenses" >&2
		missing=1
	fi
done < <(grep -o 'LICENSES/[A-Za-z0-9.-]*\.txt' "$readme" \
	| sed 's|LICENSES/||; s|\.txt$||' | sort -u)

if [ "$missing" -ne 0 ]; then
	echo "" >&2
	echo "See $readme for what an entry looks like." >&2
	exit 1
fi

echo "Core licences: every core in img/cores/ is accounted for."
