###
# ENV SET UP
###
FROM debian:bullseye

# Install server things
RUN apt update && \
    apt install -y php7.4 \
                   php7.4-fpm \
                   php7.4-gd \
                   php7.4-curl \
                   php7.4-memcached \
                   php7.4-pdo \
                   php7.4-mysql \
                   php7.4-zip \
                   php7.4-cli \
                   nginx \
                   memcached \
                   redis \
                   git \
                   nano

# Copy nginx config
COPY docker/nginx.conf /etc/nginx/sites-enabled/default

###
# APP SET UP
###

WORKDIR /app

# setup the auto-advance cron
RUN echo "*/60 *  * * *   root    cd /app && php cron/advance.php" >> /etc/crontab

EXPOSE 80
RUN chmod +x docker/startup.sh

# Run nginx as the perma-command to keep the container from stopping
ENTRYPOINT ["/bin/bash"]
CMD ["docker/startup.sh"]
