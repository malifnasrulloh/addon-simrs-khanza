FROM php:8.4-cli-bookworm

# Install system dependencies including Python for pydicom DICOM generation
RUN apt-get update && apt-get install -y --no-install-recommends \
    dcmtk \
    libmariadb-dev \
    curl \
    python3 \
    python3-venv \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions (PDO only — mysqli not used)
RUN docker-php-ext-install pdo pdo_mysql

# Create Python venv and install pydicom (avoids global pip restrictions on newer systems)
RUN python3 -m venv /app/venv && \
    /app/venv/bin/pip install --no-cache-dir pydicom numpy

WORKDIR /app

# Copy application source for production self-containment
COPY . /app/

# Make entrypoint executable
RUN chmod +x /app/entrypoint.sh

EXPOSE 8080

ENTRYPOINT ["/app/entrypoint.sh"]
