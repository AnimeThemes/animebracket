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
                   nano \
                   cron

# Copy nginx config
COPY docker/nginx.conf /etc/nginx/sites-enabled/default
COPY docker/docker.conf /etc/nginx/conf.d/docker.conf

###
# APP SET UP
###

WORKDIR /app

# setup the auto-advance cron
COPY docker/crontab /etc/cron.d/animebracket
RUN chmod 0644 /etc/cron.d/animebracket
RUN crontab /etc/cron.d/animebracket

EXPOSE 80

# Run nginx as the perma-command to keep the container from stopping
ENTRYPOINT ["/bin/bash"]
CMD ["docker/startup.sh"]
