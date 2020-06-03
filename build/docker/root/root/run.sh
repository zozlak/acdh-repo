#!/bin/bash
if [ "$USER_GID" != "" ]; then
    groupmod -g $USER_GID www-data
    chgrp -R www-data /var/www
fi
if [ "$USER_UID" != "" ]; then
    usermod -u $USER_UID www-data
    chown -R www-data /var/www
fi
chown -R www-data:www-data /var/run/apache2 /var/run/postgresql

su -l www-data -c 'mkdir -p /var/www/html/build/log'
if [ ! -d /var/www/html/tika ]; then
    su -l www-data -c 'ln -s /var/www/tika /var/www/html/tika'
fi
if [ ! -d /var/www/html/vendor ]; then
    su -l www-data -c 'cd /var/www/html && composer update'
fi
if [ ! -f /var/www/html/config.yaml ]; then
    su -l www-data -c 'cp /var/www/html/tests/config.yaml /var/www/html/config.yaml'
fi
/usr/bin/supervisord -c /root/supervisord.conf
