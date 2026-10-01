FROM python:3.12-slim
RUN pip install --no-cache-dir faster-whisper==1.2.1 jiwer num2words
