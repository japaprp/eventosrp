FROM php:8.2-apache

# Instalar dependências do sistema para compilar extensões PHP
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

# Instalar extensões PHP necessárias
RUN docker-php-ext-install pdo_mysql curl mbstring

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copiar o app
COPY . .

# Instalar dependências PHP
RUN composer install --no-interaction --prefer-dist --no-progress --no-dev

# Habilitar mod_rewrite
RUN a2enmod rewrite

# Configurar Apache para servir a pasta public
RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/public|g' /etc/apache2/sites-available/000-default.conf

# Criar .htaccess para rewrite rules
RUN printf '%s\n' \
    '<IfModule mod_rewrite.c>' \
    '    RewriteEngine On' \
    '    RewriteBase /' \
    '    RewriteRule ^index\.html$ - [L]' \
    '    RewriteCond %{REQUEST_FILENAME} !-f' \
    '    RewriteCond %{REQUEST_FILENAME} !-d' \
    '    RewriteRule . /index.html [L]' \
    '</IfModule>' > /var/www/html/public/.htaccess

# Ajustar permissões
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

CMD ["apache2-foreground"]
