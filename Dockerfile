#syntax=docker/dockerfile:1

# Versions: tag plus digest, so a moved upstream tag never gets into a signed release (Dependabot updates both).
FROM dunglas/frankenphp:1-php8.5@sha256:b8e0919047838ea32771ef573d74df9a9128de1e908a999366e26c11bc6e7ba4 AS frankenphp_upstream

# The different stages of this Dockerfile are meant to be built into separate images
# https://docs.docker.com/build/building/multi-stage/#stop-at-a-specific-build-stage
# https://docs.docker.com/reference/compose-file/build/#target


# Base FrankenPHP image
FROM frankenphp_upstream AS frankenphp_base

SHELL ["/bin/bash", "-euxo", "pipefail", "-c"]

WORKDIR /app

# persistent deps
# hadolint ignore=DL3008
RUN <<-EOF
	apt-get update
	apt-get install -y --no-install-recommends \
		cron \
		file \
		git
	install-php-extensions \
		@composer \
		apcu \
		intl \
		opcache \
		zip
	rm -rf /var/lib/apt/lists/*
EOF

# https://getcomposer.org/doc/03-cli.md#composer-allow-superuser
ENV COMPOSER_ALLOW_SUPERUSER=1

ENV PHP_INI_SCAN_DIR=":$PHP_INI_DIR/app.conf.d"

###> recipes ###
###> doctrine/doctrine-bundle ###
RUN install-php-extensions pdo_pgsql
###< doctrine/doctrine-bundle ###
###< recipes ###

COPY --link frankenphp/conf.d/10-app.ini $PHP_INI_DIR/app.conf.d/
COPY --link --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
COPY --link frankenphp/Caddyfile /etc/frankenphp/Caddyfile

# The scheduled commands (the `cron` service of Compose runs them)
COPY --link frankenphp/crontab /etc/depverify/crontab
COPY --link --chmod=755 frankenphp/docker-cron.sh /usr/local/bin/docker-cron
RUN crontab -u www-data /etc/depverify/crontab

ENTRYPOINT ["docker-entrypoint"]

HEALTHCHECK --start-period=60s CMD ["php", "-r", "exit(false === @file_get_contents('http://localhost:2019/metrics', context: stream_context_create(['http' => ['timeout' => 5]])) ? 1 : 0);"]
CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile" ]

# Dev FrankenPHP image
FROM frankenphp_base AS frankenphp_dev

# Repeated until hadolint inherits the parent stage SHELL: https://github.com/hadolint/hadolint/pull/1258
SHELL ["/bin/bash", "-euxo", "pipefail", "-c"]

ENV APP_ENV=dev
ENV XDEBUG_MODE=off
ENV FRANKENPHP_WORKER_CONFIG=watch

# dev dependencies
RUN <<-EOF
	mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
	install-php-extensions xdebug
	useradd -m -s /bin/bash nonroot
	git config --system --add safe.directory /app
EOF

# Symfony Language Server, for editors and AI agents that don't bundle it themselves
# https://github.com/symfony/language-tools
# Pinned (not "latest") and checked against the release's SHA256SUMS. Editor tooling only: when GitHub
# release downloads fail (they sometimes answer 5xx), the build warns and goes on without it.
ARG TARGETARCH
ARG SYMFONY_LSP_VERSION=v0.24.2
RUN <<-EOF
	case "$TARGETARCH" in
		amd64) lsp_arch=x64 ;;
		arm64) lsp_arch=arm64 ;;
		*) echo "unsupported architecture: $TARGETARCH"; exit 1 ;;
	esac
	lsp_base="https://github.com/symfony/language-tools/releases/download/$SYMFONY_LSP_VERSION"
	lsp_file="symfony-lsp-$SYMFONY_LSP_VERSION-linux-$lsp_arch.tar.gz"
	cd /tmp || exit 1
	# Two separate calls: with several -O, curl's exit code only reflects the last transfer.
	if curl -fsSL --retry 3 --retry-all-errors --retry-delay 5 -o "$lsp_file" "$lsp_base/$lsp_file" \
		&& curl -fsSL --retry 3 --retry-all-errors --retry-delay 5 -o SHA256SUMS "$lsp_base/SHA256SUMS"; then
		grep " $lsp_file\$" SHA256SUMS | sha256sum -c -
		tar -xzf "$lsp_file" -C /usr/local/bin --strip-components=1 --wildcards '*/symfony-lsp'
	else
		echo "warning: symfony-lsp $SYMFONY_LSP_VERSION could not be downloaded, skipped (editor tooling only)" >&2
	fi
	rm -f "/tmp/$lsp_file" /tmp/SHA256SUMS
