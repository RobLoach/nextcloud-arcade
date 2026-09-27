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

# GET a URL as the admin; body lands in $BODY, the HTTP status is echoed.
get() {
	curl -sS -o "$BODY" -w '%{http_code}' \
		-b "$COOKIES" -H "requesttoken: $TOKEN" "$1"
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
	local code
	code=$(get "$url")
	if [ "$code" != "$want" ]; then
		fail "$name: expected HTTP $want from $url, got $code (body: $(head -c 300 "$BODY"))"
		return 1
	fi
	echo "ok: $name ($code)"
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
