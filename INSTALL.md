# Installing Rihla

Everything runs in Docker: PHP 8.4-FPM, Nginx and MySQL 8.4. You need nothing else on the machine, not even PHP or Composer.

## Requirements

- Docker with Docker Compose v2 (Docker Desktop on macOS and Windows, Docker Engine on Linux)
- Git
- About 2 GB of free disk space for Docker
- Free ports 8080 (web) and 3306 (MySQL); see [Ports](#ports) to change them
- An internet connection on the first start (Composer packages, JavaScript packages) and for the maps (OpenStreetMap tiles, Nominatim, OSRM)

These steps were checked on 2026-11-11 from a fresh clone of the GitHub repository (macOS, Docker Desktop): the app started with its demo data and the 407 tests passed.

## Quick start

```bash
git clone https://github.com/oubellaismail/rihla.git
cd rihla
docker compose up -d --build
```

Then open <http://localhost:8080>.

The first start takes a few minutes. The PHP container:

1. installs the PHP dependencies (`composer install`), which also downloads the JavaScript packages (`importmap:install`);
2. waits until MySQL accepts connections;
3. runs the database migrations;
4. loads the demo data, only if the database is empty.

Follow it with:

```bash
docker compose logs -f php
```

The app is ready when the log shows `ready to handle connections`.

## Demo accounts

All demo accounts use the password `rihla2026`.

| Account | What to try |
|---|---|
| `admin@rihla.ma` | Back-office at <http://localhost:8080/admin> |
| `karim@rihla.ma` | Driver: publish a trip, see the passengers per leg, cancel a trip |
| `salma@rihla.ma` | Passenger: upcoming, completed and cancelled bookings, reviews |

The demo trips are dated relative to the day the data is loaded, so there are always upcoming and completed trips.

## Running the tests

```bash
docker compose exec php composer test
```

The tests use a separate `rihla_test` database, created when the MySQL volume is first created. They never touch the demo data and call no external service: Nominatim and OSRM are replaced by fakes.

## Everyday commands

| Task | Command |
|---|---|
| Stop | `docker compose stop` |
| Start again | `docker compose up -d` |
| Reload the demo data (erases everything in the database) | `docker compose exec php bin/console doctrine:fixtures:load` |
| Start over from scratch (deletes the database volume) | `docker compose down -v` then `docker compose up -d --build` |
| Open a shell in the PHP container | `docker compose exec php sh` |
| See the logs | `docker compose logs -f` |

## Configuration

The defaults work as they are. To change them, put variables in a `.env` file next to `compose.yaml`, or in front of the command.

### Ports

| Variable | Default | Used for |
|---|---|---|
| `HTTP_PORT` | `8080` | The website |
| `MYSQL_PORT` | `3306` | MySQL from the host (for a database client) |

Example:

```bash
HTTP_PORT=8090 MYSQL_PORT=3307 docker compose up -d
```

### Other variables

| Variable | Default | Meaning |
|---|---|---|
| `APP_ENV` | `dev` | Symfony environment |
| `APP_SECRET` | a dev-only value | Symfony secret; set your own outside local development |
| `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD` | `rihla`, `rihla`, `rihla`, `root` | MySQL credentials, used only when the volume is first created |
| `LOAD_DEMO_DATA` | `1` | Set to `0` to start with an empty database |

## External services

The maps, address search and route data come from public OpenStreetMap services:

- map tiles: `tile.openstreetmap.org`, loaded by the browser;
- address search: Nominatim, called by the server;
- driving distance and time: OSRM (`router.project-osrm.org`), called by the server.

No account or API key is needed. The server identifies itself as `Rihla/1.0`, keeps Nominatim to one request per second, limits each user to 30 map requests per minute and caches the answers for 30 days.

If these services cannot be reached, the rest of the app keeps working: the map background stays grey, address search finds nothing and no price or time is suggested, but searching trips, booking and publishing still work. Routes are then drawn as straight dashed lines.

## Troubleshooting

**The Symfony debug toolbar is missing.** It is off in dev so it does not cover phone pages. Set `toolbar: true` in `config/packages/web_profiler.yaml` to see it; `/_profiler` stays available either way.

**Port already in use** (`Bind for 0.0.0.0:8080 failed`). Another program uses the port. Pick another one: `HTTP_PORT=8090 docker compose up -d`.

**The page shows 502 Bad Gateway.** The PHP container is still installing dependencies or waiting for MySQL. Wait and watch `docker compose logs -f php`.

**`The database is still unreachable after 60 seconds`.** MySQL did not start. Look at `docker compose logs database`. Two common causes:

- Docker is out of disk space (`No space left on device`): free space with `docker system df`, then remove unused images or volumes (`docker image prune`).
- The volume was created with other credentials: `docker compose down -v` then start again. This deletes the database.

**The tests fail with `Unknown database 'rihla_test'`.** The test database is created only when the MySQL volume is first created. Recreate the volume with `docker compose down -v && docker compose up -d --build`, or create it by hand:

```bash
docker compose exec database mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS rihla_test; GRANT ALL ON rihla_test.* TO 'rihla'@'%';"
```

**Pages have no style and maps do not appear.** The JavaScript packages were not downloaded (for example, no internet during the first start). Run:

```bash
docker compose exec php bin/console importmap:install
```

**On Windows: `rihla-entrypoint: not found` or `exec format error`.** Git converted the line endings of `docker/php/entrypoint.sh`. The repository forces LF for shell scripts through `.gitattributes`; if the clone predates it, run `git config core.autocrlf false`, delete the folder and clone again.

**No demo data.** Demo data is loaded only on an empty database and only in the `dev` environment. Load it by hand with `docker compose exec php bin/console doctrine:fixtures:load`.
