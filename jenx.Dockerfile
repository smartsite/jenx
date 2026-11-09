FROM php:8.2-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        docker.io \
        git \
    && mkdir -p /usr/local/lib/docker/cli-plugins \
    && curl -SL \
        https://github.com/docker/compose/releases/download/v5.3.0/docker-compose-linux-x86_64 \
        -o /usr/local/lib/docker/cli-plugins/docker-compose \
    && chmod +x /usr/local/lib/docker/cli-plugins/docker-compose \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/jenx

COPY . /var/www/jenx/

RUN mkdir -p /var/www/jenx/state \
    && chmod 755 /var/www/jenx/state

ENTRYPOINT ["php"]
