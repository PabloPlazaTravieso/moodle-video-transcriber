#!/bin/sh
# Upload a large video through the real activity form, like a teacher's browser would.
# Usage (Git Bash on Windows needs MSYS_NO_PATHCONV=1 and a C:/ style path):
#   sh bench/upload.sh <video file> <course id> <upload repository instance id>
B=http://localhost:8000; FILE=$1; COURSE=$2; REPO=$3; J=bench/.cookies; P=bench/.page.html; R=bench/.upload.json
tok=$(curl -s -c $J "$B/login/index.php" | grep -o 'name="logintoken" value="[^"]*"' | sed 's/.*value="//;s/"//' | head -1)
curl -s -b $J -c $J -o /dev/null -d "username=admin&password=Admin1234!&logintoken=$tok" "$B/login/index.php"

curl -s -b $J -c $J "$B/course/modedit.php?add=videoai&course=$COURSE&section=0&return=0" > $P
field() { grep -o "<input[^>]*>" $P | grep "name=\"$1\"" | head -1 | grep -o 'value="[^"]*"' | cut -d\" -f2; }
SESSKEY=$(grep -o '"sesskey":"[^"]*"' $P | head -1 | cut -d'"' -f4)
MODULE=$(field module); DRAFT=$(field videofile); INTRODRAFT=$(field 'introeditor\[itemid\]')
CTX=$(grep -o '"contextid":[0-9]*' $P | head -1 | cut -d: -f2)
echo "form: module=$MODULE videodraft=$DRAFT ctx=$CTX"

# Same endpoint the file picker's "Upload a file" tab posts to.
curl -s -b $J -o $R -w "upload: HTTP %{http_code}, %{size_upload} bytes in %{time_total}s\n" \
  -F "repo_upload_file=@$FILE;type=video/mp4" -F "sesskey=$SESSKEY" -F "repo_id=$REPO" -F "itemid=$DRAFT" \
  -F "ctx_id=$CTX" -F "savepath=/" -F "title=$(basename "$FILE")" -F "maxbytes=-1" -F "areamaxbytes=-1" \
  "$B/repository/repository_ajax.php?action=upload"
echo "upload response: $(head -c 300 $R)"

# availabilityconditionsjson is normally filled in by the form's JavaScript.
curl -s -b $J -o $P -w "form submit: HTTP %{http_code} -> %{redirect_url}\n" \
  --data-urlencode "name=Clase grande ($(basename "$FILE"))" \
  -d "introeditor[text]=&introeditor[format]=1&introeditor[itemid]=$INTRODRAFT&videofile=$DRAFT" \
  -d "visible=1&cmidnumber=&course=$COURSE&coursemodule=&section=0&module=$MODULE" \
  -d "modulename=videoai&instance=&add=videoai&update=0&return=0&sr=&sesskey=$SESSKEY&_qf__mod_videoai_mod_form=1&submitbutton=Save" \
  --data-urlencode 'availabilityconditionsjson={"op":"&","c":[],"showc":[]}' \
  "$B/course/modedit.php"
grep -aoE "[A-Za-z_\]*[Ee]xception: [^<]{0,250}" $P | head -3
