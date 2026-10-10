FROM php:8.2-apache

# Instalar extensões necessárias
RUN apt-get update && apt-get install -y \
    libpdo-mysql \
    && docker-php-ext-install \
    pdo \
    pdo_mysql \
    curl \
    mbstring \
    && rm -rf /var/lib/apt/lists/*

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Definir diretório de trabalho
WORKDIR /var/www/html

# Copiar arquivos do projeto
COPY . .

# Instalar dependências PHP
RUN composer install --no-interaction --prefer-dist --no-progress --no-dev

# Configurar Apache para servir a pasta public
RUN a2enmod rewrite
RUN sed -i 's|/var/www/html|/var/www/html/public|g' /etc/apache2/sites-available/000-default.conf

# Adicionar .htaccess na pasta public para rewrite rules
RUN echo '<IfModule mod_rewrite.c>\n    RewriteEngine On\n    RewriteBase /\n    RewriteRule ^index\\.html$ - [L]\n    RewriteCond %{REQUEST_FILENAME} !-f\n    RewriteCond %{REQUEST_FILENAME} !-d\n    RewriteRule . /index.html [L]\n</IfModule>' > /var/www/html/public/.htaccess

# Definir permissões
RUN chown -R www-data:www-data /var/www/html

# Expor porta (Render vai usar a variável PORT)
EXPOSE 8080

# Comando start
CMD ["sh", "-c", "apache2ctl -D FOREGROUND"]
