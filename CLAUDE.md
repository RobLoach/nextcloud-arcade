# Agent notes

- Docker test containers: start them with `docker run --rm`, or remove them with `docker rm -fv`. The `nextcloud` image creates a ~1 GB anonymous volume for `/var/www/html` that a plain `docker rm` leaves behind.
- Prefer `build/smoke-test.sh` over hand-rolled Nextcloud containers; it cleans up after itself.
