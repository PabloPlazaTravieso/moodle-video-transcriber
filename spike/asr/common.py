"""Shared harness for the transcription comparison: same inputs, same timing and memory measurement."""
import argparse
import json
import os
import resource
import sys
import time
import wave

REFERENCED = [
    "tts_01_clase_fotosintesis", "tts_02_tutoria_dos_voces", "tts_03_silencio_y_musica",
    "tts_04_clase_programacion_mx", "tts_05_clase_fotosintesis_ruido",
    "real_04_wikipedia_caracas", "real_07_wikipedia_dolores_cacuango",
]


def args():
    p = argparse.ArgumentParser()
    p.add_argument("--name", required=True, help="result folder name, e.g. faster-whisper-turbo")
    p.add_argument("--set", default="ref", choices=["ref", "all"], help="ref = files with a reference transcript")
    p.add_argument("--threads", type=int, default=int(os.environ.get("ASR_THREADS", "8")))
    p.add_argument("--audio-dir", default="/samples/audio")
    p.add_argument("--out", default="/asr/results")
    return p.parse_args()


def files(a):
    names = REFERENCED if a.set == "ref" else sorted(f[:-4] for f in os.listdir(a.audio_dir) if f.endswith(".wav"))
    return [(n, os.path.join(a.audio_dir, n + ".wav")) for n in names]


def duration(path):
    with wave.open(path) as w:
        return w.getnframes() / w.getframerate()


def peak_rss_mb(children=False):
    r = resource.getrusage(resource.RUSAGE_CHILDREN if children else resource.RUSAGE_SELF)
    return r.ru_maxrss / 1024  # KiB on Linux


def run(a, library, model, load, transcribe, children=False):
    """load() -> engine; transcribe(engine, path) -> (text, [{"start", "end", "text"}])."""
    out = os.path.join(a.out, a.name)
    os.makedirs(out, exist_ok=True)
    t = time.perf_counter()
    engine = load()
    load_s = time.perf_counter() - t
    total_audio = total_proc = 0.0
    for name, path in files(a):
        secs = duration(path)
        t = time.perf_counter()
        text, segments = transcribe(engine, path)
        proc = time.perf_counter() - t
        total_audio += secs
        total_proc += proc
        with open(os.path.join(out, name + ".json"), "w", encoding="utf-8") as f:
            json.dump({"file": name, "audio_seconds": secs, "seconds": proc, "text": text.strip(),
                       "segments": segments}, f, ensure_ascii=False, indent=1)
        print(f"{a.name:28} {name:38} audio {secs:6.1f}s  proc {proc:6.1f}s  RTF {proc / secs:5.3f}", flush=True)
    meta = {"name": a.name, "library": library, "model": model, "threads": a.threads, "load_seconds": load_s,
            "audio_seconds": total_audio, "proc_seconds": total_proc,
            "peak_rss_mb": max(peak_rss_mb(), peak_rss_mb(True)) if children else peak_rss_mb(),
            "python": sys.version.split()[0]}
    with open(os.path.join(out, "_meta.json"), "w", encoding="utf-8") as f:
        json.dump(meta, f, indent=1)
    print(json.dumps(meta), flush=True)
