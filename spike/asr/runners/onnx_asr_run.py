import os, sys
sys.path.insert(0, "/asr")
import common
import onnx_asr
import onnxruntime as ort
from importlib.metadata import version

a = common.args()
MODEL = os.environ.get("ONNX_MODEL", "nemo-parakeet-tdt-0.6b-v3")
QUANT = os.environ.get("ONNX_QUANT", "int8") or None


def load():
    opts = ort.SessionOptions()
    opts.intra_op_num_threads = a.threads
    kwargs = {"quantization": QUANT, "sess_options": opts}
    model = onnx_asr.load_model(MODEL, **kwargs)
    # These models accept 20-30 s per call; long audio is split on speech with Silero VAD, as the library documents.
    vad = onnx_asr.load_vad("silero", sess_options=opts)
    model = model.with_vad(vad)
    if "canary" in MODEL:
        return lambda p: model.recognize(p, language="es")
    return lambda p: model.recognize(p)


def transcribe(recognize, path):
    segs = [{"start": float(s.start), "end": float(s.end), "text": s.text} for s in recognize(path)]
    return " ".join(s["text"] for s in segs), segs


common.run(a, f"onnx-asr {version('onnx-asr')}", f"{MODEL} ({QUANT or 'fp32'}) + Silero VAD", load, transcribe)