EOF

COPY --link frankenphp/conf.d/20-app.dev.ini $PHP_INI_DIR/app.conf.d/

CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--watch" ]

# Prod PHP dependencies, in their own stage: the front assets need the Symfony UX packages of vendor/
FROM frankenphp_base AS frankenphp_prod_vendor

# Repeated until hadolint inherits the parent stage SHELL: https://github.com/hadolint/hadolint/pull/1258
SHELL ["/bin/bash", "-euxo", "pipefail", "-c"]

ENV APP_ENV=prod

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --link frankenphp/conf.d/20-app.prod.ini $PHP_INI_DIR/app.conf.d/

# prevent the reinstallation of vendors at every changes in the source code
COPY --link composer.* symfony.* ./
RUN composer install --no-cache --prefer-dist --no-dev --no-autoloader --no-scripts --no-progress

# Front assets (Vite + Tailwind) of the prod image: public/build is git-ignored, so it is built here, never taken from
# the build context (.dockerignore). package.json links the Symfony UX packages from vendor/ (file:vendor/symfony/…),
# and Tailwind scans templates/ for its classes. Same Node as the dev `node` service (compose.override.yaml). Built once
# on the platform of the builder: the assets do not depend on the architecture, so a multi-arch build never runs yarn
# under emulation.
FROM --platform=$BUILDPLATFORM node:24-alpine@sha256:116b800c3754b440ee0aa3ed2f20f5bf3d99a554bfd9adeea09ea8017f6e85f5 AS frankenphp_prod_assets

WORKDIR /app

ENV COREPACK_ENABLE_DOWNLOAD_PROMPT=0

COPY --link package.json yarn.lock .yarnrc.yml ./
COPY --link --from=frankenphp_prod_vendor /app/vendor/symfony vendor/symfony
RUN corepack enable && yarn install --immutable

COPY --link vite.config.ts tsconfig.json ./
COPY --link assets assets
COPY --link templates templates
RUN yarn build

# Builder for the prod FrankenPHP image
FROM frankenphp_prod_vendor AS frankenphp_prod_builder

# Repeated until hadolint inherits the parent stage SHELL: https://github.com/hadolint/hadolint/pull/1258
SHELL ["/bin/bash", "-euxo", "pipefail", "-c"]

# copy sources
COPY --link --exclude=frankenphp/ . ./

# Before the cache warmup below: Reprise caches public/build/entrypoints.json in it (prod `cache: true`), and an
# image warmed without it would render pages without any stylesheet or script.
COPY --link --from=frankenphp_prod_assets /app/public/build public/build

RUN <<-EOF
	mkdir -p var/cache var/log var/share
	composer dump-autoload --classmap-authoritative --no-dev
	composer dump-env prod
	composer run-script --no-dev post-install-cmd
	if [ -f importmap.php ]; then
		php bin/console asset-map:compile
	fi
	chmod +x bin/console
	chmod -R g=u var
	sync
EOF

