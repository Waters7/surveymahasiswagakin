#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."
php_binary="${PHP_BIN:-php}"
mode="${1:-update}"
if [[ "$mode" != "update" && "$mode" != "install" ]]; then
    echo 'Pemakaian: PHP_BIN=/path/php bash tools/deploy-cpanel.sh [install|update]'
    exit 1
fi
if [[ ! -f .env || ! -f vendor/autoload.php ]]; then
    echo 'Siapkan .env dan vendor terlebih dahulu. Lihat bagian cPanel pada README.md.'
    exit 1
fi

"$php_binary" artisan config:clear --no-interaction
"$php_binary" artisan gakin:deployment-check --skip-database --no-interaction
echo 'Pastikan database dan storage sudah dibackup sebelum menjalankan migrasi.'
"$php_binary" artisan down --no-interaction
"$php_binary" artisan migrate --force --no-interaction
if [[ "$mode" == "install" ]]; then
    "$php_binary" artisan db:seed --class=DatabaseSeeder --force --no-interaction
fi
"$php_binary" artisan config:cache --no-interaction
"$php_binary" artisan route:cache --no-interaction
"$php_binary" artisan view:cache --no-interaction
"$php_binary" artisan gakin:deployment-check --no-interaction
"$php_binary" artisan queue:restart --no-interaction
"$php_binary" artisan up --no-interaction
echo 'Pemasangan aplikasi selesai. Periksa login, PDF, upload PKH, SSL dan Cron Jobs di cPanel.'
echo 'Jika ada kegagalan setelah mode maintenance aktif, perbaiki lalu jalankan ulang; jangan hapus database.'
