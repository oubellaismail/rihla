#!/bin/sh
set -e

# Install dependencies on the first start (the project folder is mounted from the host).
if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist
fi

# Wait until MySQL accepts connections, then bring the schema up to date.
attempts=0
until php bin/console dbal:run-sql "SELECT 1" > /dev/null 2>&1; do
    attempts=$((attempts + 1))
    if [ "$attempts" -ge 30 ]; then
        echo "The database is still unreachable after 60 seconds. Last error:"
        php bin/console dbal:run-sql "SELECT 1" || true
        exit 1
    fi
    echo "Waiting for the database..."
    sleep 2
done

php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

exec "$@"
