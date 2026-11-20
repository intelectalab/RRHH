DOCKER_COMPOSE = docker compose

KIT_PSQL = $(DOCKER_COMPOSE) exec -T postgres psql -v ON_ERROR_STOP=1 -U ayni

.PHONY: kit-db kit-isolation kit-check backlog sitio up down restart ps logs api web site horizon api-install web-install site-install migrate seed db-reset test test-api test-web test-golden test-isolation test-e2e lint lint-api lint-web lint-site test-site contracts openapi

up:
	$(DOCKER_COMPOSE) up -d

down:
	$(DOCKER_COMPOSE) down

restart:
	$(DOCKER_COMPOSE) restart

ps:
	$(DOCKER_COMPOSE) ps

logs:
	$(DOCKER_COMPOSE) logs -f

api-install:
	$(DOCKER_COMPOSE) run --rm api composer install

web-install:
	$(DOCKER_COMPOSE) run --rm web npm install

site-install:
	$(DOCKER_COMPOSE) run --rm site npm install

migrate:
	$(DOCKER_COMPOSE) run --rm api php artisan migrate

seed:
	$(DOCKER_COMPOSE) run --rm api php artisan db:seed

db-reset:
	$(DOCKER_COMPOSE) run --rm api php artisan migrate:fresh --seed

api:
	$(DOCKER_COMPOSE) up api

web:
	$(DOCKER_COMPOSE) up web

# WEB-1 · sitio comercial gestiahr.com en :3001 (aplicación aparte)
site:
	$(DOCKER_COMPOSE) up site

horizon:
	$(DOCKER_COMPOSE) up horizon

test: test-api test-web test-site

test-api:
	$(DOCKER_COMPOSE) run --rm api ./vendor/bin/pest $(if $(F),--filter $(F),)

test-web:
	$(DOCKER_COMPOSE) run --rm web npm test

test-site:
	$(DOCKER_COMPOSE) run --rm site npm test

test-golden:
	$(DOCKER_COMPOSE) run --rm api ./vendor/bin/pest --group=golden

test-isolation:
	$(DOCKER_COMPOSE) exec -T postgres psql -U ayni -d aynihr -v ON_ERROR_STOP=1 -f /kit/database/pruebas_aislamiento.sql
	$(DOCKER_COMPOSE) run --rm api ./vendor/bin/pest tests/Feature/TenancyIsolationTest.php

test-e2e:
	$(DOCKER_COMPOSE) run --rm web npm run test:e2e

lint: lint-api lint-web lint-site

lint-api:
	$(DOCKER_COMPOSE) run --rm api ./vendor/bin/pint --test
	$(DOCKER_COMPOSE) run --rm api ./vendor/bin/phpstan analyse --memory-limit=2G

lint-web:
	$(DOCKER_COMPOSE) run --rm web npm run lint
	$(DOCKER_COMPOSE) run --rm web npx tsc --noEmit

lint-site:
	$(DOCKER_COMPOSE) run --rm site npm run lint
	$(DOCKER_COMPOSE) run --rm site npx tsc --noEmit

contracts:
	$(DOCKER_COMPOSE) run --rm web npm run contracts

openapi:
	docker run --rm -v $(CURDIR):/workspace -w /workspace python:3.12-slim sh -c "pip install --quiet pyyaml openapi-spec-validator && python3 kit/tools/gen_openapi.py"


# ---- Kit (kit/): esquema, aislamiento y generadores ---------------------------------------------

kit-db:
	$(KIT_PSQL) -d aynihr -c "DROP DATABASE IF EXISTS kit_check" -c "CREATE DATABASE kit_check"
	$(KIT_PSQL) -d kit_check -q -f /kit/database/schema.sql

kit-isolation: kit-db
	$(KIT_PSQL) -d kit_check -f /kit/database/pruebas_aislamiento.sql 2>&1 | tee /tmp/kit-aislamiento.log | tail -1
	@grep -q "TODAS LAS PRUEBAS PASARON" /tmp/kit-aislamiento.log

kit-check: kit-isolation openapi backlog
	cd kit/tools/esquema && python3 gen.py
	python3 kit/tools/gen_entrega.py
	git diff --exit-code -- kit/database kit/backlog kit/api kit/docs/20-entrega-al-equipo.md kit/prompts || \
	  (echo "Los archivos generados no coinciden con sus fuentes: ejecuta los generadores y confirma el cambio." && exit 1)

backlog:
	cd kit/tools && python3 generar_backlog.py && python3 gen_ruta.py

sitio:
	python3 kit/tools/sitio/build.py
