FROM php:8.0-apache

RUN apt-get update && apt-get install -y \
	git \
	unzip \
	libzip-dev \
	wget \
	&& docker-php-ext-install pdo_mysql mysqli zip

# Install PSR extension (required by Phalcon)
RUN pecl install psr-1.2.0 \
	&& docker-php-ext-enable psr

# Install Phalcon extension via PECL
RUN pecl install phalcon-5.0.0 \
	&& docker-php-ext-enable phalcon

# Aktifkan mod_rewrite
RUN a2enmod rewrite

# Set document root
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

COPY . /var/www/html

RUN chown -R www-data /var/www/html

EXPOSE 80

CMD ["apache2-foreground"] 