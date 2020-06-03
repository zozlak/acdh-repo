from ubuntu:focal
ENV DEBIAN_FRONTEND=noninteractive
RUN apt update && \
    apt full-upgrade -y && \
    apt install -y locales
ENV LANG=en_US.UTF-8 LANGUAGE=en_US:en
RUN locale-gen en_US.UTF-8 && \
    apt update && \
    apt install -y supervisor git apache2 curl vim locales libapache2-mod-php php-cli php-pgsql php-zip php-iconv php-readline php-json php-curl php-intl php-mbstring php-yaml php-bcmath php-dom php-opcache php-gd php-xml php-xdebug composer openjdk-8-jre-headless postgresql authbind pv sqlite3 rabbitmq-server && \
    a2enmod rewrite && \
    sed -i -E 's/(www-data:.*:)[^:]*/\1\/bin\/bash/g' /etc/passwd && \
    rm /var/www/html/* && \
    chown -R www-data:www-data /var/www && \
    su -l www-data -c 'mkdir /var/www/tika' && \
    su -l www-data -c 'curl https://archive.apache.org/dist/tika/tika-server-1.24.1.jar > /var/www/tika/tika-server.jar' && \
    su -l www-data -c 'curl https://archive.apache.org/dist/tika/tika-app-1.24.1.jar > /var/www/tika/tika-app.jar' && \
    chown -R www-data:www-data /var/run/postgresql && \
    su -l www-data -c '/usr/lib/postgresql/12/bin/initdb -D /var/www/postgresql --auth=ident -U www-data --locale en_US.UTF-8' 
RUN su -l www-data -c 'git clone https://github.com/acdh-oeaw/arche-core.git /var/www/html' && \
    su -l www-data -c 'cd /var/www/html && composer update' && \
    su -l www-data -c '/usr/lib/postgresql/12/bin/pg_ctl start -D /var/www/postgresql' && \
    su -l www-data -c 'createdb -O www-data www-data' && \
    su -l www-data -c 'psql -f /var/www/html/build/db_schema.sql' && \
    su -l www-data -c '/usr/lib/postgresql/12/bin/pg_ctl stop -D /var/www/postgresql'
COPY /root /
CMD ["/root/run.sh"]

