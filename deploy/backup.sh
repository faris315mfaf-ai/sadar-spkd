#!/usr/bin/env bash
# One archive with everything needed to move SADAR-SPKD to another server:
# database dump, uploaded files (profile photos, attendance selfies, doctor notes),
# the WhatsApp session and the .env file.
#
#   deploy/backup.sh [output-dir]      (default: ../backups, next to the app folder)
#
# The archive contains secrets (.env), so it is created with mode 600.
set -euo pipefail

cd "$(dirname "$0")/.."
compose="docker compose -f docker-compose.prod.yml"
stamp="$(date +%Y%m%d-%H%M)"
out_dir="${1:-../backups}"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

mkdir -p "$out_dir"

$compose exec -T sadar-db sh -c \
  'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --no-tablespaces "$MYSQL_DATABASE"' \
  > "$tmp/database.sql"
tar -C storage -cf "$tmp/storage-app.tar" app
$compose exec -T sadar-bot tar -C /app/auth -cf - . > "$tmp/wa-auth.tar"
cp .env "$tmp/env"

archive="$out_dir/sadar-spkd-$stamp.tar.gz"
(umask 077 && tar -C "$tmp" -czf "$archive" .)

echo "Backup selesai: $archive ($(du -h "$archive" | cut -f1))"
