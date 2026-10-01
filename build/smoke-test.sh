#!/usr/bin/env bash
#
# Smoke-test the app against a real Nextcloud server.
#
# Unit tests and Psalm only see the app's own code; the bugs this catches
# live in the server it runs inside -- query-optimizer crashes, mimetype
# registration gone wrong, listeners that never fire. So: install a real
# Nextcloud, enable the app, seed some ROMs, and hit the endpoints a
# browser would, then do it all again through an app upgrade.
#
# Usage:
#   build/smoke-test.sh                # starts its own container, cleans up
#   build/smoke-test.sh <container>    # uses a running nextcloud container
#
# Tunables (environment): SMOKE_PORT (default 8480), SMOKE_IMAGE
# (default nextcloud:35-apache), SMOKE_ADMIN_PASS.

set -euo pipefail

REPO_ROOT=$(cd "$(dirname "$0")/.." && pwd)
CONTAINER=${1:-}
OWN_CONTAINER=
PORT=${SMOKE_PORT:-8480}
IMAGE=${SMOKE_IMAGE:-nextcloud:35-apache}
ADMIN_USER=admin
ADMIN_PASS=${SMOKE_ADMIN_PASS:-smoke-Adm1n-pass}
BASE="http://127.0.0.1:${PORT}"
WORKDIR=$(mktemp -d)
COOKIES="$WORKDIR/cookies.txt"
BODY="$WORKDIR/body"
FAILED=

cleanup() {
	local status=$?
	if [ -n "$OWN_CONTAINER" ]; then
		if [ "$status" -ne 0 ]; then
			echo '--- last container logs ---'
			docker logs --tail 20 "$CONTAINER" 2>&1 || true
		fi
		docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
	fi
	rm -rf "$WORKDIR"
}
trap cleanup EXIT

fail() {
	echo "FAIL: $*" >&2
	FAILED=1
}

die() {
	echo "FATAL: $*" >&2
	exit 1
}

step() {
	echo
	echo "==> $*"
}

occ() {
	docker exec -u www-data "$CONTAINER" php occ "$@"
}

# Ask the server something as the admin; body lands in $BODY, the HTTP
# status is echoed. A plain GET needs only the URL; anything else -- a
# POST, a form field -- passes its own curl arguments before it.
get() {
	curl -sS -o "$BODY" -w '%{http_code}' \
		-b "$COOKIES" -H "requesttoken: $TOKEN" "$@"
}

# As above, but fails the run when the status is not the one wanted.
expect_status() {
	local name=$1 want=$2
	shift 2
	local code
	code=$(get "$@")
	if [ "$code" != "$want" ]; then
		fail "$name: expected HTTP $want, got $code (body: $(head -c 300 "$BODY"))"
		return 1
	fi
	echo "ok: $name ($code)"
}

# Run a python3 check against $BODY; $1 names the assertion, $2 is the code.
# The code gets the parsed JSON as `data` and fails by raising.
assert_json() {
	local name=$1 code=$2
	if ! python3 - "$BODY" <<PY
import json, sys
try:
    data = json.load(open(sys.argv[1]))
except Exception as e:
    raise SystemExit(f"response is not JSON: {e}")
$code
PY
	then
		fail "$name (body: $(head -c 300 "$BODY"))"
	fi
}

assert_status() {
	local name=$1 url=$2 want=${3:-200}
	expect_status "$name" "$want" "$url"
}

# The failure signature all three past regressions shared: the app made the
# server log an error. Any log line naming the app alongside an error or
# exception fails the run, and is printed. Debug and info entries are let
# through: occ upgrade turns the log up to debug, and those entries carry
# harmless backtraces that would match the words without being a problem.
check_log() {
	local when=$1
	if ! docker exec "$CONTAINER" sh -c \
		'cat /var/www/html/data/nextcloud.log 2>/dev/null' \
		| python3 -c '
import json, sys
bad = []
for line in sys.stdin:
    lower = line.lower()
    if "arcade" not in lower:
        continue
    if "error" not in lower and "exception" not in lower:
        continue
    try:
        level = json.loads(line).get("level", 4)
    except Exception:
        level = 4
    if level >= 2:  # warning and up; debug/info backtraces are not failures
        bad.append(line.rstrip())
if bad:
    for line in bad[:20]:
        print(line[:800], file=sys.stderr)
    raise SystemExit(f"{len(bad)} offending line(s)")
'
	then
		fail "nextcloud.log has arcade errors $when"
	else
		echo "ok: nextcloud.log clean $when"
	fi
}

