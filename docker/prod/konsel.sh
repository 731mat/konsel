#!/bin/sh
# bin/console vždy pod uživatelem www-data – soubory v temp/ a var/ musí patřit webovému serveru.
# Použití v konzoli kontejneru: konsel user:create jan@obec.cz "Jan Novák" --role admin -m obec
if [ "$(id -u)" = 0 ]; then
	exec setpriv --reuid=www-data --regid=www-data --init-groups php /app/bin/console "$@"
fi
exec php /app/bin/console "$@"
