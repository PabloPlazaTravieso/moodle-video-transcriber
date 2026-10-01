#!/bin/sh
# HTTP checks against the running spike site. Usage: sh http.sh <cmid> <student username>
B=http://localhost:8000; CMID=$1; STUDENT=$2; T=$(mktemp -d)
login() { # $1 user $2 pass $3 jar
  tok=$(curl -s -c "$3" "$B/login/index.php" | grep -o 'name="logintoken" value="[^"]*"' | sed 's/.*value="//;s/"//' | head -1)
  curl -s -b "$3" -c "$3" -o /dev/null -d "username=$1&password=$2&logintoken=$tok" "$B/login/index.php"
}
ok() { if [ "$2" = "$3" ]; then echo "  [PASS] $1 ($3)"; else echo "  [FAIL] $1 — expected $2, got $3"; fi; }

login admin 'Admin1234!' "$T/admin"
curl -s -b "$T/admin" "$B/mod/videoai/view.php?id=$CMID" > "$T/view.html"
ok "teacher view page loads" 200 "$(curl -s -o /dev/null -w '%{http_code}' -b "$T/admin" "$B/mod/videoai/view.php?id=$CMID")"
ok "status badge rendered" 1 "$(grep -c 'badge' "$T/view.html" | awk '{print ($1>0)}')"
AUDIO=$(grep -o "$B/pluginfile.php/[^\"]*/mod_videoai/audio/[^\"?]*" "$T/view.html" | head -1)
VIDEO=$(grep -o "$B/pluginfile.php/[^\"]*/mod_videoai/video/[^\"?]*" "$T/view.html" | head -1)
echo "  audio url: $AUDIO"
ok "audio served" 200 "$(curl -s -o "$T/a.wav" -w '%{http_code}' -b "$T/admin" "$AUDIO")"
ok "audio content-type" "audio/wav" "$(curl -s -o /dev/null -w '%{content_type}' -b "$T/admin" "$AUDIO" | cut -d';' -f1)"
ok "audio is a RIFF/WAVE file" "RIFF" "$(head -c4 "$T/a.wav")"
ok "video supports range requests (seeking)" 206 "$(curl -s -o /dev/null -w '%{http_code}' -H 'Range: bytes=1000-1999' -b "$T/admin" "$VIDEO")"

login "$STUDENT" 'Spike1234!' "$T/student"
ok "student view page loads" 200 "$(curl -s -o "$T/sview.html" -w '%{http_code}' -b "$T/student" "$B/mod/videoai/view.php?id=$CMID")"
ok "student page has no processing panel" 0 "$(grep -c 'mod_videoai/audio' "$T/sview.html")"
ok "student can stream the video" 206 "$(curl -s -o /dev/null -w '%{http_code}' -H 'Range: bytes=0-999' -b "$T/student" "$VIDEO")"
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$T/student" "$AUDIO")
ok "student cannot download separated audio" 1 "$( [ "$code" != 200 ] && echo 1 || echo 0)"
echo "  (student audio request returned HTTP $code)"
rm -rf "$T"
