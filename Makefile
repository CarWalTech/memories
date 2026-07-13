all: dev-setup lint build-js-production test

# Dev env management
dev-setup: clean clean-dev npm-init bin-ext install-tools

bin-ext:
	sh scripts/get-bin-ext.sh

install-tools:
	composer install

php-lint:
	vendor/bin/php-cs-fixer fix

psalm:
	vendor/bin/psalm --no-cache --show-info=true

npm-init:
	npm ci

npm-update:
	npm update

.PHONY: dev-setup bin-ext install-tools php-lint psalm npm-init npm-update

# Building
build-js:
	npm run dev

build-js-production:
	rm -f js/* && npm run build

patch-external:
	bash scripts/patch-external.sh

watch-js:
	npm run watch

# Same as watch-js, but after every rebuild forces Nextcloud to serve the
# freshly built JS instead of a browser-cached copy. Nextcloud appends
# `?v=<app-version>-<theming-cachebuster>` to every <script> URL; the main
# entry filenames (memories-main.js etc.) don't change between builds, so
# without bumping something the browser just keeps the old cached file.
#
# We used to bump the DEV version in appinfo/info.xml for this, but that also
# flips Nextcloud's global needUpgrade() check (it compares every enabled
# app's on-disk version against the DB), which then requires a full
# `occ upgrade` to clear -- and running that on every save re-triggers app
# repair/migration steps, which was corrupting the theming cache. Bumping
# theming's own `cachebuster` config value produces the same cache-busting
# URL suffix without any of that: it's a plain counter, no migrations
# attached, and takes effect immediately.
watch-js-dev:
	npm run watch 2>&1 | while IFS= read -r line; do \
		echo "$$line"; \
		case "$$line" in *compiled*) \
			docker exec $(NEXTCLOUD_CONTAINER) php occ config:app:set theming cachebuster --value="$$(date +%s)" ;; \
		esac; \
	done

.PHONY: build-js patch-external watch-js watch-js-dev

# Testing
test:
	npm run test

test-watch:
	npm run test:watch

test-coverage:
	npm run test:coverage

.PHONY: test test-watch test-coverage

# Linting
lint:
	npm run lint

lint-fix:
	npm run lint:fix

.PHONY: lint lint-fix

# Cleaning
clean:
	rm -f js/*

clean-dev:
	rm -rf node_modules

.PHONY: clean clean-dev

# Deploying to a local dev instance
NEXTCLOUD_CONTAINER ?= nextcloud-app-1

bump-dev-version:
	@perl -pi -e 's/(<version>\d+\.\d+\.\d+-DEV-)(\d+)(<\/version>)/$$1.($$2+1).$$3/e' appinfo/info.xml
	@echo "Bumped version to $$(grep -oP '(?<=<version>).*(?=</version>)' appinfo/info.xml)"

bump-and-upgrade: bump-dev-version
	docker exec $(NEXTCLOUD_CONTAINER) php occ upgrade

deploy-dev: bump-dev-version
	$(MAKE) build-js
	docker exec $(NEXTCLOUD_CONTAINER) php occ upgrade

.PHONY: bump-dev-version deploy-dev
