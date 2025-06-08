FROM php:8.4.5-fpm

# Set build arguments from .env file
ARG USER_ID=1000
ARG GROUP_ID=1000
ARG PZ_FOLDER=app
ARG CRON_SCHEDULE='* * * * *'
ARG SCHEDULE_TOKEN

# Install dependencies
RUN apt-get update && apt-get install -y \
    libfreetype-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    cron \
&& docker-php-ext-configure gd --with-freetype --with-jpeg \
&& docker-php-ext-install -j$(nproc) gd

RUN docker-php-ext-install mysqli pdo pdo_mysql calendar && docker-php-ext-enable pdo_mysql
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

RUN apt-get update && apt-get install -y cron

# Create the group and user
RUN groupadd -g ${GROUP_ID} pz-user && \
    useradd -u ${USER_ID} -g pz-user -m -s /bin/bash pz-user

# Set permissions
RUN if [ ! -z "$SCHEDULE_TOKEN" ]; then \
    echo "Running scheduler install"; \
    echo "${CRON_SCHEDULE} pz-user CRON_TOKEN=${SCHEDULE_TOKEN} $(which php) /var/www/${PZ_FOLDER}/schedule.php >> /var/log/cron.log 2>&1" > /etc/cron.d/pz-cron \
    && touch /var/log/cron.log \
    && chmod 666 /var/log/cron.log; \
else \
    echo "Skipping scheduler setup"; \
fi

# Set user for the php-fpm process
COPY www.conf /usr/local/etc/php-fpm.d/www.conf
RUN echo "" >> /usr/local/etc/php-fpm.d/www.conf && \
    echo "user = pz-user" >> /usr/local/etc/php-fpm.d/www.conf && \
    echo "group = pz-user" >> /usr/local/etc/php-fpm.d/www.conf

WORKDIR /var/www/${PZ_FOLDER}/

# Copy and set permissions for the entrypoint script
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]