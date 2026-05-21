FROM php:8.4-cli-bookworm

# Install only required system dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    dcmtk \
    libmariadb-dev \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions (PDO only — mysqli not used)
RUN docker-php-ext-install pdo pdo_mysql

WORKDIR /app

# Copy application source for production self-containment
COPY . /app/

# Make entrypoint executable
RUN chmod +x /app/entrypoint.sh

EXPOSE 8080

ENTRYPOINT ["/app/entrypoint.sh"]
