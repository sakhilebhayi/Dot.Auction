#!/bin/sh
# Signed-in smoke check with the production caches on (R-42).
#
#   sh tests/Support/signed-in-smoke.sh
#
# Builds a throwaway SQLite database, runs `php artisan route:cache && config:cache && event:cache` (what every deploy
# does), opens every authenticated page as the right person and expects 200, then removes the caches again. It never
# touches your real database: DB_* and the session/cache/queue/mail drivers are forced for this run only, and the cached
# config is deleted on exit. APP_ENV=production, so APP_URL's host is the only Host the app answers for (trustHosts).
set -u
cd "$(dirname "$0")/../.."

WORK=$(mktemp -d)
export APP_ENV=production APP_DEBUG=false APP_URL=https://auction.smoke.test TRUSTED_HOSTS=
export DB_CONNECTION=sqlite DB_DATABASE="$WORK/smoke.sqlite" DB_URL=
export SESSION_DRIVER=array CACHE_STORE=array QUEUE_CONNECTION=sync MAIL_MAILER=array BROADCAST_CONNECTION=log
export FILESYSTEM_DISK=local LOG_CHANNEL=stderr LOG_LEVEL=error
export NOTIFY_INGEST_URL= NOTIFY_INGEST_SECRET= PAYSTACK_SECRET_KEY= PAYSTACK_PUBLIC_KEY=
touch "$DB_DATABASE"

cleanup() {
    php artisan optimize:clear >/dev/null 2>&1
    rm -rf "$WORK" storage/app/private/auction-images/smoke
}
trap cleanup EXIT INT TERM

php artisan optimize:clear >/dev/null 2>&1 || exit 1
php artisan migrate --force >/dev/null || exit 1
php artisan route:cache && php artisan config:cache && php artisan event:cache || exit 1
php tests/Support/signed-in-smoke.php
