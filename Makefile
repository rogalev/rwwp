.PHONY: build clean console php test test-fetch

build:
	docker compose build php

clean:
	docker compose down --remove-orphans

console:
	docker compose run --rm php php bin/console $(cmd)

php:
	docker compose run --rm php php $(cmd)

test:
	docker compose run --rm php php bin/phpunit

test-fetch:
	docker compose run --rm php php bin/console parser:fetch https://example.com
