#!/bin/sh
# Build the test video set in /samples. Run inside the "tools" service:
#   docker compose --profile tools run --rm tools sh samples/make_samples.sh
# Synthetic videos: Piper TTS reading samples/texts/*.txt (exact reference transcript).
# Real videos: public-domain / Creative Commons media from Wikimedia Commons (see samples/SOURCES.md).
set -e
OUT=/samples; VOICES=/samples/.voices; TXT=/work/samples/texts
mkdir -p "$OUT" "$VOICES" "$OUT/reference"
F="ffmpeg -nostdin -loglevel error -y"
HF=https://huggingface.co/rhasspy/piper-voices/resolve/main

voice() { # $1 = path inside piper-voices, e.g. es/es_ES/davefx/medium/es_ES-davefx-medium
  name=$(basename "$1")
  [ -f "$VOICES/$name.onnx" ] || { curl -sfL -o "$VOICES/$name.onnx" "$HF/$1.onnx"; curl -sfL -o "$VOICES/$name.onnx.json" "$HF/$1.onnx.json"; }
  echo "$VOICES/$name.onnx"
}
say() { # $1 voice model, $2 text file, $3 output wav, [$4 speaker id]
  if [ -n "$4" ]; then piper -m "$1" -s "$4" -f "$3" < "$2" >/dev/null 2>&1; else piper -m "$1" -f "$3" < "$2" >/dev/null 2>&1; fi
}
# Wrap an audio file in a video: 1280x720 slide-like background with a clock, 25 fps, H.264 + AAC.
to_video() { # $1 audio, $2 output mp4, $3 background colour
  $F -f lavfi -i "color=c=$3:s=1280x720:r=25" -f lavfi -i "testsrc2=s=320x180:r=25" -i "$1" \
     -filter_complex "[0][1]overlay=W-w-40:H-h-40:shortest=1[v]" -map "[v]" -map 2:a \
     -c:v libx264 -preset veryfast -crf 28 -pix_fmt yuv420p -c:a aac -b:a 128k -shortest "$2"
}
T=$(mktemp -d)

echo "== Synthetic (Piper TTS) =="
DAVE=$(voice es/es_ES/davefx/medium/es_ES-davefx-medium)
SHARV=$(voice es/es_ES/sharvard/medium/es_ES-sharvard-medium)
MX=$(voice es/es_MX/claude/high/es_MX-claude-high)

# 1. Plain lecture, Spain Spanish.
say "$DAVE" "$TXT/clase_fotosintesis.txt" "$T/foto.wav"
to_video "$T/foto.wav" "$OUT/tts_01_clase_fotosintesis.mp4" 0x1d3557
cp "$TXT/clase_fotosintesis.txt" "$OUT/reference/tts_01_clase_fotosintesis.txt"

# 2. Dialogue: alternating female (teacher) and male (student) voices.
i=0; : > "$T/list.txt"
while IFS= read -r line; do
  [ -z "$line" ] && continue
  printf '%s\n' "$line" > "$T/l.txt"; spk=$(( i % 2 == 0 ? 1 : 0 ))
  say "$SHARV" "$T/l.txt" "$T/d$i.wav" "$spk"
  $F -i "$T/d$i.wav" -af "apad=pad_dur=0.6" -ar 22050 -ac 1 "$T/dp$i.wav"
  echo "file '$T/dp$i.wav'" >> "$T/list.txt"; i=$((i+1))
done < "$TXT/entrevista_profesora.txt"
$F -f concat -safe 0 -i "$T/list.txt" "$T/entrevista.wav"
to_video "$T/entrevista.wav" "$OUT/tts_02_tutoria_dos_voces.mp4" 0x2a9d8f
cp "$TXT/entrevista_profesora.txt" "$OUT/reference/tts_02_tutoria_dos_voces.txt"

# 3. Speech, 45 s of silence, 20 s of synthetic music, speech: Whisper tends to invent text in gaps.
say "$DAVE" "$TXT/clase_silencio_parte1.txt" "$T/s1.wav"
say "$DAVE" "$TXT/clase_silencio_parte2.txt" "$T/s2.wav"
$F -f lavfi -i "anullsrc=r=22050:cl=mono:d=45" "$T/silence.wav"
$F -f lavfi -i "sine=f=261.6:d=20:r=22050" -f lavfi -i "sine=f=329.6:d=20:r=22050" -f lavfi -i "sine=f=392:d=20:r=22050" \
   -filter_complex "[0][1][2]amix=inputs=3,tremolo=f=4:d=0.6,volume=0.5" -ac 1 "$T/music.wav"
