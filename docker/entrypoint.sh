#!/bin/sh
set -e

echo "==> Starting application container initialization..."

# Wait for MySQL if configured
if [ "$DB_CONNECTION" = "mysql" ]; then
    echo "==> Waiting for MySQL database at $DB_HOST:$DB_PORT..."
    until php -r "try { new PDO('mysql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT') . ';dbname=' . getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD')); exit(0); } catch (Exception \$e) { exit(1); }"; do
        echo "    MySQL is unavailable - sleeping 2s..."
        sleep 2
    done
    echo "==> MySQL is up and accepting connections!"
fi

# Wait for Redis if configured
if [ -n "$REDIS_HOST" ]; then
    echo "==> Waiting for Redis at $REDIS_HOST:$REDIS_PORT..."
    until php -r "try { \$r = new Redis(); \$r->connect(getenv('REDIS_HOST'), (int)(getenv('REDIS_PORT') ?: 6379), 1); exit(\$r->ping() ? 0 : 1); } catch (Exception \$e) { exit(1); }"; do
        echo "    Redis is unavailable - sleeping 2s..."
        sleep 2
    done
    echo "==> Redis is up and reachable!"
fi

# Ensure storage and bootstrap directories are writable
echo "==> Ensuring directory permissions..."
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# Run database migrations
echo "==> Running database migrations..."
php artisan migrate --force --no-interaction

# Cache configuration in production
if [ "$APP_ENV" = "production" ]; then
    echo "==> Optimizing configuration and routes for production..."
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

echo "==> Initialization complete. Launching application process: $@"
exec "$@"
