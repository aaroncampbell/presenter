#! /bin/bash
cd /var/www/html/wp-content/plugins
git clone https://github.com/aaroncampbell/presenter --recurse-submodules
wp plugin activate presenter