# --- server -----------------------------------------------------------------

if [ -z "$CONTAINER" ]; then
	CONTAINER="arcade-smoke-$$"
	OWN_CONTAINER=1
	step "Starting $IMAGE as $CONTAINER on port $PORT"
	docker run -d --name "$CONTAINER" -p "127.0.0.1:${PORT}:80" "$IMAGE" >/dev/null
fi

step 'Waiting for the container to finish initializing'
for i in $(seq 1 90); do
	if docker logs "$CONTAINER" 2>&1 | grep -q 'Initializing finished'; then
		break
	fi
	[ "$i" = 90 ] && die 'container never finished initializing'
	sleep 2
done

step 'Installing Nextcloud (sqlite)'
occ maintenance:install --database sqlite \
	--admin-user "$ADMIN_USER" --admin-pass "$ADMIN_PASS" \
	|| die 'maintenance:install failed'
occ config:system:set trusted_domains 1 --value=127.0.0.1 >/dev/null

# --- the app ----------------------------------------------------------------

step 'Installing the app into the container'
docker exec "$CONTAINER" mkdir -p /var/www/html/apps/arcade
git -C "$REPO_ROOT" ls-files -z \
	| tar -C "$REPO_ROOT" --null -T - -cf - \
	| docker exec -i "$CONTAINER" tar -xf - -C /var/www/html/apps/arcade
docker exec "$CONTAINER" chown -R www-data:www-data /var/www/html/apps/arcade

step 'Enabling the app'
occ app:enable arcade || die 'app:enable arcade failed'

step 'Seeding ROMs'
docker exec -u www-data "$CONTAINER" sh -c '
	set -e
	d=/var/www/html/data/admin/files/Games
	mkdir -p "$d/Super Nintendo"
	printf "not a real Game Boy ROM"           > "$d/Tetris.gb"
	printf "not a real Super Nintendo ROM"     > "$d/Super Nintendo/Chrono.sfc"
	mkdir -p /var/www/html/data/admin/files/Saves
'
occ files:scan "$ADMIN_USER" || die 'files:scan failed'

# --- authenticated requests --------------------------------------------------

step 'Logging in'
# The web login: fetch the form for its CSRF token, post the credentials
# with the Origin header Nextcloud insists on, then pick up the session's
# own token for the requests that follow.
curl -sSf -c "$COOKIES" -o "$BODY" "$BASE/index.php/login" \
	|| die 'could not reach the login page'
LOGIN_TOKEN=$(grep -o 'data-requesttoken="[^"]*"' "$BODY" | head -1 \
	| sed 's/data-requesttoken="//;s/"$//')
[ -n "$LOGIN_TOKEN" ] || die 'no requesttoken on the login page'
LOCATION=$(curl -sSf -o /dev/null -w '%{redirect_url}' \
	-b "$COOKIES" -c "$COOKIES" \
	-H "Origin: $BASE" -e "$BASE/index.php/login" \
	--data-urlencode "user=$ADMIN_USER" \
	--data-urlencode "password=$ADMIN_PASS" \
	--data-urlencode "requesttoken=$LOGIN_TOKEN" \
	"$BASE/index.php/login")
case "$LOCATION" in
	*/login*|'') die "login failed, redirected to '$LOCATION'" ;;
esac
curl -sSf -b "$COOKIES" -c "$COOKIES" -o "$BODY" "$BASE/index.php/csrftoken" \
	|| die 'could not reach /index.php/csrftoken'
TOKEN=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["token"])' "$BODY") \
	|| die 'no CSRF token in the response'

step 'Hitting the endpoints'
assert_status 'app page' "$BASE/index.php/apps/arcade/" || true

if assert_status 'library endpoint' "$BASE/index.php/apps/arcade/arcade/library?limit=5"; then
	assert_json 'library lists the seeded ROMs' '
