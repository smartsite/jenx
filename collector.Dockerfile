FROM php:8.2-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        libzip-dev \
        zlib1g-dev \
    && docker-php-ext-install -j"$(nproc)" curl zip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/jenx

# Copy only the scripts required by the existing collector.
COPY --chown=root:root jenx-collect-runner.php ./
COPY --chown=root:root jenx-collect.php ./
COPY --chown=root:root jenx-build-state.php ./
COPY --chown=root:root jenx-github.php ./
COPY --chown=root:root jenx-config.php ./

# The collector is a CLI process, not a network service.
USER www-data:www-data

ENTRYPOINT ["php", "/var/www/jenx/jenx-collect-runner.php"]
