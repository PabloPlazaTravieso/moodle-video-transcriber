FROM python:3.12-slim
WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir --timeout 120 --retries 10 -r requirements.txt
COPY app.py .
ENV MODEL_DIR=/models
VOLUME /models
EXPOSE 8000
# A single worker: the model is loaded once per process and transcriptions are serialised anyway.
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000", "--workers", "1", "--timeout-keep-alive", "30"]
