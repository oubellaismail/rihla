#!/bin/sh
set -e

# Install dependencies on the first start (the project folder is mounted from the host).
if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist
fi

# Wait until MySQL accepts connections, then bring the schema up to date.
until php bin/console dbal:run-sql "SELECT 1" > /dev/null 2>&1; do
    echo "Waiting for the database..."
    sleep 2
done

php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

exec "$@"
