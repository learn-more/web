FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
        libldap2-dev \
        libonig-dev \
        libpng-dev \
    && docker-php-ext-configure ldap \
    && docker-php-ext-install mysqli pdo_mysql ldap mbstring gd \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY apache.conf /etc/apache2/sites-available/000-default.conf
COPY php.ini /usr/local/etc/php/conf.d/docker-custom.ini

# Ensure the indicators directory (bind-mounted from host) is writable at startup
RUN echo '#!/bin/sh\nchmod -f 777 /var/www/html/www.reactos.org/testman/indicators 2>/dev/null || true\nexec apache2-foreground "$@"' > /usr/local/bin/docker-entrypoint.sh \
    && chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
