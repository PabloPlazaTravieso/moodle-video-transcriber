"""Transcription service for mod_videoai.

POST /transcribe  multipart field "file" (audio), optional form field "language" (e.g. "es"; empty = detect).
                  Returns {"language", "duration", "text", "segments": [{"start", "end", "text"}], "model",
                  "processing_seconds"}.
GET  /health      Returns the loaded model and settings.

Engine: faster-whisper BatchedInferencePipeline with VAD, the best option in the spike comparison
(spike/asr/RESULTADOS.md): Spanish WER ~2 %, no invented text in silences, ~0.43x real time on 8 CPU threads.
"""
import logging
import os
import secrets
import tempfile
import threading
import time

from fastapi import Depends, FastAPI, File, Form, Header, HTTPException, UploadFile
from faster_whisper import BatchedInferencePipeline, WhisperModel, __version__ as fw_version
from faster_whisper.audio import decode_audio
from faster_whisper.vad import VadOptions, get_speech_timestamps

MODEL = os.environ.get("WHISPER_MODEL", "large-v3-turbo")
DEVICE = os.environ.get("WHISPER_DEVICE", "cpu")  # "cuda" on a GPU host
COMPUTE_TYPE = os.environ.get("WHISPER_COMPUTE_TYPE", "int8")  # "float16" on GPU
THREADS = int(os.environ.get("WHISPER_THREADS", "0"))  # 0 = all cores
BATCH_SIZE = int(os.environ.get("WHISPER_BATCH_SIZE", "8"))
BEAM_SIZE = int(os.environ.get("WHISPER_BEAM_SIZE", "5"))
MAX_UPLOAD_MB = int(os.environ.get("MAX_UPLOAD_MB", "1024"))
# Batched decoding returns ~30 s chunks; with word timestamps they are re-cut into sentences so answers
# can point to the exact moment. A sentence longer than this is split anyway.
MAX_SEGMENT_SECONDS = float(os.environ.get("MAX_SEGMENT_SECONDS", "20"))
# Speech pieces closer than this are decoded together; a longer pause always starts a new chunk.
MAX_GAP_SECONDS = float(os.environ.get("MAX_GAP_SECONDS", "1.0"))
SR = 16000
API_KEY = os.environ.get("API_KEY", "")
MODEL_DIR = os.environ.get("MODEL_DIR", "/models")

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
log = logging.getLogger("transcriber")

log.info("loading %s (%s, %s) ...", MODEL, DEVICE, COMPUTE_TYPE)
_t = time.perf_counter()
_whisper = WhisperModel(MODEL, device=DEVICE, compute_type=COMPUTE_TYPE, cpu_threads=THREADS, download_root=MODEL_DIR)
pipeline = BatchedInferencePipeline(_whisper)
log.info("model ready in %.1fs", time.perf_counter() - _t)

# One transcription at a time: a second one would only compete for the same cores and double the memory.
# Further requests wait here; scale by running more containers behind a load balancer.
_busy = threading.Lock()

app = FastAPI(title="mod_videoai transcriber", version="1.0.0")


def speech_clips(audio):
    """Contiguous stretches of speech, at most 30 s long, for the batched pipeline.

    The pipeline's own VAD concatenates speech pieces across pauses into one 30 s chunk, which misplaces
    the words right after a long pause (a word said at 71 s came back at 5.6 s in the spike). Passing our
    own clips keeps every chunk contiguous, so word times map back correctly.
    """
    pieces = get_speech_timestamps(audio, VadOptions(min_silence_duration_ms=160, max_speech_duration_s=30))
    clips = []
    for p in pieces:
        start, end = p["start"] / SR, p["end"] / SR
        if clips and start - clips[-1]["end"] <= MAX_GAP_SECONDS and end - clips[-1]["start"] <= 30:
            clips[-1]["end"] = end
        else:
            clips.append({"start": start, "end": end})
    return clips


def sentences(segments):
    """Re-cut decoded segments into sentences using word timestamps."""
    out, words = [], []

    def flush():
        if words:
            text = "".join(w.word for w in words).strip()
            if text:
                out.append({"start": round(words[0].start, 3), "end": round(words[-1].end, 3), "text": text})
            words.clear()

    for segment in segments:
        for w in segment.words or []:
            words.append(w)
            if w.word.rstrip().endswith((".", "?", "!", "…")) or w.end - words[0].start >= MAX_SEGMENT_SECONDS:
                flush()
        flush()  # Never join words across decoded chunks.
    return out


def check_key(authorization: str = Header(default="")):
    if API_KEY and not secrets.compare_digest(authorization, f"Bearer {API_KEY}"):
        raise HTTPException(status_code=401, detail="invalid or missing API key")


@app.get("/health")
def health():
    return {"status": "ok", "model": MODEL, "device": DEVICE, "compute_type": COMPUTE_TYPE,
            "batch_size": BATCH_SIZE, "faster_whisper": fw_version, "busy": _busy.locked()}


@app.post("/transcribe", dependencies=[Depends(check_key)])
def transcribe(file: UploadFile = File(...), language: str = Form(default="")):
    with tempfile.NamedTemporaryFile(suffix=os.path.splitext(file.filename or "")[1]) as tmp:
        size = 0
        while chunk := file.file.read(1 << 20):
            size += len(chunk)
            if size > MAX_UPLOAD_MB << 20:
                raise HTTPException(status_code=413, detail=f"file larger than {MAX_UPLOAD_MB} MB")
            tmp.write(chunk)
        tmp.flush()
        try:
            audio = decode_audio(tmp.name, sampling_rate=SR)
        except Exception as e:  # Not decodable audio: the client sent something wrong, do not retry.
            raise HTTPException(status_code=422, detail=f"cannot decode audio: {e}")

    duration = len(audio) / SR
    with _busy:
        start = time.perf_counter()
        clips = speech_clips(audio)
        if clips:
            segments, info = pipeline.transcribe(audio, language=language or None, beam_size=BEAM_SIZE,
                                                 batch_size=BATCH_SIZE, word_timestamps=True,
                                                 vad_filter=False, clip_timestamps=clips)
            segs, detected = sentences(segments), info.language
        else:  # No speech at all: nothing to decode (the pipeline would fail without clips).
            segs, detected = [], language or None
        elapsed = time.perf_counter() - start
    log.info("%s: %.1fs of audio in %.1fs (%d segments, %s)", file.filename, duration, elapsed, len(segs), detected)
    return {"language": detected, "duration": round(duration, 3), "text": " ".join(s["text"] for s in segs),
            "segments": segs, "model": f"faster-whisper {fw_version} {MODEL} {COMPUTE_TYPE}",
            "processing_seconds": round(elapsed, 3)}