names = [g.get("basename", "") for g in data.get("games", [])]
if not any("Tetris" in n for n in names):
    raise SystemExit(f"Tetris.gb missing from games: {names}")
if not any("Chrono" in n for n in names):
    raise SystemExit(f"Chrono.sfc missing from games: {names}")
systems = data.get("systems", [])
if "gb" not in systems:
    raise SystemExit(f"gb missing from systems: {systems}")
'
fi

if assert_status 'suggest endpoint' "$BASE/index.php/apps/arcade/arcade/suggest"; then
	assert_json 'suggest returns JSON' 'pass'
fi

if assert_status 'game endpoint' "$BASE/index.php/apps/arcade/arcade/game?file=/Games/Tetris.gb"; then
	assert_json 'game reports a system id' '
system = data.get("system") or {}
if not system.get("id"):
    raise SystemExit(f"no system.id in: {data}")
'
fi

step 'Reading the capabilities'
# What other apps and clients read this app through. The version is taken
# from the app manager, so a capabilities payload that still answers proves
# the app is loaded, not merely installed.
if assert_status 'capabilities' "$BASE/ocs/v2.php/cloud/capabilities?format=json"; then
	VERSION_NOW=$(sed -n 's/.*<version>\(.*\)<\/version>.*/\1/p' "$REPO_ROOT/appinfo/info.xml" | head -1)
	assert_json 'capabilities carry the app' "
arcade = data['ocs']['data']['capabilities'].get('arcade')
if arcade is None:
    raise SystemExit('no arcade key in the capabilities')
