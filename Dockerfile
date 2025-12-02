FROM dockerhub-remote.packages.cofense-tools.com/php:8.5.0RC5-fpm-alpine3.22

# nginx, supervisor, and libraries for php extensions
RUN apk add --no-cache \
    nginx \
    supervisor \
    libzip-dev \
    libpng-dev \
	zip \
    libjpeg-turbo-dev \
    freetype-dev \
    postgresql-dev \
    icu-dev \
    oniguruma-dev \
  && nproc=$(getconf _NPROCESSORS_ONLN) \
  && docker-php-ext-configure gd --with-freetype --with-jpeg \
  && docker-php-ext-install -j$(nproc) \
    pdo_mysql \
    pdo_pgsql \
    zip \
    gd \
    intl 
	
# increasing post max size and upload max filezise
RUN echo "post_max_size = 500M" > /usr/local/etc/php/conf.d/zz-custom-settings.ini
RUN echo "upload_max_filesize = 500M" >> /usr/local/etc/php/conf.d/zz-custom-settings.ini
RUN echo "log_errors = On" >> /usr/local/etc/php/conf.d/zz-custom-settings.ini
RUN echo "error_log = /var/log/php_errors.log" >> /usr/local/etc/php/conf.d/zz-custom-settings.ini
RUN mkdir -p /var/log && touch /var/log/php_errors.log && chown www-data:www-data /var/log/php_errors.log

RUN mkdir -p /run/nginx

COPY nginx.conf /etc/nginx/http.d/default.conf
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

WORKDIR /var/www/html

COPY . .

COPY healthcheck.sh /healthcheck.sh
RUN chmod +x /healthcheck.sh

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

EXPOSE 8080

# start up
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

HEALTHCHECK --interval=30s --timeout=3s --start-period=1m --retries=2 \
  CMD /healthcheck.sh

ARG BUILD_DATE=
ARG VCS_REF=
ARG VCS_URL=
ARG VERSION=
LABEL \
  org.label-schema.schema-version="0.0.1-rc0" \
  org.label-schema.build-date=$BUILD_DATE \
  org.label-schema.name="ocms-service" \
  org.label-schema.vcs-ref=$VCS_REF \
  org.label-schema.vcs-url=$VCS_URL \
  org.label-schema.version="$VERSION"
