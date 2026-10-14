#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."
php_binary="${PHP_BIN:-php}"
mode="${1:-update}"
if [[ "$mode" != "update" && "$mode" != "install" && "$mode" != "pull" ]]; then
    echo 'Pemakaian: PHP_BIN=/path/php bash tools/deploy-cpanel.sh [install|update|pull]'
    exit 1
fi
if [[ ! -f .env || ! -f vendor/autoload.php ]]; then
    echo 'Siapkan .env dan vendor terlebih dahulu. Lihat bagian cPanel pada README.md.'
    exit 1
fi

if [[ "$mode" == "pull" ]]; then
    if [[ ! -d .git ]]; then
        echo 'Folder ini belum terhubung ke GitHub. Ikuti langkah "Update dari GitHub" pada README.md.'
        exit 1
    fi
    if ! git diff --quiet || ! git diff --cached --quiet; then
        echo 'Ada perubahan file kode langsung di server (lihat: git status). Batalkan dahulu agar tidak tertimpa.'
        exit 1
    fi
    before="$(git rev-parse HEAD)"
    git fetch --quiet origin main
    git merge --ff-only origin/main
    if ! git diff --quiet "$before" HEAD -- composer.lock; then
        echo 'composer.lock berubah; memasang dependensi produksi...'
        "${COMPOSER_BIN:-composer}" install --no-dev --optimize-autoloader --no-interaction --no-progress
    fi
    echo "Versi kode: $(git log -1 --format='%h %s')"
    mode=update
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
