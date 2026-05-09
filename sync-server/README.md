# MEtR Sync Backend

Laravel 11 sync backend for MEtR desktop app.

## Structure

- `backend/` — Laravel 11 application
- `nginx/` — Nginx configuration
- `scripts/` — Deployment and health-check scripts
- `docker-compose.yml` — Docker Compose runtime

## Local Development

```bash
cd sync-server
cp .env.example .env
cp backend/.env.example backend/.env
docker compose up -d
docker exec -it metr-sync-php sh -lc 'cd /var/www/html && composer install'
docker exec -it metr-sync-php sh -lc 'cd /var/www/html && php artisan migrate --force'
docker exec -it metr-sync-php sh -lc 'cd /var/www/html && php artisan db:seed --force'
```

## Running Tests

With local PHP:

```bash
cd sync-server/backend
composer install
vendor/bin/phpunit
```

With Docker only:

```bash
cd sync-server
docker compose --env-file .env.example run --rm \
  -e APP_ENV=testing \
  -e DB_CONNECTION=sqlite \
  -e DB_DATABASE=':memory:' \
  -e CACHE_STORE=array \
  -e SESSION_DRIVER=array \
  -e QUEUE_CONNECTION=sync \
  php sh -lc 'cd /var/www/html && vendor/bin/phpunit'

docker compose --env-file .env.example run --rm \
  -e APP_ENV=testing \
  php sh -lc 'cd /var/www/html && vendor/bin/pint --test'
```

## Deployment

See `docs/sync_backend_laravel_implementation_and_deployment.md` in the repo root.
