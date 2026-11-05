FROM php:8.2-apache
RUN apt-get update && apt-get install -y --no-install-recommends ffmpeg \
    && rm -rf /var/lib/apt/lists/*
COPY setup-admin.php /opt/streamvideo/setup-admin.php
COPY setup-key.php credentials.php /opt/streamvideo/
COPY apache.conf /etc/apache2/sites-enabled/000-default.conf
RUN a2enmod rewrite
RUN php -r 'exit(extension_loaded("pdo_sqlite") && extension_loaded("fileinfo") ? 0 : 1);' \
    && mkdir -p /var/lib/streamvideo \
    && chown www-data:www-data /var/lib/streamvideo
