# Use PHP 8.3 FPM Alpine as the base image
FROM php:8.3-fpm-alpine

RUN php -m

# Set PHP memory limit to unlimited (-1)
RUN echo "memory_limit = -1" > /usr/local/etc/php/conf.d/memory-limit.ini

# Install dependencies and PHP extensions
RUN apk add --no-cache freetype \
    freetype-dev \
    autoconf \
    gcc \
    g++ \
    make \
    nginx \
    bash \
    gzip \
    lsof \
    mariadb-client \
    coreutils \
    sed \
    tar \
    libzip-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    libxpm-dev \
    git \
    unzip \
    curl \
    libxml2-dev \
    oniguruma-dev \
    icu-dev \
    zlib-dev \
    linux-headers \
    libxslt-dev

# Configure and install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp --with-xpm && \
    docker-php-ext-install -j$(nproc) bcmath gd intl soap sockets xsl pdo_mysql zip && \
    rm -rf /var/cache/apk/*

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer --version=2.8.1

# Set working directory
WORKDIR /var/www/html

# Copy local Magento code to the working directory
COPY . /var/www/html

# Copy the auth.json file
COPY auth.json.sample /var/www/html/auth.json

# Define build arguments for Magento authentication keys
ARG MAGENTO_PUBLIC_KEY
ARG MAGENTO_PRIVATE_KEY

# Replace the <public-key> and <private-key> in auth.json with the actual values
RUN sed -i 's/<public-key>/'"$MAGENTO_PUBLIC_KEY"'/g' /var/www/html/auth.json && \
    sed -i 's/<private-key>/'"$MAGENTO_PRIVATE_KEY"'/g' /var/www/html/auth.json && cat /var/www/html/auth.json

# Install PHP dependencies via Composer
RUN composer install --no-cache --no-interaction --no-dev

# Set permissions for Magento directories and files
RUN find . -type f -exec chmod 644 {} \; && \
    find . -type d -exec chmod 755 {} \; && \
    chmod -Rf 777 var && \
    chmod -Rf 777 pub/static && \
    chmod -Rf 777 pub/media && \
    chmod 777 ./app/etc && \
    chmod 644 ./app/etc/*.xml && \
    chmod -Rf 775 bin

# Define build arguments for Magento setup installation
ARG BASE_URL
ARG DB_HOST
ARG DB_NAME
ARG DB_USER
ARG DB_PASSWORD
ARG ADMIN_FIRSTNAME
ARG ADMIN_LASTNAME
ARG ADMIN_EMAIL
ARG ADMIN_USER
ARG ADMIN_PASSWORD
ARG ELASTICSEARCH_HOST

# Run the Magento setup install command with variables
RUN php bin/magento setup:install --base-url="${BASE_URL}" --base-url-secure="${BASE_URL}" --db-host="${DB_HOST}"  --db-name="${DB_NAME}" --db-user="${DB_USER}" --db-password="${DB_PASSWORD}" --admin-firstname="${ADMIN_FIRSTNAME}"  --admin-lastname="${ADMIN_LASTNAME}"  --admin-email="${ADMIN_EMAIL}"  --admin-user="${ADMIN_USER}" --admin-password="${ADMIN_PASSWORD}" --use-rewrites="1" --backend-frontname="admin" --search-engine="elasticsearch7" --elasticsearch-host="${ELASTICSEARCH_HOST}" --elasticsearch-port="9200" --language=en_US --currency=USD --timezone=America/Chicago  --use-rewrites=1

# Change permissions (if necessary)
RUN chown -R www-data:www-data /var/www/html

# Copy nginx configuration file
COPY ./nginx.conf /etc/nginx/nginx.conf

# Expose the ports for PHP and Nginx
EXPOSE 80

# Start PHP-FPM and Nginx
CMD ["sh", "-c", "php-fpm & nginx -g 'daemon off;'"]
