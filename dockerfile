FROM php:8.3-fpm

# Define build arguments for Magento authentication keys
ARG MAGENTO_PUBLIC_KEY
ARG MAGENTO_PRIVATE_KEY

ENV MAGENTO_PUBLIC_KEY=${MAGENTO_PUBLIC_KEY}
ENV MAGENTO_PRIVATE_KEY=${MAGENTO_PRIVATE_KEY}

# Set environment variables for Composer
ENV COMPOSER_VERSION=2.8.1

# Install system dependencies and PHP extensions required for Magento
RUN apt-get update && apt-get install -y \
    nginx \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    git \
    curl \
    libicu-dev \
    libxslt1-dev \
    vim \
    libzip-dev \
    sendmail-bin \
    sendmail \
    && docker-php-ext-install -j$(nproc) intl xsl soap opcache bcmath mbstring mysqli pdo pdo_mysql zip sockets \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- \
    --install-dir=/usr/local/bin \
    --filename=composer \
    --version=$COMPOSER_VERSION

# Copy the auth.json file
COPY auth.json.sample /var/www/html/auth.json

# Replace the <public-key> and <private-key> in auth.json with the actual values
RUN sed -i 's/<public-key>/'"$MAGENTO_PUBLIC_KEY"'/g' /var/www/html/auth.json && \
    sed -i 's/<private-key>/'"$MAGENTO_PRIVATE_KEY"'/g' /var/www/html/auth.json

# Set the document root to Magento's default directory
WORKDIR /var/www/html

# Copy Magento files
COPY . /var/www/html/

# Install Magento dependencies with Composer
RUN composer update

# Change ownership and permissions for Magento
RUN chown -R www-data:www-data /var/www/html/ \
    && find var generated vendor pub/static pub/media app/etc -type f -exec chmod g+w {} + \
    && find var generated vendor pub/static pub/media app/etc -type d -exec chmod g+ws {} + \
    && chmod u+x bin/magento

# Copy Nginx configuration file
COPY nginx.conf /etc/nginx/conf.d/default.conf

# Expose ports for Nginx and PHP-FPM
EXPOSE 80 9000

# Run Nginx and PHP-FPM within a single CMD command
CMD service nginx start && php-fpm --nodaemonize
