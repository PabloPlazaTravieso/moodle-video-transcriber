<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_videoai\local;

/**
 * Tests for the ffmpeg wrapper. Tests that need real binaries are skipped unless
 * MOD_VIDEOAI_TEST_FFMPEG and MOD_VIDEOAI_TEST_FFPROBE point to them (in config.php or the environment).
 *
 * @package    mod_videoai
 * @category   test
 * @copyright  2026 Awakelab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_videoai\local\ffmpeg
 */
final class ffmpeg_test extends \advanced_testcase {

    public function test_audio_command(): void {
        $command = ffmpeg::audio_command('ffmpeg', 'in file.mp4', 'out.wav', 16000, 1);
        $this->assertSame('ffmpeg', $command[0]);
        $this->assertSame('in file.mp4', $command[array_search('-i', $command) + 1]);
        $this->assertSame('0:a:0', $command[array_search('-map', $command) + 1]);
        $this->assertSame('16000', $command[array_search('-ar', $command) + 1]);
        $this->assertSame('1', $command[array_search('-ac', $command) + 1]);
        $this->assertSame('pcm_s16le', $command[array_search('-c:a', $command) + 1]);
        $this->assertContains('-vn', $command);
        $this->assertSame('out.wav', end($command));
    }

    public function test_video_only_command(): void {
        $command = ffmpeg::video_only_command('ffmpeg', 'in.mp4', 'out.mp4');
        $this->assertContains('-an', $command);
        $this->assertSame('copy', $command[array_search('-c:v', $command) + 1]);
        $this->assertContains('+faststart', $command);
        $this->assertSame('out.mp4', end($command));

        // The faststart flag is only valid for MP4-family containers.
        $this->assertNotContains('+faststart', ffmpeg::video_only_command('ffmpeg', 'in.webm', 'out.webm'));
    }

    public function test_run_reports_failure(): void {
        // Any executable will do to exercise process handling; use the PHP binary running the tests.
        $wrapper = new class(PHP_BINARY, PHP_BINARY, 1) extends ffmpeg {
            public function call(array $command): string {
                return $this->run($command);
            }
        };
        $this->assertSame('ok', $wrapper->call([PHP_BINARY, '-r', 'echo "ok";']));

        try {
            $wrapper->call([PHP_BINARY, '-r', 'fwrite(STDERR, "boom"); exit(3);']);
            $this->fail('Expected an exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorffmpegfailed', $e->errorcode);
            $this->assertStringContainsString('exit code 3', $e->debuginfo);
            $this->assertStringContainsString('boom', $e->debuginfo);
        }

        try {
            $wrapper->call([PHP_BINARY, '-r', 'sleep(10);']);
            $this->fail('Expected an exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorffmpegtimeout', $e->errorcode);
        }
    }

    public function test_transcribe_command(): void {
        $command = ffmpeg::transcribe_command('ffmpeg', '/tmp/a b/audio.wav', '/tmp/out.srt', [
            'model' => '/models/ggml.bin', 'vadmodel' => '/models/vad.bin', 'language' => 'es',
            'queue' => 20, 'threads' => 4,
        ]);
        $this->assertSame('4', $command[array_search('-filter_threads', $command) + 1]);
        $this->assertSame('/tmp/a b/audio.wav', $command[array_search('-i', $command) + 1]);
        $this->assertSame('whisper=model=/models/ggml.bin:language=es:queue=20:use_gpu=false:'
            . 'destination=/tmp/out.srt:format=srt:vad_model=/models/vad.bin:vad_min_silence_duration=2', $command[array_search('-af', $command) + 1]);

        // Without language, VAD or threads: auto-detection, 25 s chunks, no vad_model and one thread per core.
        $command = ffmpeg::transcribe_command('ffmpeg', 'in.wav', 'out.srt', ['model' => 'm.bin']);
        $filter = $command[array_search('-af', $command) + 1];
        $this->assertStringContainsString('language=auto', $filter);
        $this->assertStringNotContainsString('vad_model', $filter);
        $this->assertStringContainsString('queue=25', $filter);
        $this->assertSame((string) ffmpeg::cpu_count(), $command[array_search('-filter_threads', $command) + 1]);
    }

    public function test_filter_escape(): void {
        // Both levels: option separators first, then filter graph separators (and the added backslashes).
        $this->assertSame('/plain/path.bin', ffmpeg::filter_escape('/plain/path.bin'));
        // C:\models -> inner level C\:\\models -> outer level C\\:\\\\models.
        $this->assertSame('C\\\\:\\\\\\\\models', ffmpeg::filter_escape('C:\\models'));
        $this->assertSame('a\,b\[c\]', ffmpeg::filter_escape('a,b[c]'));
    }

    public function test_split_real_video(): void {
        $ffmpegpath = defined('MOD_VIDEOAI_TEST_FFMPEG') ? MOD_VIDEOAI_TEST_FFMPEG : getenv('MOD_VIDEOAI_TEST_FFMPEG');
        $ffprobepath = defined('MOD_VIDEOAI_TEST_FFPROBE') ? MOD_VIDEOAI_TEST_FFPROBE : getenv('MOD_VIDEOAI_TEST_FFPROBE');
        if (!$ffmpegpath || !$ffprobepath) {
            $this->markTestSkipped('MOD_VIDEOAI_TEST_FFMPEG / MOD_VIDEOAI_TEST_FFPROBE not set.');
        }
        $ffmpeg = new ffmpeg($ffmpegpath, $ffprobepath);
        $dir = make_request_directory();

        // Generate a 2 second test video with a tone, so no binary fixture is needed.
        $source = "$dir/source.mp4";
        exec(escapeshellarg($ffmpegpath) . ' -nostdin -loglevel error -f lavfi -i testsrc=duration=2:size=160x120:rate=10'
            . ' -f lavfi -i sine=frequency=440:duration=2 -shortest -c:v libx264 -c:a aac '
            . escapeshellarg($source), $out, $code);
        $this->assertSame(0, $code);

        $info = $ffmpeg->probe($source);
        $this->assertTrue($info['hasaudio']);
        $this->assertTrue($info['hasvideo']);
        $this->assertEqualsWithDelta(2.0, $info['duration'], 0.2);

        $ffmpeg->extract_audio($source, "$dir/audio.wav");
        $audio = $ffmpeg->probe("$dir/audio.wav");
        $this->assertTrue($audio['hasaudio']);
        $this->assertFalse($audio['hasvideo']);

        $ffmpeg->extract_video_only($source, "$dir/video.mp4");
        $video = $ffmpeg->probe("$dir/video.mp4");
        $this->assertTrue($video['hasvideo']);
        $this->assertFalse($video['hasaudio']);
    }
}
