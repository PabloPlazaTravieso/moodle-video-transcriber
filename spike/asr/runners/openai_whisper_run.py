import os, sys
sys.path.insert(0, "/asr")
import common
import torch
import whisper

a = common.args()
MODEL = os.environ.get("OW_MODEL", "turbo")
torch.set_num_threads(a.threads)


def load():
    return whisper.load_model(MODEL, device="cpu", download_root="/models/openai-whisper")


def transcribe(model, path):
    r = model.transcribe(path, language="es", beam_size=5, fp16=False)
    segs = [{"start": s["start"], "end": s["end"], "text": s["text"]} for s in r["segments"]]
    return r["text"], segs


common.run(a, f"openai-whisper {whisper.__version__}", f"{MODEL} fp32, beam 5", load, transcribe)
