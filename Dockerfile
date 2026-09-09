FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        mariadb-server \
        mariadb-client \
        openssl \
    && docker-php-ext-install pdo pdo_mysql \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf

COPY . /var/www/html
COPY docker/start.sh /usr/local/bin/start-crm.sh
RUN sed -i 's/\r$//' /usr/local/bin/start-crm.sh \
    && chmod +x /usr/local/bin/start-crm.sh \
    && mkdir -p /var/www/html/storage/logs \
    && chown -R www-data:www-data /var/www/html

WORKDIR /var/www/html
EXPOSE 80

CMD ["/usr/local/bin/start-crm.sh"]
