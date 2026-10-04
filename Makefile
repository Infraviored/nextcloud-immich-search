# make build    frontend (Vue) into js/
# make lint     PHP syntax check (inside the Nextcloud container if there is no local php)
# make test     PHP unit tests (in a php container if there is no local php) and JS tests
# make dist     release tarball for manual install or the app store: build/immich_search-<version>.tar.gz
# make deploy   install into the local Nextcloud container (see deploy.sh)

APP     := immich_search
VERSION := $(shell sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml)
FILES   := appinfo img js l10n lib templates LICENSE README.md
PHP     ?= $(shell command -v php || echo "docker exec -i $${CONTAINER:-nextcloud-aio-nextcloud} php")

.PHONY: build lint test dist deploy clean

node_modules: package.json
	npm install --no-audit --no-fund
	@touch node_modules

build: node_modules
	npm run build

lint:
	@for f in $$(find lib appinfo templates -name '*.php'); do \
		$(PHP) -l < "$$f" >/dev/null || { echo "syntax error: $$f"; exit 1; }; done
	@for f in l10n/*.json; do python3 -m json.tool "$$f" >/dev/null || { echo "bad json: $$f"; exit 1; }; done
	@echo "lint ok"

test: node_modules
	@if command -v php >/dev/null; then composer install -q && composer test; \
	else docker run --rm -u $$(id -u):$$(id -g) -v "$$PWD":/app -w /app php:8.3-cli vendor/bin/phpunit -c tests/phpunit.xml; fi
	npm test

dist: lint build
	@mkdir -p build
	@tar --exclude='*.map' --transform 's,^,$(APP)/,' -czf build/$(APP)-$(VERSION).tar.gz $(FILES)
	@echo build/$(APP)-$(VERSION).tar.gz

deploy: lint
	@./deploy.sh

clean:
	rm -rf build js
