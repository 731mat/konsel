#!/bin/sh
# Plánované úlohy produkčního kontejneru (náhrada cronu), každých 10 minut:
# - úklid vypršelých zámků bodů
# - záloha, pokud je podle plánu v Nastavení → Zálohy na řadě (zmeškaný termín se dožene)
while :; do
	sleep 600
	php /app/bin/console locks:cleanup > /dev/null || echo "Konšel: locks:cleanup selhal" >&2
	php /app/bin/console backup:run --scheduled | grep -v 'není podle plánu na řadě' || true
done
