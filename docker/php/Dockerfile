# Misma base que el resto de los proyectos PHP del equipo.
FROM php:8.4-fpm

ARG UID=1000
ARG GID=1000

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libzip-dev \
        libicu-dev \
        libonig-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libwebp-dev \
        default-mysql-client \
    && docker-php-ext-configure intl \
    # gd lo exige PhpSpreadsheet para leer las planillas de nómina, y las
    # fotos de perfil, que se recortan y reescriben al subirlas.
    # `--with-webp`: una foto de 256 px pesa la mitad en WebP que en JPEG.
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    # `exif` es para leer la orientación de las fotos de teléfono: sin ella,
    # un retrato vertical se guarda acostado.
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        bcmath \
        zip \
        intl \
        gd \
        exif \
        opcache \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Límites de subida y de memoria. Ver los comentarios del propio archivo.
COPY docker/php/php.ini /usr/local/etc/php/conf.d/app.ini

# El contenedor escribe en volúmenes montados desde el host: si corriera
# como root, storage/ y bootstrap/cache/ quedarían con dueño equivocado.
RUN groupmod -o -g "${GID}" www-data \
    && usermod  -o -u "${UID}" -g "${GID}" www-data

# www-data necesita un HOME donde pueda escribir: sin esto, `artisan tinker`
# falla al guardar su historial y composer no puede cachear.
ENV HOME=/var/www
RUN mkdir -p /var/www/.config /var/www/.composer \
    && chown -R www-data:www-data /var/www

WORKDIR /var/www/html
USER www-data

EXPOSE 9000
CMD ["php-fpm"]
