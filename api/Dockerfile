FROM php:8.3-apache

RUN a2enmod rewrite

# Allow .htaccess overrides (needed for Slim routing on Apache)
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf


# System deps for pdo_pgsql & pdo_mysql + tools needed by Composer + curl extension build deps
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev \
    default-libmysqlclient-dev \
    libcurl4-openssl-dev \
    git \
    unzip \
    zip \
 && rm -rf /var/lib/apt/lists/*

# PHP extensions (install DB drivers + curl)
RUN docker-php-ext-install pdo_mysql pdo_pgsql curl

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Apache: serve /public
RUN sed -i 's#/var/www/html#/var/www/html/public#g' /etc/apache2/sites-available/000-default.conf

