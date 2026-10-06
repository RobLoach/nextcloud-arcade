# Agent notes

- Docker test containers: start them with `docker run --rm`, or remove them with `docker rm -fv`. The `nextcloud` image creates a ~1 GB anonymous volume for `/var/www/html` that a plain `docker rm` leaves behind.
- Prefer `build/smoke-test.sh` over hand-rolled Nextcloud containers; it cleans up after itself.
- Iterating on it: `SMOKE_REUSE=1 build/smoke-test.sh` keeps the installed instance in the `arcade-smoke-data` volume and reruns in ~20s instead of ~3min. Plain runs stay hermetic; remove the volume with `docker volume rm arcade-smoke-data`.
