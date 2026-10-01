import os, sys, wave
import numpy as np
sys.path.insert(0, "/asr")
import common
from faster_whisper import BatchedInferencePipeline, WhisperModel, __version__

a = common.args()
MODEL = os.environ.get("FW_MODEL", "large-v3-turbo")
VAD = os.environ.get("FW_VAD", "1") == "1"
# FW_BATCH > 0 uses the batched pipeline (always VAD-segmented), the same approach WhisperX uses.
BATCH = int(os.environ.get("FW_BATCH", "0"))


def load():
    model = WhisperModel(MODEL, device="cpu", compute_type="int8", cpu_threads=a.threads, download_root="/models/faster-whisper")
    return BatchedInferencePipeline(model) if BATCH else model


def transcribe(model, path):
    # faster-whisper 1.2.1 decodes files with PyAV and breaks with PyAV >= 16 (metadata_errors argument);
    # the plugin WAV is already 16 kHz mono PCM, so pass the samples directly.
    with wave.open(path) as w:
        audio = np.frombuffer(w.readframes(w.getnframes()), dtype=np.int16).astype(np.float32) / 32768
    if BATCH:
        segments, _ = model.transcribe(audio, language="es", beam_size=5, batch_size=BATCH)
    else:
        segments, _ = model.transcribe(audio, language="es", beam_size=5, vad_filter=VAD)
    segs = [{"start": s.start, "end": s.end, "text": s.text} for s in segments]
    return " ".join(s["text"] for s in segs), segs


common.run(a, f"faster-whisper {__version__}", f"{MODEL} int8, beam 5, " + (f"batched x{BATCH} (VAD)" if BATCH else f"VAD {'on' if VAD else 'off'}"), load, transcribe)
