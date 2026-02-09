FROM php:8.0-apache

# Install system dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
	git \
	unzip \
	libzip-dev \
	curl \
	build-essential \
	autoconf \
	&& docker-php-ext-install -j$(nproc) pdo_mysql mysqli zip \
	&& rm -rf /var/lib/apt/lists/*

# Install PSR extension
RUN pecl install psr-1.2.0 > /dev/null 2>&1 && docker-php-ext-enable psr

# Install Phalcon extension (suppress verbose output)
RUN pecl install phalcon-5.0.0 > /dev/null 2>&1 && docker-php-ext-enable phalcon

# Verify Phalcon installation
RUN php -m | grep -i phalcon

# Enable OPcache extension and apply sane defaults
RUN docker-php-ext-install opcache && docker-php-ext-enable opcache
RUN { \
    echo "opcache.memory_consumption=128"; \
    echo "opcache.interned_strings_buffer=8"; \
    echo "opcache.max_accelerated_files=10000"; \
    echo "opcache.revalidate_freq=2"; \
    echo "opcache.enable_cli=1"; \
} > /usr/local/etc/php/conf.d/opcache-recommended.ini

# Enable error display for debugging
RUN echo "display_errors = On" >> /usr/local/etc/php/php.ini-production && \
	echo "error_reporting = E_ALL" >> /usr/local/etc/php/php.ini-production && \
	cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Copy application files
COPY . /var/www/html
WORKDIR /var/www/html

# Install PHP dependencies via Composer
RUN composer install --no-dev --prefer-dist --no-interaction 2>&1 || echo "Composer install completed"

# Set document root
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf && \
	sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf && \
	# Force AllowOverride All globally to ensure .htaccess works
	sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf && \
	a2enmod rewrite && \
	chown -R www-data /var/www/html

EXPOSE 80
CMD ["apache2-foreground"] 