#!/bin/sh
# Start produkčního kontejneru: práva svazků, šifrovací klíč, migrace, index, plánované úlohy, Apache.
set -eu
cd /app

as_app() {
	setpriv --reuid=www-data --regid=www-data --init-groups "$@"
}

# Svazky (var = databáze, soubory, zálohy, relace; log) musí patřit webovému serveru
mkdir -p var/storage var/backups var/sessions log temp
find var log temp ! -user www-data -exec chown www-data:www-data {} +

# Šifrovací klíč pro API klíče v DB: z KONSEL_ENCRYPTION_KEY, jinak se jednou vygeneruje do svazku
if [ -z "${KONSEL_ENCRYPTION_KEY:-}" ] && [ ! -s var/encryption.key ]; then
	echo "Konšel: vytvářím šifrovací klíč var/encryption.key"
	as_app php -r 'file_put_contents("var/encryption.key", base64_encode(sodium_crypto_secretbox_keygen()) . "\n"); chmod("var/encryption.key", 0600);'
fi

echo "Konšel: migrace databáze"
konsel migrations:migrate --no-interaction --allow-no-migration

echo "Konšel: fulltextový index"
konsel search:reindex || echo "Konšel: přestavění indexu selhalo, hledání může být neúplné" >&2

# Plánované úlohy (místo systémového cronu) běží na pozadí jako www-data
as_app konsel-scheduler &

exec "$@"
