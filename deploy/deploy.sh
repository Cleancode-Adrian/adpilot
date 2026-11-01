#!/usr/bin/env bash
# Aktualizacja AdPilot na serwerze (bez Dockera).
# Użycie (w katalogu projektu):  bash deploy/deploy.sh
set -euo pipefail

cd "$(dirname "$0")/.."

echo "→ Tryb serwisowy"
php artisan down --retry=30 || true

echo "→ Pobieranie zmian z GitHuba"
git pull --ff-only

echo "→ Zależności PHP"
composer install --no-dev --optimize-autoloader --no-interaction

echo "→ Budowanie frontendu"
npm ci --no-audit --no-fund
npm run build

echo "→ Migracje bazy"
php artisan migrate --force

echo "→ Cache konfiguracji"
php artisan storage:link 2>/dev/null || true
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "→ Restart workera kolejki (Supervisor podniesie go z nowym kodem)"
php artisan queue:restart

php artisan up
echo "✓ Gotowe"
