DOCKER_COMPOSE = docker compose

.PHONY: up down restart ps logs api web horizon api-install web-install migrate seed db-reset test test-api test-web test-golden test-isolation test-e2e lint lint-api lint-web contracts openapi

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

horizon:
	$(DOCKER_COMPOSE) up horizon

test: test-api test-web

test-api:
	$(DOCKER_COMPOSE) run --rm api ./vendor/bin/pest $(if $(F),--filter $(F),)

test-web:
	$(DOCKER_COMPOSE) run --rm web npm test

test-golden:
	$(DOCKER_COMPOSE) run --rm api ./vendor/bin/pest --group=golden

test-isolation:
	$(DOCKER_COMPOSE) run --rm api ./vendor/bin/pest --group=isolation

test-e2e:
	$(DOCKER_COMPOSE) run --rm web npm run test:e2e

lint: lint-api lint-web

lint-api:
	$(DOCKER_COMPOSE) run --rm api ./vendor/bin/pint --test
	$(DOCKER_COMPOSE) run --rm api ./vendor/bin/phpstan analyse --memory-limit=2G

lint-web:
	$(DOCKER_COMPOSE) run --rm web npm run lint
	$(DOCKER_COMPOSE) run --rm web npx tsc --noEmit

contracts:
	$(DOCKER_COMPOSE) run --rm web npm run contracts

openapi:
	docker run --rm -v $(CURDIR):/workspace -w /workspace python:3.12-slim sh -c "pip install --quiet pyyaml openapi-spec-validator && python3 tools/gen_openapi.py"

