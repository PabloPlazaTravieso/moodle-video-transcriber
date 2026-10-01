FROM python:3.12-slim
RUN pip install --no-cache-dir "onnx-asr[cpu,hub]==0.12.0"
