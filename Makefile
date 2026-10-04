# make lint     PHP syntax check (inside the Nextcloud container if there is no local php)
# make dist     release tarball for manual install or the app store: build/immich_search-<version>.tar.gz
# make deploy   install into the local Nextcloud container (see deploy.sh)

APP     := immich_search
VERSION := $(shell sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml)
FILES   := appinfo css img js l10n lib templates LICENSE README.md
PHP     ?= $(shell command -v php || echo "docker exec -i $${CONTAINER:-nextcloud-aio-nextcloud} php")

.PHONY: lint dist deploy clean

lint:
	@for f in $$(find lib appinfo templates -name '*.php'); do \
		$(PHP) -l < "$$f" >/dev/null || { echo "syntax error: $$f"; exit 1; }; done
	@for f in l10n/*.json; do python3 -m json.tool "$$f" >/dev/null || { echo "bad json: $$f"; exit 1; }; done
	@echo "lint ok"

dist: lint
	@mkdir -p build
	@tar --transform 's,^,$(APP)/,' -czf build/$(APP)-$(VERSION).tar.gz $(FILES)
	@echo build/$(APP)-$(VERSION).tar.gz

deploy: lint
	@./deploy.sh

clean:
	rm -rf build
