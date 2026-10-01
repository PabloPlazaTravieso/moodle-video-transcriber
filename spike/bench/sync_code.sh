#!/bin/sh
# Put Moodle 4.5's code into the container volume, leaving the bind mounts (config.php and the two plugins) alone.
# Source: the checkout mounted at /moodle-src (MOODLE_SRC in .env) or, if there is none, a fresh clone from GitHub.
set -e
SRC=/moodle-src
if [ ! -f "$SRC/version.php" ]; then
  SRC=/tmp/moodle-clone
  if [ ! -f "$SRC/version.php" ]; then
    echo "Cloning Moodle 4.5 (MOODLE_405_STABLE) from GitHub..."
    git clone --quiet --depth 1 --branch MOODLE_405_STABLE https://github.com/moodle/moodle.git "$SRC"
  fi
fi
cd "$SRC"
tar --exclude=./config.php --exclude=./mod/videoai --exclude=./local/videotranscriber --exclude=./.git -cf - . \
  | tar -xf - -C /var/www/html
chown -R www-data:www-data /var/www/html 2>/dev/null || true
echo "Moodle code in place: $(grep -o "release *= *'[^']*'" /var/www/html/version.php)"
