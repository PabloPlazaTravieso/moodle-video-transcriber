# Moodle PHP image + FFmpeg 8 built with the Whisper filter, so mod_videoai can both separate the audio
# and transcribe it with the same binary. Debian's ffmpeg (5.1) has no Whisper support.
ARG BASE=moodlehq/moodle-php-apache:8.3

FROM ${BASE} AS build
ARG WHISPER_TAG=v1.9.4
ARG FFMPEG_VERSION=8.1.3
RUN apt-get update && apt-get install -y --no-install-recommends \
        build-essential cmake git pkg-config nasm curl ca-certificates xz-utils libx264-dev \
    && rm -rf /var/lib/apt/lists/*
# whisper.cpp as shared libraries in /usr/local, with its pkg-config file for FFmpeg's configure.
# GGML_NATIVE optimises for this CPU; build with -DGGML_NATIVE=OFF for images that move between machines.
RUN git clone --depth 1 --branch ${WHISPER_TAG} https://github.com/ggml-org/whisper.cpp /src/whisper.cpp \
    && cmake -S /src/whisper.cpp -B /src/whisper.cpp/build -DCMAKE_BUILD_TYPE=Release -DGGML_NATIVE=ON \
        -DBUILD_SHARED_LIBS=ON -DWHISPER_BUILD_EXAMPLES=OFF -DWHISPER_BUILD_TESTS=OFF \
    && cmake --build /src/whisper.cpp/build -j \
    && cmake --install /src/whisper.cpp/build --prefix /usr/local
RUN curl -sfL https://ffmpeg.org/releases/ffmpeg-${FFMPEG_VERSION}.tar.xz | tar -xJ -C /src \
    && cd /src/ffmpeg-${FFMPEG_VERSION} \
    && PKG_CONFIG_PATH=/usr/local/lib/pkgconfig ./configure --prefix=/usr/local --disable-doc --disable-debug \
        --enable-gpl --enable-libx264 --enable-whisper \
    && make -j"$(nproc)" && make install

FROM ${BASE}
RUN apt-get update && apt-get install -y --no-install-recommends libx264-164 libgomp1 \
    && rm -rf /var/lib/apt/lists/*
COPY --from=build /usr/local/bin/ffmpeg /usr/local/bin/ffprobe /usr/local/bin/
COPY --from=build /usr/local/lib/ /usr/local/lib/
RUN ldconfig && ln -s /usr/local/bin/ffmpeg /usr/bin/ffmpeg && ln -s /usr/local/bin/ffprobe /usr/bin/ffprobe \
    && ffmpeg -hide_banner -filters | grep -q ' whisper ' && ffmpeg -version | head -1
