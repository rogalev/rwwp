.PHONY: build clean console console-list daemon-logs daemon-restart daemon-start daemon-stop diagnostic-clear diagnostic-tail parser-list php production-run self-check status test test-fetch

DAEMON_NAME ?= russiaww-parser-daemon
interval ?= 30
limit ?= 1

build:
	docker compose build php

clean:
	docker compose down --remove-orphans

daemon-start:
	docker compose run -d --name $(DAEMON_NAME) php sh -lc 'while true; do php bin/console parser:production:run-once --limit-per-assignment=$(limit) --image-limit=10; sleep $(interval); done'

daemon-logs:
	docker logs -f $(DAEMON_NAME)

daemon-stop:
	-docker stop $(DAEMON_NAME)
	-docker rm $(DAEMON_NAME)

daemon-restart: daemon-stop daemon-start

diagnostic-tail:
	tail -f var/log/parser-diagnostic.ndjson

diagnostic-clear:
	rm -f var/log/parser-diagnostic.ndjson

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

self-check:
	docker compose run --rm php php bin/console parser:self-check

status:
	docker compose run --rm php php bin/console parser:status:show

test:
	docker compose run --rm php php bin/phpunit

test-fetch:
	docker compose run --rm php php bin/console parser:fetch https://example.com
