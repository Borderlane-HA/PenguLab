FROM php:8.3-cli-alpine

WORKDIR /app

RUN apk add --no-cache \
      curl-dev \
      libxml2-dev \
      libsodium-dev \
      oniguruma-dev \
      sqlite-dev \
      su-exec \
      nmap \
    && docker-php-ext-install -j"$(nproc)" \
      curl \
      mbstring \
      pdo_sqlite \
      simplexml \
      sodium

COPY . /app

RUN addgroup -S pengulab \
    && adduser -S -G pengulab pengulab \
    && mkdir -p /app/data \
    && chown -R pengulab:pengulab /app/data \
    && if [ -f /app/apps.json ]; then chown pengulab:pengulab /app/apps.json; fi \
    && chmod +x /app/docker-entrypoint.sh

ENV PENGULAB_DATA_DIR=/app/data

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD php -r '$s=@file_get_contents("http://127.0.0.1:8080/api.php?route=bootstrap"); exit($s===false?1:0);'

ENTRYPOINT ["/app/docker-entrypoint.sh"]
CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app"]