# Collect shared libraries needed by FrankenPHP and PHP extensions
# hadolint ignore=DL3008,SC3054
RUN <<-'EOF'
	apt-get update
	apt-get install -y --no-install-recommends libtree
	mkdir -p /tmp/libs
	BINARIES=(frankenphp php file)
	for target in $(printf '%s\n' "${BINARIES[@]}" | xargs -I{} which {}) \
		$(find "$(php -r 'echo ini_get("extension_dir");')" -maxdepth 2 -name "*.so"); do
		libtree -pv "$target" 2>/dev/null | grep -oP '(?:── )\K/\S+(?= \[)' | while IFS= read -r lib; do
			[ -f "$lib" ] && cp -n "$lib" /tmp/libs/
		done
	done
	rm -rf /var/lib/apt/lists/*
EOF

# Prod FrankenPHP image, published as ghcr.io/dependencyverifier/console (.github/workflows/release.yml)
FROM debian:13-slim@sha256:8aa5a7bf5a1955bddbe7d74fcdd0b91ab30e74362a8b73af672b0481b35b44a0 AS frankenphp_prod

LABEL org.opencontainers.image.source="https://github.com/DependencyVerifier/console"

SHELL ["/bin/bash", "-euxo", "pipefail", "-c"]

ENV APP_ENV=prod
ENV PHP_INI_SCAN_DIR=":/usr/local/etc/php/app.conf.d"

COPY --from=frankenphp_prod_builder /usr/local/bin/frankenphp /usr/local/bin/frankenphp
COPY --from=frankenphp_prod_builder /usr/local/bin/php /usr/local/bin/php
COPY --from=frankenphp_prod_builder /usr/local/bin/docker-php-entrypoint /usr/local/bin/docker-php-entrypoint
COPY --from=frankenphp_prod_builder /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=frankenphp_prod_builder /tmp/libs /usr/lib

COPY --from=frankenphp_prod_builder /usr/local/etc/php/conf.d /usr/local/etc/php/conf.d
COPY --from=frankenphp_prod_builder /usr/local/etc/php/php.ini /usr/local/etc/php/php.ini
COPY --from=frankenphp_prod_builder /usr/local/etc/php/app.conf.d /usr/local/etc/php/app.conf.d

COPY --from=frankenphp_prod_builder /etc/frankenphp/Caddyfile /etc/frankenphp/Caddyfile

# CA certificates for TLS, file/libmagic for Symfony MIME type detection
COPY --from=frankenphp_prod_builder /etc/ssl/certs/ca-certificates.crt /etc/ssl/certs/ca-certificates.crt
COPY --from=frankenphp_prod_builder /etc/ssl/openssl.cnf /etc/ssl/openssl.cnf
COPY --from=frankenphp_prod_builder /usr/bin/file /usr/bin/file
COPY --from=frankenphp_prod_builder /usr/lib/file/magic.mgc /usr/lib/file/magic.mgc

ENV  OPENSSL_CONF=/etc/ssl/openssl.cnf XDG_CONFIG_HOME=/config XDG_DATA_HOME=/data SSL_CERT_FILE=/etc/ssl/certs/ca-certificates.crt

# The frankenphp binary carries cap_net_bind_service (ports 80/443 as www-data): OpenSSL then runs in secure mode and
# ignores SSL_CERT_FILE and OPENSSL_CONF, falling back to its compiled-in /usr/lib/ssl, absent from this slim image.
# Without these links every TLS connection made by the web workers (SMTP STARTTLS of the emails) fails with
# "certificate verify failed", while the CLI (worker, cron, mailer:test) works.
RUN <<-EOF
	mkdir -p /usr/lib/ssl
	ln -s /etc/ssl/certs/ca-certificates.crt /usr/lib/ssl/cert.pem
	ln -s /etc/ssl/certs /usr/lib/ssl/certs
	ln -s /etc/ssl/openssl.cnf /usr/lib/ssl/openssl.cnf
EOF

# The scheduled commands (the `cron` service of Compose runs them), installed before the setuid bits are removed
# hadolint ignore=DL3008
RUN apt-get update && apt-get install -y --no-install-recommends cron && rm -rf /var/lib/apt/lists/*
COPY --link frankenphp/crontab /etc/depverify/crontab
COPY --link --chmod=755 frankenphp/docker-cron.sh /usr/local/bin/docker-cron
RUN crontab -u www-data /etc/depverify/crontab

RUN <<-EOF
	mkdir -p /data/caddy /config/caddy
	chown -R www-data:www-data /data /config
	# Remove setuid/setgid bits
	find / -perm /6000 -type f -exec chmod a-s {} + 2>/dev/null || true
EOF

COPY --link --exclude=var --from=frankenphp_prod_builder /app /app
# Group 0 + g=u for arbitrary-UID runtimes (e.g. OpenShift).
COPY --chown=www-data:0 --from=frankenphp_prod_builder /app/var /app/var
# The agent releases volume starts from this directory (owner and mode included): writable by www-data.
RUN install -d -o www-data -g 0 -m 775 /app/var/agent-releases && chmod g=u /app/var

COPY --link --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint

# www-data, numeric so runtimes can verify runAsNonRoot
USER 33

WORKDIR /app

ENTRYPOINT ["docker-entrypoint"]

HEALTHCHECK --start-period=60s CMD ["php", "-r", "exit(false === @file_get_contents('http://localhost:2019/metrics', context: stream_context_create(['http' => ['timeout' => 5]])) ? 1 : 0);"]
CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile" ]
