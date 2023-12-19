#! /bin/bash

# Largely based on https://d9.hosting/blog/wp-cli-install-wordpress-from-the-command-line/
cd /var/www/html
# Install the latest WP into this directory
wp core download --locale=en_GB
# Configure the database with the credentials set up in root_install_wordpress.sh
wp config create --dbname=wp --dbuser=wp --dbpass=wp --locale=en_GB
# Skip the first-run-wizard
wp core install --url=localhost --title=Test --admin_user=admin --admin_password=password --admin_email=example@example.com --skip-email
# Setup basic permalinks
wp option update permalink_structure ""
# Flush the rewrite schema based on the permalink structure
wp rewrite structure ""