for x in s1 silence music s2; do $F -i "$T/$x.wav" -ar 22050 -ac 1 "$T/n_$x.wav"; echo "file '$T/n_$x.wav'"; done > "$T/list2.txt"
$F -f concat -safe 0 -i "$T/list2.txt" "$T/gap.wav"
to_video "$T/gap.wav" "$OUT/tts_03_silencio_y_musica.mp4" 0x264653
cat "$TXT/clase_silencio_parte1.txt" "$TXT/clase_silencio_parte2.txt" > "$OUT/reference/tts_03_silencio_y_musica.txt"

# 4. Technical vocabulary, numbers, Mexican Spanish.
say "$MX" "$TXT/clase_programacion.txt" "$T/prog.wav"
to_video "$T/prog.wav" "$OUT/tts_04_clase_programacion_mx.mp4" 0x3a0ca3
cp "$TXT/clase_programacion.txt" "$OUT/reference/tts_04_clase_programacion_mx.txt"

# 5. Lecture 1 with moderate pink background noise (~16 dB below the voice), like a classroom recording.
$F -i "$T/foto.wav" -f lavfi -i "anoisesrc=c=pink:r=22050:a=0.08" \
   -filter_complex "[0]volume=1[s];[1]volume=1[n];[s][n]amix=inputs=2:duration=first:normalize=0" "$T/noisy.wav"
to_video "$T/noisy.wav" "$OUT/tts_05_clase_fotosintesis_ruido.mp4" 0x6d597a
cp "$TXT/clase_fotosintesis.txt" "$OUT/reference/tts_05_clase_fotosintesis_ruido.txt"

# SAMPLES=tts skips the downloads from Wikimedia Commons (enough for the demo course).
if [ "${SAMPLES:-all}" != "tts" ]; then
echo "== Real speech (Wikimedia Commons) =="
fetch() { # $1 Commons file name, $2 output
  [ -f "$2" ] || curl -sfL -A "videoai-spike/0.1 (test media for a Moodle plugin)" -o "$2" \
    "https://commons.wikimedia.org/wiki/Special:FilePath/$(printf '%s' "$1" | sed 's/ /_/g')"
}
# Real videos, kept as uploaded.
fetch '"Las estrellas" (Worldnet TV)..webm' "$OUT/real_01_las_estrellas.webm"
fetch '"Cuidemos el planeta Tierra" (Worldnet TV)..webm' "$OUT/real_02_cuidemos_el_planeta.webm"
fetch '1.2.3TV - Mi casa es una burbuja.webm' "$OUT/real_03_mi_casa_es_una_burbuja.webm"
# Spoken Wikipedia recordings (audio only), wrapped into a video.
fetch 'Caracas, Spanish Wikipedia Article Intro.wav' "$T/caracas.wav" && to_video "$T/caracas.wav" "$OUT/real_04_wikipedia_caracas.mp4" 0x003670
fetch 'Edgar Allan Poe - El Cuervo Spanish.ogg' "$T/cuervo.ogg" && to_video "$T/cuervo.ogg" "$OUT/real_05_poema_el_cuervo.mp4" 0x012142
fetch 'CanariasVoz1.ogg' "$T/canarias.ogg" && to_video "$T/canarias.ogg" "$OUT/real_06_acento_canario.mp4" 0x01264c
fetch 'Dolores cacuango.ogg' "$T/dolores.ogg" && to_video "$T/dolores.ogg" "$OUT/real_07_wikipedia_dolores_cacuango.mp4" 0x314668

fi

# Approximate references for real recordings: the Wikipedia revision each volunteer read
# (built with samples/wiki_to_text.php; Caracas is the first intro paragraph, 62 s at ~140 words/min).
for r in real_04_wikipedia_caracas real_07_wikipedia_dolores_cacuango; do cp "$TXT/$r.txt" "$OUT/reference/$r.txt"; done
mkdir -p "$OUT/audio" && chmod 777 "$OUT/audio"  # written by the plugin export (bench/samples_e2e.php)

rm -rf "$T"
echo "== Result =="
for f in "$OUT"/*.mp4 "$OUT"/*.webm; do
  [ -f "$f" ] || continue  # No .webm when SAMPLES=tts.
  d=$(ffprobe -v error -show_entries format=duration -of csv=p=0 "$f")
  a=$(ffprobe -v error -select_streams a:0 -show_entries stream=codec_name -of csv=p=0 "$f")
  mb=$(awk "BEGIN{printf \"%.1f\", $(stat -c %s "$f")/1048576}")
  printf '%-44s %7.1fs %6sMB audio=%s\n' "$(basename "$f")" "$d" "$mb" "$a"
done
