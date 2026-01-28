# Multi-stage Dockerfile for Machinjiri Framework
FROM php:8.3-cli-alpine as base

# Install system dependencies
RUN apk add --no-cache \
    git \
    curl \
    openssl \
    postgresql-dev \
    sqlite-dev \
    build-base

# Install PHP extensions
RUN docker-php-ext-install \
    pdo \
    pdo_mysql \
    pdo_pgsql \
    pdo_sqlite

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Development stage
FROM base as development
COPY . .
RUN composer install --no-interaction

# Testing stage
FROM development as testing
RUN composer install --dev --no-interaction
CMD ["./vendor/bin/phpunit"]

# Production stage
FROM base as production
COPY . .
RUN composer install --no-dev --optimize-autoloader --no-interaction
EXPOSE 8000
CMD ["php", "-S", "localhost:8000"]
