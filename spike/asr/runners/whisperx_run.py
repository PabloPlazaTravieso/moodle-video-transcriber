import os, sys
sys.path.insert(0, "/asr")
import common
import torch
import whisperx
from importlib.metadata import version

a = common.args()
MODEL = os.environ.get("WX_MODEL", "large-v3-turbo")
torch.set_num_threads(a.threads)


def load():
    model = whisperx.load_model(MODEL, "cpu", compute_type="int8", language="es", threads=a.threads,
                                download_root="/models/faster-whisper")
    align, meta = whisperx.load_align_model(language_code="es", device="cpu", model_dir="/models/hf")
    return model, align, meta


def transcribe(engine, path):
    model, align, meta = engine
    audio = whisperx.load_audio(path)
    r = model.transcribe(audio, batch_size=8, language="es")
    r = whisperx.align(r["segments"], align, meta, audio, "cpu", return_char_alignments=False)
    segs = [{"start": s.get("start", 0), "end": s.get("end", 0), "text": s["text"]} for s in r["segments"]]
    return " ".join(s["text"] for s in segs), segs


common.run(a, f"whisperx {version('whisperx')}", f"{MODEL} int8 + wav2vec2 alignment (es)", load, transcribe)