if arcade.get('version') != '$VERSION_NOW':
    raise SystemExit(f\"version {arcade.get('version')!r}, expected '$VERSION_NOW'\")
systems = {s['id'] for s in arcade.get('systems', [])}
if 'gb' not in systems:
    raise SystemExit(f'gb missing from the advertised systems: {sorted(systems)}')
if not isinstance(arcade.get('features'), dict) or not arcade['features']:
    raise SystemExit(f\"features missing: {arcade.get('features')!r}\")
if arcade.get('limits', {}).get('maxGames', 0) <= 0:
    raise SystemExit(f\"limits look wrong: {arcade.get('limits')!r}\")
"
fi

# --- save states outlive the game only until it is really gone ---------------

step 'Saving a state and throwing the game away'
GAME=/Games/Tetris.gb
# Saves need somewhere of the user's own to live, and there is no default
# for that: pointing the setting at a folder is what a player does first,
# so the settings endpoint is exercised on the way.
if expect_status 'the saves folder is set' 200 -X POST \
	--data-urlencode 'saves_folder=/Saves' \
	"$BASE/index.php/apps/arcade/arcade/settings"; then
	assert_json 'the saves folder was kept' '
kept = data.get("saves_folder")
if kept != "/Saves":
    raise SystemExit(f"saves_folder came back as {kept!r}")
'
fi
STATE_URL="$BASE/index.php/apps/arcade/arcade/state?file=$GAME&slot=1"
expect_status 'a state is saved' 200 -X POST \
	-H 'Content-Type: application/octet-stream' \
	--data-binary 'not a real save state' "$STATE_URL" || true

if assert_status 'states are listed' "$BASE/index.php/apps/arcade/arcade/states?file=$GAME"; then
	assert_json 'the saved state is there' '
slots = [s.get("slot") for s in data.get("states", [])]
if 1 not in slots:
    raise SystemExit(f"slot 1 missing from: {slots}")
'
fi

# From here the game itself goes away, and the endpoint that lists states
# turns away a request naming a game the user does not have -- so the save
# is watched where it actually lives: in the user's own files.
SAVES_DIR=/var/www/html/data/admin/files/Saves
SAVE_FILE=$(docker exec "$CONTAINER" find "$SAVES_DIR" -name '*.state' | head -1)
if [ -z "$SAVE_FILE" ]; then
	die "nothing was written under $SAVES_DIR ($(docker exec "$CONTAINER" find "$SAVES_DIR" | head -5 | tr '\n' ' '))"
fi
echo "ok: the save was written to ${SAVE_FILE#"$SAVES_DIR"/}"
save_is() {
	local want=$1 when=$2
	if docker exec "$CONTAINER" test -f "$SAVE_FILE"; then
		local have=present
	else
		local have=gone
	fi
	if [ "$have" != "$want" ]; then
		fail "the save is $have $when, expected $want"
	else
		echo "ok: the save is $have $when"
	fi
}

# Into the trash first, where the save is meant to survive: a game brought
# back out of the trash keeps what was saved of it.
DAV="$BASE/remote.php/dav/files/$ADMIN_USER"
curl -sSf -o /dev/null -X DELETE -b "$COOKIES" -H "requesttoken: $TOKEN" "$DAV$GAME" \
	|| die 'could not move the game to the trash'
save_is present 'while the game sits in the trash'

step 'Expunging the game from the trash'
# NodeDeletedEvent never fires for a path inside the trash, so saves used
# to be left behind for ever. The legacy preDelete hook is what finally
# collects them, and only a real expunge exercises it.
TRASH="$BASE/remote.php/dav/trashbin/$ADMIN_USER/trash"
curl -sS -o "$BODY" -X PROPFIND -b "$COOKIES" -H "requesttoken: $TOKEN" \
	-H 'Depth: 1' "$TRASH" || die 'could not list the trash'
TRASH_ITEM=$(grep -o '<d:href>[^<]*Tetris[^<]*</d:href>' "$BODY" | head -1 \
	| sed 's|<d:href>||;s|</d:href>||')
[ -n "$TRASH_ITEM" ] || die "the game is not in the trash (body: $(head -c 300 "$BODY"))"
curl -sSf -o /dev/null -X DELETE -b "$COOKIES" -H "requesttoken: $TOKEN" \
	"$BASE$TRASH_ITEM" || die 'could not expunge the game from the trash'
save_is gone 'once the game is expunged'

step 'Running occ arcade:cleanup --dry-run'
# The sweep that collects what the events missed. A dry run touches
# nothing, so it is safe to run here, and it is the only place the sweep
# is exercised against a real database.
if occ arcade:cleanup --dry-run > "$BODY" 2>&1; then
	grep -q 'Dry run' "$BODY" || fail "arcade:cleanup --dry-run said nothing about being a dry run: $(head -c 300 "$BODY")"
	grep -q 'Removed the states of' "$BODY" || fail "arcade:cleanup --dry-run reported no counts: $(head -c 300 "$BODY")"
	echo 'ok: arcade:cleanup --dry-run'
else
	fail "occ arcade:cleanup --dry-run exited non-zero: $(head -c 300 "$BODY")"
fi

step 'Fetching the JavaScript bundles'
assert_status 'main entry bundle' "$BASE/apps/arcade/js/arcade-main.mjs" || true
CHUNK=$(cd "$REPO_ROOT/js" && ls -- *.chunk.mjs | head -1)
[ -n "$CHUNK" ] || die 'no *.chunk.mjs in js/'
assert_status "chunk bundle ($CHUNK)" "$BASE/apps/arcade/js/$CHUNK" || true

step 'Running occ arcade:status'
if occ arcade:status; then
	echo 'ok: arcade:status'
else
	fail 'occ arcade:status exited non-zero'
fi

check_log 'after the fresh install'

# --- the upgrade path ---------------------------------------------------------

step 'Upgrading the app in place'
VERSION=$(sed -n 's/.*<version>\(.*\)<\/version>.*/\1/p' "$REPO_ROOT/appinfo/info.xml" | head -1)
NEXT="${VERSION%.*}.$(( ${VERSION##*.} + 1 ))"
echo "bumping $VERSION -> $NEXT to force the app upgrade path"
docker exec "$CONTAINER" sed -i \
	"s|<version>$VERSION</version>|<version>$NEXT</version>|" \
	/var/www/html/apps/arcade/appinfo/info.xml
if occ upgrade; then
	echo 'ok: occ upgrade'
else
	fail 'occ upgrade exited non-zero'
fi
check_log 'after the upgrade'

# --- verdict ------------------------------------------------------------------

echo
if [ -n "$FAILED" ]; then
	echo 'Smoke test FAILED' >&2
	exit 1
fi
echo 'Smoke test passed'
