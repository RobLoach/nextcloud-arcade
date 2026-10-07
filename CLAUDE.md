# Agent notes

- Docker test containers: start them with `docker run --rm`, or remove them with `docker rm -fv`. The `nextcloud` image creates a ~1 GB anonymous volume for `/var/www/html` that a plain `docker rm` leaves behind.
- Prefer `build/smoke-test.sh` over hand-rolled Nextcloud containers; it cleans up after itself.
- `SMOKE_TMPFS=1 build/smoke-test.sh` keeps the whole server in memory (2 GB tmpfs): no volume at all, and a fresh run in ~50s instead of ~170s.
- The smoke test stays on the `-apache` image deliberately. `-fpm-alpine` ships no web server, and the app's own `img/cores/.htaccess` only means anything to one that reads it.
- Iterating on it: `SMOKE_REUSE=1 build/smoke-test.sh` keeps the installed instance in the `arcade-smoke-data` volume and reruns in ~20s instead of ~3min. Plain runs stay hermetic; remove the volume with `docker volume rm arcade-smoke-data`.
