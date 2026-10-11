FROM php:8.2-apache

# Instalar dependências do sistema necessárias
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    libonig-dev \
    libssl-dev \
    zlib1g-dev \
    libzip-dev \
    default-mysql-client \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Instalar extensões PHP necessárias
RUN docker-php-ext-install -j$(nproc) pdo_mysql curl mbstring zip

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copiar o app
COPY . .

# Instalar dependências PHP
RUN composer install --no-interaction --prefer-dist --no-progress --no-dev

# Remover todos os MPMs e ativar somente prefork; validar durante o build
RUN for mpm in mpm_event mpm_worker mpm_prefork; do \
      a2dismod "$mpm" 2>/dev/null || true; \
    done \
    && a2enmod mpm_prefork \
    && apache2ctl -M \
    && apache2ctl -t

# Habilitar mod_rewrite
RUN a2enmod rewrite

# Configurar Apache para servir a pasta public
RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/public|g' /etc/apache2/sites-available/000-default.conf

# Criar .htaccess para rewrite rules
RUN printf '%s\\n' \
    '<IfModule mod_rewrite.c>' \
    '    RewriteEngine On' \
    '    RewriteBase /' \
    '    RewriteRule ^index\\.html$ - [L]' \
    '    RewriteCond %{REQUEST_FILENAME} !-f' \
    '    RewriteCond %{REQUEST_FILENAME} !-d' \
    '    RewriteRule . /index.html [L]' \
    '</IfModule>' > /var/www/html/public/.htaccess

# Ajustar permissões
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

# Reaplicar a seleção do MPM em runtime e falhar com log claro se houver conflito
CMD ["sh", "-c", "for mpm in mpm_event mpm_worker mpm_prefork; do a2dismod \"$mpm\" 2>/dev/null || true; done; a2enmod mpm_prefork; apache2ctl -M; apache2ctl -t; exec apache2-foreground"]
