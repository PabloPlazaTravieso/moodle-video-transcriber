"""Score every result folder in /asr/results against /samples/reference and print a comparison table.

Normalisation (same for reference and hypothesis): lower case, digits spelled out in Spanish (num2words),
punctuation removed, accents kept. For tts_03 the invented words are the insertions against the reference
(the only speech is before 5.4 s and after 70.6 s); the text of segments overlapping the gap is printed for inspection.
"""
import glob
import json
import os
import re
import sys
import unicodedata

import jiwer
from num2words import num2words

REF_DIR = "/samples/reference"
GAP = ("tts_03_silencio_y_musica", 5.4, 70.6)
SYNTH = ["tts_01_clase_fotosintesis", "tts_02_tutoria_dos_voces", "tts_03_silencio_y_musica",
         "tts_04_clase_programacion_mx", "tts_05_clase_fotosintesis_ruido"]
REAL = ["real_04_wikipedia_caracas", "real_07_wikipedia_dolores_cacuango"]


def spell(match):
    s = match.group(0).replace(".", "").replace(",", ".")
    try:
        return " " + num2words(float(s) if "." in s else int(s), lang="es") + " "
    except Exception:
        return " " + s + " "


def norm(text):
    text = unicodedata.normalize("NFC", text).lower()
    text = re.sub(r"\d+(?:[.,]\d+)*", spell, text)
    text = re.sub(r"[^\w\s]|_", " ", text)
    return re.sub(r"\s+", " ", text).strip()


def score(folder):
    rows = {}
    for path in glob.glob(os.path.join(folder, "*.json")):
        if path.endswith("_meta.json"):
            continue
        r = json.load(open(path, encoding="utf-8"))
        ref_path = os.path.join(REF_DIR, r["file"] + ".txt")
        if not os.path.exists(ref_path):
            continue
        ref, hyp = norm(open(ref_path, encoding="utf-8").read()), norm(r["text"])
        m = jiwer.process_words(ref, hyp if hyp else "<vacío>")
        row = {"wer": m.wer, "ins": m.insertions, "del": m.deletions, "sub": m.substitutions,
               "rtf": r["seconds"] / r["audio_seconds"], "segments": len(r.get("segments") or [])}
        if r["file"] == GAP[0]:
            row["gap_words"] = m.insertions
            row["gap_text"] = " | ".join(s["text"].strip() for s in r.get("segments") or []
                                         if s["start"] < GAP[2] and s["end"] > GAP[1])[:200]
        rows[r["file"]] = row
    return rows


def avg(rows, names):
    vals = [rows[n]["wer"] for n in names if n in rows]
    return sum(vals) / len(vals) if vals else float("nan")


results = []
for folder in sorted(glob.glob("/asr/results/*/")):
    meta_path = os.path.join(folder, "_meta.json")
    if not os.path.exists(meta_path):
        continue
    meta = json.load(open(meta_path))
    rows = score(folder)
    results.append((meta, rows))

print("| Librería / modelo | WER sintéticos | WER voz real | WER ruido | WER técnico (MX) | Palabras inventadas en silencio/música | RTF | Carga (s) | RAM pico (MB) | Tiempos |")
print("|---|---|---|---|---|---|---|---|---|---|")
for meta, rows in sorted(results, key=lambda x: avg(x[1], SYNTH + REAL)):
    g = rows.get(GAP[0], {})
    ts = "sí" if any(r["segments"] > 1 for r in rows.values()) else "no"
    print(f"| {meta['name']} | {avg(rows, SYNTH):.1%} | {avg(rows, REAL):.1%} | "
          f"{rows.get('tts_05_clase_fotosintesis_ruido', {}).get('wer', float('nan')):.1%} | "
          f"{rows.get('tts_04_clase_programacion_mx', {}).get('wer', float('nan')):.1%} | {g.get('gap_words', '-')} | "
          f"{meta['proc_seconds'] / meta['audio_seconds']:.3f} | {meta['load_seconds']:.0f} | {meta['peak_rss_mb']:.0f} | {ts} |")

if "-v" in sys.argv:
    for meta, rows in results:
        print(f"\n## {meta['name']} ({meta['library']}, {meta['model']})")
        for name, r in sorted(rows.items()):
            extra = f"  gap: {r['gap_words']} words «{r['gap_text']}»" if "gap_words" in r else ""
            print(f"  {name:38} WER {r['wer']:6.1%}  (sub {r['sub']}, del {r['del']}, ins {r['ins']})  RTF {r['rtf']:.3f}{extra}")
