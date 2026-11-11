# GestiaHR · Makefile inicial (PLT-1, PLT-2 y PLT-3)
#
# Se copia a la raíz del repositorio. Las metas del kit ya funcionan; las de la aplicación avisan en qué
# historia se crean mientras no existan apps/api y apps/web. Los nombres son los que usa CLAUDE.md:
# no se inventan alias distintos.

SHELL := /bin/bash
COMPOSE := docker compose
PSQL := $(COMPOSE) exec -T postgres psql -v ON_ERROR_STOP=1 -U gestia_owner
F ?=

.PHONY: help up down kit-db kit-isolation kit-check openapi backlog sitio contracts \
        api-install web-install migrate seed api web horizon test test-api test-golden \
        test-isolation test-e2e lint

help:                 ## Lista las metas
	@grep -E '^[a-z-]+:.*##' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  %-16s %s\n", $$1, $$2}'

# ---- Servicios de apoyo -------------------------------------------------------------------------

up:                   ## PostgreSQL 17, Redis, S3 local y buzón de correo
	$(COMPOSE) up -d --wait

down:                 ## Detiene los servicios (los datos quedan en volúmenes)
	$(COMPOSE) down

# ---- Kit: esquema, pruebas de aislamiento y generadores ------------------------------------------

kit-db: up            ## Crea la base kit_check y carga kit/database/schema.sql
	$(PSQL) -d gestiahr -c "DROP DATABASE IF EXISTS kit_check" -c "CREATE DATABASE kit_check"
	$(PSQL) -d kit_check -q -f /kit/database/schema.sql

kit-isolation: kit-db ## Corre pruebas_aislamiento.sql sobre la base recién cargada
	$(PSQL) -d kit_check -f /kit/database/pruebas_aislamiento.sql 2>&1 | tee /tmp/gestia-aislamiento.log | tail -1
	@grep -q "TODAS LAS PRUEBAS PASARON" /tmp/gestia-aislamiento.log

kit-check: kit-isolation openapi backlog ## Todo lo del kit: esquema, aislamiento, OpenAPI y backlog
	cd kit/tools/esquema && python3 gen.py
	python3 kit/tools/gen_entrega.py
	git diff --exit-code -- kit/database kit/backlog kit/api kit/docs/20-entrega-al-equipo.md kit/prompts || \
	  (echo "Los archivos generados no coinciden con sus fuentes: ejecuta los generadores y confirma el cambio." && exit 1)

openapi:              ## Regenera y valida kit/api/openapi.yaml
	python3 kit/tools/gen_openapi.py

backlog:              ## Regenera el backlog (con su verificador) y la ruta del MVP
	cd kit/tools && python3 generar_backlog.py && python3 gen_ruta.py

sitio:                ## Regenera el sitio de referencia en kit/docs/web/sitio
	python3 kit/tools/sitio/build.py

# ---- Aplicación: se completan en las historias indicadas -----------------------------------------

define falta
	@if [ ! -d $(1) ]; then echo "Todavía no existe $(1): se crea en $(2) (kit/backlog/ruta-mvp.md)."; exit 1; fi
endef

api-install:          ## Dependencias de la API
	$(call falta,apps/api,PLT-1)
	cd apps/api && composer install

web-install:          ## Dependencias de la web
	$(call falta,apps/web,PLT-1)
	cd apps/web && npm ci

migrate:              ## Migraciones
	$(call falta,apps/api,PLT-1)
	cd apps/api && php artisan migrate

seed:                 ## Organización demo coherente
	$(call falta,apps/api,PLT-1)
	cd apps/api && php artisan db:seed

api:                  ## API en :8000
	$(call falta,apps/api,PLT-1)
	cd apps/api && php artisan serve --port=8000

web:                  ## Web en :3000
	$(call falta,apps/web,PLT-1)
	cd apps/web && npm run dev

horizon:              ## Colas
	$(call falta,apps/api,PLT-6)
	cd apps/api && php artisan horizon

test: test-api        ## Todas las pruebas (Pest + Vitest)
	$(call falta,apps/web,PLT-1)
	cd apps/web && npm test

test-api:             ## Pruebas de backend; una sola con F=NombreDeTest
	$(call falta,apps/api,PLT-1)
	cd apps/api && ./vendor/bin/pest $(if $(F),--filter=$(F),)

test-golden:          ## Pruebas doradas de planilla
	$(call falta,apps/api/packages/country-pe,NOM-1)
	cd apps/api && ./vendor/bin/pest packages/country-pe/tests/golden

test-isolation:       ## Aislamiento entre organizaciones sobre todas las rutas
	$(call falta,apps/api/tests/Isolation,TEN-3)
	cd apps/api && ./vendor/bin/pest tests/Isolation

test-e2e:             ## Playwright
	$(call falta,apps/web/e2e,QA-1)
	cd apps/web && npx playwright test

lint:                 ## Pint + PHPStan (Larastan) + ESLint + tsc
	$(call falta,apps/api,PLT-1)
	cd apps/api && ./vendor/bin/pint --test && ./vendor/bin/phpstan analyse
	cd apps/web && npm run lint && npx tsc --noEmit

contracts:            ## Regenera packages/contracts desde kit/api/openapi.yaml
	$(call falta,packages/contracts,PLT-5)
	cd packages/contracts && npm run generate
