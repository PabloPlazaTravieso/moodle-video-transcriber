#!/bin/sh
# HTTP checks for local_videotranscriber. Usage: sh bench/auto_http.sh <courseid> <student username> <ws token>
B=http://localhost:8000; C=$1; STUDENT=$2; TOKEN=$3; T=$(mktemp -d)
login() {
  tok=$(curl -s -c "$3" "$B/login/index.php" | grep -o 'name="logintoken" value="[^"]*"' | sed 's/.*value="//;s/"//' | head -1)
  curl -s -b "$3" -c "$3" -o /dev/null -d "username=$1&password=$2&logintoken=$tok" "$B/login/index.php"
}
ok() { if [ "$2" = "$3" ]; then echo "  [PASS] $1 ($3)"; else echo "  [FAIL] $1 — expected $2, got $3"; fi; }
login admin 'Admin1234!' "$T/a"
ok "report page loads" 200 "$(curl -s -o "$T/r.html" -w '%{http_code}' -b "$T/a" "$B/local/videotranscriber/index.php?id=$C")"
ok "report lists 4 videos (3 transcribed + 1 skipped)" 4 "$(grep -o 'badge badge-' "$T/r.html" | wc -l | tr -d ' ')"
V=$(grep -o 'videoid=[0-9]*' "$T/r.html" | head -1)
ok "transcript view shows timed lines" 1 "$(curl -s -b "$T/a" "$B/local/videotranscriber/index.php?id=$C&$V" | grep -c 'font-monospace' | awk '{print ($1>0)}')"
ok "course page links the report" 1 "$(curl -s -b "$T/a" "$B/course/view.php?id=$C" | grep -c 'local/videotranscriber/index.php' | awk '{print ($1>0)}')"
login "$STUDENT" 'Spike1234!' "$T/s"
code=$(curl -s -o "$T/s.html" -w '%{http_code}' -b "$T/s" "$B/local/videotranscriber/index.php?id=$C")
ok "student cannot open the report" 1 "$( [ "$code" != 200 ] || grep -q 'nopermissions\|Sorry\|Lo sentimos' "$T/s.html" && echo 1 || echo 0)"
echo "  (student got HTTP $code)"
R=$(curl -s "$B/webservice/rest/server.php" -d "wstoken=$TOKEN" -d "wsfunction=local_videotranscriber_get_course_transcripts" -d "moodlewsrestformat=json" -d "courseid=$C")
echo "$R" > "$T/ws.json"
ok "REST web service returns the course videos" 4 "$(echo "$R" | grep -o '"videoid"' | wc -l | tr -d ' ')"
echo "  REST sample: $(echo "$R" | grep -o '"activityname":"[^"]*"' | sort -u | tr '\n' ' ')"
rm -rf "$T"
