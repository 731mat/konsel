# Konšel – vše běží v Dockeru. Na hostiteli není potřeba PHP, Composer ani Node.
export UID := $(shell id -u)
export GID := $(shell id -g)

DC   = docker compose
EXEC = $(DC) exec -u www-data app

.DEFAULT_GOAL := help
.PHONY: help up down build rebuild sh logs composer console npm assets qa test stan cs cs-fix db-reset screenshots

help: ## Vypíše dostupné příkazy
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

up: ## Nastartuje kontejnery (http://localhost:8080)
	$(DC) up -d

down: ## Zastaví kontejnery
	$(DC) down

build: ## Sestaví image
	$(DC) build

rebuild: ## Sestaví image bez cache a restartuje
	$(DC) build --no-cache && $(DC) up -d --force-recreate

sh: ## Shell v app kontejneru
	$(EXEC) bash

logs: ## Logy kontejnerů
	$(DC) logs -f

composer: ## Composer, např. make composer ARGS="require foo/bar"
	$(EXEC) composer $(ARGS)

console: ## bin/console, např. make console ARGS="migrations:migrate"
	$(EXEC) php bin/console $(ARGS)

npm: ## npm v node kontejneru, např. make npm ARGS="install bootstrap"
	$(DC) run --rm --no-deps node npm $(ARGS)

assets: ## Jednorázový build assetů (www/assets)
	$(DC) run --rm --no-deps node sh -c "npm install --no-audit --no-fund && npm run build"

qa: ## Coding standard + PHPStan + testy
	$(EXEC) composer qa

test: ## Testy (Nette Tester)
	$(EXEC) composer test

stan: ## PHPStan
	$(EXEC) composer stan

cs: ## Kontrola coding standardu
	$(EXEC) composer cs

cs-fix: ## Oprava coding standardu
	$(EXEC) composer cs-fix

db-reset: ## Smaže DB, spustí migrace a nahraje demo data
	$(EXEC) composer db:reset

screenshots: ## Screenshoty stránek (desktop + mobil) do temp/screenshots, např. PAGES="/admin,/admin/meeting"
	@mkdir -p temp/screenshots
	$(DC) run --rm -e PAGES="$(PAGES)" screenshots
