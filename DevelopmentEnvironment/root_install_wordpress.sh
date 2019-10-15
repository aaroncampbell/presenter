#! /bin/bash

# Allow us to run commands as www-data
chsh -s /bin/bash www-data
# Let www-data access files in the web-root.
chown -R www-data:www-data /var/www

# wp-cli
curl -s -S -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
mv wp-cli.phar /usr/local/bin/wp
chmod +x /usr/local/bin/wp

# Slightly based on 
# https://www.a2hosting.co.uk/kb/developer-corner/mysql/managing-mysql-databases-and-users-from-the-command-line
echo "CREATE DATABASE wp;" | mysql -u root
echo "GRANT ALL PRIVILEGES ON wp.* TO 'wp'@'localhost' IDENTIFIED BY 'wp';" | mysql -u root
echo "FLUSH PRIVILEGES;" | mysql -u root

su - www-data -c /vagrant/user_install_wordpress.sh

su - www-data -c /vagrant/customise_wordpress.sh