#!/bin/sh
# Generate synthetic test videos covering the formats teachers are likely to upload.
set -e
D=${VIDEOS_DIR:-/tmp/videos}; mkdir -p "$D"; cd "$D"
F="ffmpeg -nostdin -loglevel error -y"
V="-f lavfi -i testsrc2=size=1280x720:rate=30"
A="-f lavfi -i sine=frequency=440:sample_rate=48000"
A2="-f lavfi -i sine=frequency=880:sample_rate=48000"

$F $V:duration=60 $A:duration=60 -c:v libx264 -preset ultrafast -c:a aac -shortest h264_aac_1min.mp4
$F $V:duration=60 $A:duration=60 -c:v libx264 -preset ultrafast -c:a aac -shortest "clase 1 introducción á.mov"
$F -f lavfi -i testsrc2=size=640x360:rate=30:duration=60 $A:duration=60 -c:v libvpx-vp9 -deadline realtime -cpu-used 8 -c:a libopus -shortest vp9_opus_1min.webm
$F $V:duration=60 $A:duration=60 $A2:duration=60 -map 0 -map 1 -map 2 -c:v libx264 -preset ultrafast -c:a aac -shortest two_audio_tracks.mkv
$F -f lavfi -i testsrc2=size=640x360:rate=25:duration=60 $A:duration=60 -c:v mpeg4 -c:a libmp3lame -shortest mpeg4_mp3.avi
$F $V:duration=60 $A:duration=60 -c:v libx265 -preset ultrafast -tag:v hvc1 -c:a aac -shortest hevc_aac.mp4
$F $V:duration=30 -c:v libx264 -preset ultrafast no_audio.mp4
head -c 300000 h264_aac_1min.mp4 > truncated.mp4
# Long lecture-like files for timing: 10 min 720p and 60 min 360p.
$F $V:duration=600 $A:duration=600 -c:v libx264 -preset ultrafast -c:a aac -b:a 128k -shortest lecture_10min_720p.mp4
$F -f lavfi -i testsrc2=size=640x360:rate=25:duration=3600 $A:duration=3600 -c:v libx264 -preset ultrafast -crf 35 -c:a aac -b:a 96k -shortest lecture_60min_360p.mp4
ls -la "$D"
