#!/bin/sh
# Run every transcription engine on the same WAVs, one at a time (no CPU contention), then score them.
# Usage: sh asr/run_all.sh [ref|all] [engine ...]   (from the spike folder; Git Bash needs MSYS_NO_PATHCONV=1)
SET=${1:-ref}; shift 2>/dev/null
C="docker compose -f asr/docker-compose.yml run --rm -T"
run() { # $1 service, $2 result name, $3 script, then VAR=value env pairs
  svc=$1; name=$2; script=$3; shift 3
  envs=""; for e in "$@"; do envs="$envs -e $e"; done
  echo "=== $name ==="
  $C $envs "$svc" python -u "runners/$script" --name "$name" --set "$SET" 2>&1 | grep --line-buffered -v 'Container\|Network\|Volume\|Warning\|warn(' || echo "!!! $name failed"
}
want() { [ -z "$ONLY" ] || echo " $ONLY " | grep -q " $1 "; }
ONLY="$*"

want faster-whisper && run faster-whisper faster-whisper-turbo faster_whisper_run.py FW_VAD=1
want faster-whisper-novad && run faster-whisper faster-whisper-turbo-sin-vad faster_whisper_run.py FW_VAD=0
want faster-whisper-batched && run faster-whisper faster-whisper-turbo-lotes faster_whisper_run.py FW_BATCH=8
want whisper-cpp && run whisper-cpp whisper.cpp-turbo-q5 whisper_cpp_run.py
want parakeet && run onnx-asr parakeet-tdt-0.6b-v3 onnx_asr_run.py ONNX_MODEL=nemo-parakeet-tdt-0.6b-v3
want canary && run onnx-asr canary-1b-v2 onnx_asr_run.py ONNX_MODEL=nemo-canary-1b-v2
want vosk && run vosk vosk-small-es-0.42 vosk_run.py
want openai-whisper && run openai-whisper openai-whisper-turbo openai_whisper_run.py
want whisperx && run whisperx whisperx-turbo whisperx_run.py

echo "=== Score ==="
$C faster-whisper python score.py -v 2>&1 | grep -v 'Container\|Network\|Volume'
