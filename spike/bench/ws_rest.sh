#!/bin/sh
# Call mod_videoai_get_transcript over REST, as the question-answering AI would.
# Usage: sh bench/ws_rest.sh <token> <cmid>
curl -s "http://localhost:8000/webservice/rest/server.php" \
  -d "wstoken=$1" -d "wsfunction=mod_videoai_get_transcript" -d "moodlewsrestformat=json" -d "cmid=$2"
