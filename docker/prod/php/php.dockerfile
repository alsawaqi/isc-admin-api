FROM php:8.3-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    gnupg2 \
    curl \
    apt-transport-https \
    ca-certificates \
    unixodbc-dev \
    lsb-release \
    libxml2-dev \
    libssl-dev \
    zip unzip git \
    gnupg \
    libgssapi-krb5-2 \
    libodbc1 \
    && apt-get clean

# Add Microsoft GPG key and MS SQL repository
RUN curl -sSL https://packages.microsoft.com/keys/microsoft.asc | gpg --dearmor > /usr/share/keyrings/microsoft.asc.gpg \
 && echo "deb [signed-by=/usr/share/keyrings/microsoft.asc.gpg] https://packages.microsoft.com/debian/12/prod bookworm main" > /etc/apt/sources.list.d/mssql-release.list

# Install Microsoft SQL Server drivers
RUN apt-get update \
 && ACCEPT_EULA=Y apt-get install -y msodbcsql18 mssql-tools18 \
 && echo 'export PATH="$PATH:/opt/mssql-tools18/bin"' >> ~/.bashrc

# Install and enable PHP SQLSRV extensions
RUN pecl install pdo_sqlsrv sqlsrv \
 && docker-php-ext-enable pdo_sqlsrv sqlsrv

# Other PHP extensions (if needed)
RUN docker-php-ext-install pdo

WORKDIR /var/www/html

CMD ["php-fpm"]
