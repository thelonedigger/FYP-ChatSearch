FROM php:8.2-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpq-dev \
    libzip-dev \
    zip \
    unzip \
    nodejs \
    npm \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev

# Configure and install GD extension
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd

# Install other PHP extensions
RUN docker-php-ext-install pdo_pgsql zip

# Install Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www

# Copy application files
COPY . .

# Expose port
EXPOSE 8000

# Default command
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]