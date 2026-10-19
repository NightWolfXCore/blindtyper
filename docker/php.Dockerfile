FROM php:8.3-cli
RUN apt-get update && apt-get install -y --no-install-recommends libicu-dev libonig-dev libxml2-dev unzip \
    && docker-php-ext-install -j2 pdo_mysql mbstring intl dom xml xmlwriter \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
