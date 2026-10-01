#!/bin/sh
# Copy the Moodle checkout into the container volume, leaving config.php and mod/videoai (bind mounts) alone.
set -e
cd /moodle-src
tar --exclude=./config.php --exclude=./mod/videoai --exclude=./.git -cf - . | tar -xf - -C /var/www/html
chown -R www-data:www-data /var/www/html 2>/dev/null || true
echo "synced $(find /var/www/html -type f | wc -l) files"
