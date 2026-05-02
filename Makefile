.PHONY: build clean console console-list parser-list php production-run status test test-fetch

build:
	docker compose build php

clean:
	docker compose down --remove-orphans

console:
	docker compose run --rm php php bin/console $(cmd)

console-list:
	docker compose run --rm php php bin/console list

parser-list:
	docker compose run --rm php php bin/console list parser

php:
	docker compose run --rm php php $(cmd)

production-run:
	docker compose run --rm php php bin/console parser:production:run-once

status:
	docker compose run --rm php php bin/console parser:status:show

test:
	docker compose run --rm php php bin/phpunit

test-fetch:
	docker compose run --rm php php bin/console parser:fetch https://example.com
