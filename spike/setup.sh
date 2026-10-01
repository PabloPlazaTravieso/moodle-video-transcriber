#!/usr/bin/env bash
# One-command local environment: Moodle 4.5 + both plugins + FFmpeg 8 with Whisper + a demo course.
# Needs Docker. On Windows run it from Git Bash. Safe to run again: finished steps are skipped.
#
#   cd spike && ./setup.sh
#
# Optional settings (environment or spike/.env): MOODLE_PORT (8000), ADMIN_PASS, MOODLE_SRC (existing Moodle 4.5
# checkout to copy instead of cloning), MODELS_VOLUME. The first run downloads about 1 GB and compiles FFmpeg
# (20-40 minutes in total); later runs take seconds.
set -euo pipefail
cd "$(dirname "$0")"
export MSYS_NO_PATHCONV=1 # Git Bash on Windows: do not rewrite /paths in docker arguments.
[ -f .env ] && set -a && . ./.env && set +a
PORT=${MOODLE_PORT:-8000}
ADMIN_PASS=${ADMIN_PASS:-Admin1234!}
URL="http://localhost:$PORT"

dc() { docker compose "$@"; }
web() { dc exec -T webserver "$@"; }
asweb() { dc exec -T -u www-data webserver "$@"; }
step() { printf '\n\033[1;36m== %s\033[0m\n' "$*"; }

docker info > /dev/null 2>&1 || { echo "Docker is not running. Start Docker Desktop and try again."; exit 1; }

step "1/7 Building images (first time: compiles FFmpeg 8 with Whisper, 10-20 min)"
dc build webserver
dc --profile tools build tools

step "2/7 Starting Moodle and PostgreSQL on port $PORT"
dc up -d db webserver
until dc exec -T db pg_isready -U moodle > /dev/null 2>&1; do sleep 2; done

step "3/7 Moodle 4.5 code"
if web test -f /var/www/html/version.php; then echo "already in place"; else web sh /bench/sync_code.sh; fi

step "4/7 Installing Moodle and the plugins"
if [ "$(dc exec -T db psql -U moodle -tAc "SELECT to_regclass('m_config') IS NOT NULL" | tr -d '[:space:]')" = "t" ]; then
  asweb php /var/www/html/admin/cli/upgrade.php --non-interactive | tail -1
else
  asweb php /var/www/html/admin/cli/install_database.php --agree-license --fullname="Video Transcriber" \
    --shortname=vt --summary="Entorno local de pruebas" --adminuser=admin --adminpass="$ADMIN_PASS" \
    --adminemail=admin@example.com | tail -1
fi

step "5/7 Whisper models (575 MB) and Spanish language pack"
web sh -c 'set -e; mkdir -p /models/whisper.cpp && cd /models/whisper.cpp
  get() { [ -s "$1" ] && echo "$1: already downloaded" || { echo "downloading $1"; curl -sSfL --retry 5 -o "$1.part" "$2" && mv "$1.part" "$1" && echo "$1: done"; }; }
  get ggml-large-v3-turbo-q5_0.bin https://huggingface.co/ggerganov/whisper.cpp/resolve/main/ggml-large-v3-turbo-q5_0.bin
  get ggml-silero-v5.1.2.bin https://huggingface.co/ggml-org/whisper-vad/resolve/main/ggml-silero-v5.1.2.bin
  chmod -R a+rX /models'
web sh -c 'set -e; L=/var/www/moodledata/lang; mkdir -p $L
  if [ -d $L/es ]; then echo "es: already installed"; else
    curl -sfL -o /tmp/es.zip https://download.moodle.org/download.php/direct/langpack/4.5/es.zip
    php -r "\$z = new ZipArchive; \$z->open(\"/tmp/es.zip\"); \$z->extractTo(\"$L\");"; rm /tmp/es.zip; echo "es: installed"; fi
  chown -R www-data:www-data $L'

step "6/7 Demo videos (synthetic Spanish voices)"
if web test -f /samples/tts_02_tutoria_dos_voces.mp4; then echo "already generated"; else
  dc --profile tools run --rm -T -e SAMPLES=tts tools sh samples/make_samples.sh | tail -7
fi

step "7/7 Plugin settings, demo course and cron"
asweb php /bench/configure_demo.php
if ! web sh -c 'ps aux | grep -q "[c]ron-loop"'; then
  # The trailing comment marks the loop so the check above finds it.
  dc exec -d -u www-data webserver sh -c 'while true; do php /var/www/html/admin/cli/cron.php > /tmp/cron.log 2>&1; sleep 60; done # cron-loop'
  echo "cron started (every minute)"
else
  echo "cron already running"
fi

cat <<EOF

Done. Open $URL  (user: admin, password: $ADMIN_PASS)
Demo course: $(asweb php -r 'define("CLI_SCRIPT", 1); require "/var/www/html/config.php"; echo $DB->get_field("course", "id", ["shortname" => "demopulse"]);' | sed "s#^#$URL/course/view.php?id=#")

  - "Clase grabada: fotosíntesis" is a Video Transcriber activity: it transcribes itself within a few minutes.
  - More -> "Transcripciones de vídeos" -> "Activar ahora" does what Pulse will do: transcribe all the course videos.
Stop: docker compose stop   ·   Start again: ./setup.sh   ·   Delete everything: docker compose down -v
EOF
