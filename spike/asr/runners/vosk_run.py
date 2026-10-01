import json, os, sys, wave
sys.path.insert(0, "/asr")
import common
from vosk import Model, KaldiRecognizer, SetLogLevel
from importlib.metadata import version

a = common.args()
MODEL = os.environ.get("VOSK_MODEL", "/models/vosk/vosk-model-small-es-0.42")
SetLogLevel(-1)


def load():
    return Model(MODEL)


def transcribe(model, path):
    wf = wave.open(path)
    rec = KaldiRecognizer(model, wf.getframerate())
    rec.SetWords(True)
    segs = []

    def add(res):
        r = json.loads(res)
        if r.get("text"):
            words = r.get("result", [])
            segs.append({"start": words[0]["start"] if words else 0, "end": words[-1]["end"] if words else 0,
                         "text": r["text"]})

    while True:
        data = wf.readframes(8000)
        if not data:
            break
        if rec.AcceptWaveform(data):
            add(rec.Result())
    add(rec.FinalResult())
    return " ".join(s["text"] for s in segs), segs


common.run(a, f"vosk {version('vosk')}", os.path.basename(MODEL), load, transcribe)
