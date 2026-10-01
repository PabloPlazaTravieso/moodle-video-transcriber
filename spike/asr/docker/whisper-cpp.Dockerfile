FROM python:3.12-slim
ARG WCPP_TAG=v1.9.4
RUN apt-get update && apt-get install -y --no-install-recommends build-essential cmake git curl ca-certificates \
    && git clone --depth 1 --branch ${WCPP_TAG} https://github.com/ggml-org/whisper.cpp /opt/whisper.cpp \
    && cmake -S /opt/whisper.cpp -B /opt/whisper.cpp/build -DCMAKE_BUILD_TYPE=Release -DGGML_NATIVE=ON -DBUILD_SHARED_LIBS=OFF \
    && cmake --build /opt/whisper.cpp/build -j --config Release --target whisper-cli \
    && cp /opt/whisper.cpp/build/bin/whisper-cli /usr/local/bin/ \
    && rm -rf /var/lib/apt/lists/*
