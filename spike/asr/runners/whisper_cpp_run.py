import json, os, subprocess, sys
sys.path.insert(0, "/asr")
import common

a = common.args()
MODEL = os.environ.get("WCPP_MODEL", "/models/whisper.cpp/ggml-large-v3-turbo-q5_0.bin")


def load():
    # whisper-cli loads the model on every call; measure one warm-up call on a short file as "load".
    subprocess.run(["whisper-cli", "-m", MODEL, "-f", "/opt/whisper.cpp/samples/jfk.wav", "-t", str(a.threads), "-np"],
                   check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    return None


def transcribe(_, path):
    base = "/tmp/wcpp_out"
    subprocess.run(["whisper-cli", "-m", MODEL, "-f", path, "-l", "es", "-t", str(a.threads), "-bs", "5",
                    "-oj", "-of", base, "-np"], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    data = json.load(open(base + ".json", encoding="utf-8", errors="replace"))
    segs = [{"start": s["offsets"]["from"] / 1000, "end": s["offsets"]["to"] / 1000, "text": s["text"]}
            for s in data["transcription"]]
    return " ".join(s["text"] for s in segs), segs


version = subprocess.run(["git", "-C", "/opt/whisper.cpp", "describe", "--tags"], capture_output=True, text=True).stdout.strip()
common.run(a, f"whisper.cpp {version}", os.path.basename(MODEL) + ", beam 5", load, transcribe, children=True)
