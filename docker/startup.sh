#!/bin/bash
mkdir ./cache
chmod 777 ./cache
mkdir ./images
chmod 777 ./images
mkdir -p ./dist/static/js/

service php7.4-fpm start
service redis-server start
service memcached start
service nginx start
cron

tail -f /var/log/nginx/animebracket.error.log
