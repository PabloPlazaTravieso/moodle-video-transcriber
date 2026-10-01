#!/bin/sh
# End-to-end test of the Pulse trigger, calling the REST web services from outside Moodle as Pulse would.
# Only adhoc tasks are run (never the nightly scheduled task), to show transcription starts on its own.
# Usage (from spike/, Git Bash needs MSYS_NO_PATHCONV=1): sh bench/pulse_e2e.sh
B=http://localhost:8000
EXEC="docker compose exec -T -u www-data webserver"
fail=0
check() { if [ "$2" = "1" ]; then echo "  [PASS] $1 — $3"; else echo "  [FAIL] $1 — $3"; fail=$((fail+1)); fi; }
rest() { curl -s "$B/webservice/rest/server.php" -d "wstoken=$TOKEN" -d "moodlewsrestformat=json" -d "wsfunction=$1" "${@:2}"; }
state() { $EXEC php /bench/pulse_e2e.php state "$1"; }
adhoc() { $EXEC php /var/www/html/admin/cli/adhoc_task.php --execute > /dev/null 2>&1; }
has() { echo "$1" | grep -q "$2" && echo 1 || echo 0; }

SETUP=$($EXEC php /bench/pulse_e2e.php setup | tail -1)
P1=$(echo "$SETUP" | grep -o '"p1":[0-9]*' | cut -d: -f2)
P2=$(echo "$SETUP" | grep -o '"p2":[0-9]*' | cut -d: -f2)
P3=$(echo "$SETUP" | grep -o '"p3":[0-9]*' | cut -d: -f2)
TOKEN=$(echo "$SETUP" | grep -o '"token":"[^"]*"' | cut -d'"' -f4)
echo "courses p1=$P1 p2=$P2 p3=$P3"

echo "A. Before Pulse is active, nothing is transcribed"
S=$(state "$P1"); check "course P1 inactive, its video not picked up" "$(has "$S" '"enabled":false.*"status":-1')" "$S"

echo "B. Pulse activates course P1 (REST set_course_enabled)"
R=$(rest local_videotranscriber_set_course_enabled -d courseid=$P1 -d enabled=1)
check "web service answers: enabled, 1 video queued" "$(has "$R" '"enabled":true.*"videosqueued":1')" "$R"
start=$(date +%s); adhoc; secs=$(( $(date +%s) - start ))
S=$(state "$P1"); check "transcribed without the nightly task" "$(has "$S" '"status":3,"segments":[1-9]')" "${secs}s · $S"

echo "C. Pulse asks for the transcripts of P2, where it was already active (auto-activation)"
R=$(rest local_videotranscriber_get_course_transcripts -d courseid=$P2)
check "request activates the course and queues its video" "$(has "$R" '"activated":true')" "$(echo "$R" | grep -o '"inscope":[a-z]*,"activated":[a-z]*')"
adhoc
R=$(rest local_videotranscriber_get_course_transcripts -d courseid=$P2)
check "second request returns the transcript" "$(has "$R" '"activated":false.*"ready":true.*"text":"\[00:00:00\]')" "$(echo "$R" | grep -o '"text":"[^"]\{0,70\}')"

echo "D. A teacher adds a new video to P1"
$EXEC php /bench/pulse_e2e.php addvideo "$P1" tts_03_silencio_y_musica > /dev/null
adhoc   # Runs the discovery queued by the observer, then the transcription it queued.
adhoc
S=$(state "$P1"); check "new video found and transcribed automatically" "$(has "$S" 'tts_03_silencio_y_musica.mp4","status":3')" "$S"

echo "E. Pulse deactivates P1, then another video is added"
R=$(rest local_videotranscriber_set_course_enabled -d courseid=$P1 -d enabled=0)
check "web service answers: disabled" "$(has "$R" '"enabled":false')" "$R"
$EXEC php /bench/pulse_e2e.php addvideo "$P1" tts_05_clase_fotosintesis_ruido > /dev/null
adhoc
S=$(state "$P1"); check "new video not transcribed, earlier transcripts kept" \
    "$( [ "$(has "$S" 'tts_05_clase_fotosintesis_ruido.mp4","status":-1')$(has "$S" 'tts_02_tutoria_dos_voces.mp4","status":3')" = 11 ] && echo 1 || echo 0)" "$S"

echo "F. Course P3, never touched by Pulse"
S=$(state "$P3"); check "nothing transcribed" "$(has "$S" '"enabled":false.*"status":-1')" "$S"

echo "G. A request with an invalid token is rejected"
R=$(curl -s "$B/webservice/rest/server.php" -d wstoken=nope -d moodlewsrestformat=json -d wsfunction=local_videotranscriber_set_course_enabled -d courseid=$P3 -d enabled=1)
check "invalid token rejected" "$(has "$R" 'invalidtoken')" "$R"

[ $fail -eq 0 ] && echo "ALL PASSED" || echo "$fail FAILED"
