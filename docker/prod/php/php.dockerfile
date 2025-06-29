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

# Add Microsoft GPG key and SQL Server repo
RUN curl -sSL https://packages.microsoft.com/keys/microsoft.asc | gpg --dearmor > /usr/share/keyrings/microsoft.asc.gpg \
 && echo "deb [signed-by=/usr/share/keyrings/microsoft.asc.gpg] https://packages.microsoft.com/debian/12/prod bookworm main" > /etc/apt/sources.list.d/mssql-release.list

# Install Microsoft SQL Server drivers
RUN apt-get update \
 && ACCEPT_EULA=Y apt-get install -y msodbcsql18 mssql-tools18

# Set MSSQL tools path (optional, for debugging or usage)
ENV PATH="${PATH}:/opt/mssql-tools18/bin"

# Install and enable SQLSRV extensions
RUN pecl install pdo_sqlsrv sqlsrv \
 && docker-php-ext-enable pdo_sqlsrv sqlsrv \
 && docker-php-ext-install pdo \
 && php -m | grep sqlsrv

WORKDIR /var/www/html

CMD ["php-fpm"]
