FROM php:8.3-cli-alpine

WORKDIR /app

COPY . /app

RUN addgroup -S pengulab && adduser -S -G pengulab pengulab \
    && touch /app/apps.json \
    && chown -R pengulab:pengulab /app

USER pengulab

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app"]
