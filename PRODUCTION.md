# Production deployment with Docker

Run these commands from the repository root on the server.

```bash
cp app/.env.example app/.env
vi app/.env
```

Set `APP_ENV=production`, `APP_DEBUG=false`, `CACHE_STORE=redis`,
`SESSION_DRIVER=file`, `REDIS_HOST=redis`, and the exchange credentials in
`app/.env`. Keep this file on the server and never commit it.

The production Compose file publishes the application Nginx only on
`127.0.0.1:8080`. Put the public domain in the existing host Nginx and proxy
that virtual host to `http://127.0.0.1:8080` so other sites can keep using
ports 80 and 443.

Build and initialize Laravel:

```bash
docker compose -f docker-compose.prod.yml build
docker compose -f docker-compose.prod.yml run --rm php-cli composer install --no-dev --optimize-autoloader
docker compose -f docker-compose.prod.yml run --rm php-cli php artisan key:generate
docker compose -f docker-compose.prod.yml run --rm php-cli php artisan storage:link
docker compose -f docker-compose.prod.yml run --rm php-cli php artisan config:cache
```

Start the web application and market-data worker first:

```bash
docker compose -f docker-compose.prod.yml up -d nginx php-fpm redis market-data
docker compose -f docker-compose.prod.yml logs -f market-data
```

Check balances and signals before enabling live orders:

```bash
docker compose -f docker-compose.prod.yml run --rm php-cli php artisan market:refresh-balances
docker compose -f docker-compose.prod.yml run --rm php-cli php artisan market:calculate-spreads --once
```

The calculator starts in signal-only mode by default. Enable live execution
only after the checks above:

```bash
LUMA_EXECUTE_FLAG=--execute docker compose -f docker-compose.prod.yml up -d spread-calculator
```

For automatic restart after a server reboot, enable Docker and use:

```bash
sudo systemctl enable --now docker
docker compose -f docker-compose.prod.yml up -d
```

Useful logs:

```bash
docker compose -f docker-compose.prod.yml logs -f spread-calculator
tail -f app/storage/logs/profitable-trades-$(date +%Y-%m-%d).log
```
