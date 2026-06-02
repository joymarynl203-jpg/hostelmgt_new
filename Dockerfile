# Hostel Management System — PHP + Apache for Render (and other Docker hosts)
FROM php:8.2-apache-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev libpq-dev \
    && docker-php-ext-install pdo_mysql pdo_pgsql \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/hms-entrypoint.sh
RUN chmod +x /usr/local/bin/hms-entrypoint.sh

COPY HMS ./HMS
COPY hms_superadmin ./hms_superadmin

# Do not bake local secrets into the image
RUN rm -f ./HMS/config.local.php ./hms_superadmin/config.sa.php

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/hms-entrypoint.sh"]
CMD ["apache2-foreground"]
