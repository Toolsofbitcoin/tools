FROM php:8.2-apache

# Install required system dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libssl-dev \
    && docker-php-ext-install zip bcmath

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Copy custom Apache configuration
COPY apache-config.conf /etc/apache2/sites-available/000-default.conf

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . .

# Create users.json with proper permissions
RUN touch users.json && \
    chmod 666 users.json && \
    chown www-data:www-data users.json

# Create error log with proper permissions
RUN touch error.log && \
    chmod 666 error.log && \
    chown www-data:www-data error.log

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader

# Set proper permissions for web server
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html

EXPOSE 80