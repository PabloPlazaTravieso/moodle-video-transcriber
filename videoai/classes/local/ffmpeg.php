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

use moodle_exception;

/**
 * Thin wrapper around the ffmpeg and ffprobe binaries.
 *
 * Commands are always built as argument arrays and passed to proc_open() without a shell,
 * so file names never need escaping and cannot inject shell syntax.
 *
 * @package    mod_videoai
 * @copyright  2026 Awakelab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ffmpeg {

    /** @var string[] Container extensions that accept the faststart flag. */
    private const FASTSTART_EXTENSIONS = ['mp4', 'm4v', 'mov'];

    /**
     * Constructor.
     *
     * @param string $ffmpegpath Full path to the ffmpeg executable.
     * @param string $ffprobepath Full path to the ffprobe executable.
     * @param int $timeout Maximum seconds a single command may run.
     */
    public function __construct(
        /** @var string Full path to the ffmpeg executable. */
        private readonly string $ffmpegpath,
        /** @var string Full path to the ffprobe executable. */
        private readonly string $ffprobepath,
        /** @var int Maximum seconds a single command may run. */
        private readonly int $timeout = 3600,
    ) {
    }

    /**
     * Build an instance from the plugin settings.
     *
     * @param int|null $timeout Seconds a command may run; defaults to the "timeout" setting.
     * @return self
     * @throws moodle_exception If the binaries are not configured or not executable.
     */
    public static function from_config(?int $timeout = null): self {
        $config = get_config('mod_videoai');
        $ffmpegpath = trim((string) ($config->pathtoffmpeg ?? ''));
        $ffprobepath = trim((string) ($config->pathtoffprobe ?? ''));
        foreach ([$ffmpegpath, $ffprobepath] as $path) {
            if ($path === '' || !is_file($path) || !is_executable($path)) {
                throw new moodle_exception('errorffmpegnotconfigured', 'mod_videoai', '', null, $path);
            }
        }
        $timeout = $timeout ?? (int) ($config->timeout ?? 3600);
        return new self($ffmpegpath, $ffprobepath, $timeout > 0 ? $timeout : 3600);
    }

    /**
     * Inspect a media file.
     *
     * @param string $input Path to the media file.
     * @return array{duration: ?float, hasaudio: bool, hasvideo: bool}
     */
    public function probe(string $input): array {
        $output = $this->run(self::probe_command($this->ffprobepath, $input));
        $info = json_decode($output, true);
        if (!is_array($info)) {
            throw new moodle_exception('errorprobe', 'mod_videoai', '', null, $output);
        }
        $types = array_column($info['streams'] ?? [], 'codec_type');
        $duration = $info['format']['duration'] ?? null;
        return [
            'duration' => is_numeric($duration) ? (float) $duration : null,
            'hasaudio' => in_array('audio', $types, true),
            'hasvideo' => in_array('video', $types, true),
        ];
    }

    /**
     * Write the first audio track of $input to $output as uncompressed PCM WAV.
     *
     * @param string $input Source media file.
     * @param string $output Destination .wav file.
     * @param int $samplerate Sample rate in Hz (16000 is what Whisper-style models expect).
     * @param int $channels Number of audio channels (1 = mono).
     */
    public function extract_audio(string $input, string $output, int $samplerate = 16000, int $channels = 1): void {
        $this->run(self::audio_command($this->ffmpegpath, $input, $output, $samplerate, $channels));
    }

    /**
     * Write the first video track of $input to $output without any audio, copying the stream (no re-encode).
     *
     * @param string $input Source media file.
     * @param string $output Destination file; should keep the source container extension.
     */
    public function extract_video_only(string $input, string $output): void {
        $this->run(self::video_only_command($this->ffmpegpath, $input, $output));
    }

    /**
     * Whether this ffmpeg build includes the Whisper speech-to-text filter (FFmpeg 8+ built with --enable-whisper).
     *
     * @return bool
     */
    public function has_whisper(): bool {
        $filters = $this->run([$this->ffmpegpath, '-hide_banner', '-filters']);
        return (bool) preg_match('/^\s*\S+\s+whisper\s/m', $filters);
    }

    /**
     * Transcribe an audio file with the Whisper filter, writing SRT subtitles to $output.
     *
     * @param string $input Audio file (the WAV produced by extract_audio()).
     * @param string $output Destination .srt file.
     * @param array $options model (path, required), vadmodel (path), vadsilence (seconds), language, queue (seconds),
     *     threads.
     */
    public function transcribe(string $input, string $output, array $options): void {
        $this->run(self::transcribe_command($this->ffmpegpath, $input, $output, $options));
    }

    /**
     * ffmpeg command that runs the Whisper filter over the audio and discards the audio itself.
     *
     * SRT rather than the filter's JSON output: the filter writes the text into its JSON without escaping it,
     * so a quote in what was said produces invalid JSON.
     *
     * @param string $ffmpeg Path to ffmpeg.
     * @param string $input Audio file.
     * @param string $output Destination .srt file.
     * @param array $options model, vadmodel, vadsilence, language, queue, threads (see transcribe()).
     * @return string[]
     */
    public static function transcribe_command(string $ffmpeg, string $input, string $output, array $options): array {
        $args = [
            'model' => $options['model'],
            'language' => ($options['language'] ?? '') !== '' ? $options['language'] : 'auto',
            // Whisper sees the audio in pieces of this length. The 3 s default loses context; 30 s, a full Whisper
            // window, made it drop the rest of a piece after the first sentence in the spike. 25 s avoids both.
            'queue' => (string) max(1, (int) ($options['queue'] ?? 25)),
            'use_gpu' => 'false',
            'destination' => $output,
            'format' => 'srt',
        ];
        if (!empty($options['vadmodel'])) {
            // Voice activity detection: without it Whisper invents text over silence and music.
            $args['vad_model'] = $options['vadmodel'];
            // Only cut at pauses this long. Each cut is decoded as a full 30 s Whisper window, so the 0.5 s default
            // makes many short pieces: 2 s was 2.5x faster in the spike with the same text.
            $args['vad_min_silence_duration'] = (string) (float) ($options['vadsilence'] ?? 2);
        }
        $filter = 'whisper=' . implode(':', array_map(fn($k, $v) => $k . '=' . self::filter_escape($v),
            array_keys($args), $args));

        $command = [$ffmpeg, '-nostdin', '-hide_banner', '-loglevel', 'error'];
        // Without -filter_threads the filter runs on very few threads (3x slower on 8 cores in the spike).
        $threads = (int) ($options['threads'] ?? 0) ?: self::cpu_count();
        array_push($command, '-filter_threads', (string) $threads);
        array_push($command, '-i', $input, '-vn', '-af', $filter, '-f', 'null', '-');
        return $command;
    }

    /**
     * Number of CPU cores, for the default number of transcription threads.
     *
     * @return int
     */
    public static function cpu_count(): int {
        if (is_readable('/proc/cpuinfo')) {
            $count = preg_match_all('/^processor\s*:/m', (string) file_get_contents('/proc/cpuinfo'));
        } else {
            $count = (int) getenv('NUMBER_OF_PROCESSORS');
        }
        return $count > 0 ? $count : 4;
    }

    /**
     * Escape an option value for use inside an -af filter graph.
     *
     * FFmpeg parses it twice: first the graph (where [ ] , ; separate filters) and then the filter's own
     * options (where : and = separate them), so the value is escaped for the inner level and then again
     * for the outer one, as described in "Notes on filtergraph escaping" of the FFmpeg documentation.
     *
     * @param string $value
     * @return string
     */
    public static function filter_escape(string $value): string {
        $inner = addcslashes($value, "\\':=");
        return addcslashes($inner, "\\'[],;");
    }

    /**
     * ffprobe command that prints format and stream information as JSON.
     *
     * @param string $ffprobe Path to ffprobe.
     * @param string $input Media file.
     * @return string[]
     */
    public static function probe_command(string $ffprobe, string $input): array {
        return [
            $ffprobe, '-v', 'error',
            '-show_entries', 'format=duration:stream=codec_type',
            '-of', 'json',
            $input,
        ];
    }

    /**
     * ffmpeg command that extracts the audio track to PCM WAV.
     *
     * @param string $ffmpeg Path to ffmpeg.
     * @param string $input Source media file.
     * @param string $output Destination .wav file.
     * @param int $samplerate Sample rate in Hz.
     * @param int $channels Number of channels.
     * @return string[]
     */
    public static function audio_command(string $ffmpeg, string $input, string $output, int $samplerate,
            int $channels): array {
        return [
            $ffmpeg, '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
            '-i', $input,
            '-map', '0:a:0', '-vn', '-sn', '-dn',
            '-ac', (string) $channels,
            '-ar', (string) $samplerate,
            '-c:a', 'pcm_s16le',
            $output,
        ];
    }

    /**
     * ffmpeg command that copies the video track and drops audio, subtitles and data streams.
     *
     * @param string $ffmpeg Path to ffmpeg.
     * @param string $input Source media file.
     * @param string $output Destination file.
     * @return string[]
     */
    public static function video_only_command(string $ffmpeg, string $input, string $output): array {
        $command = [
            $ffmpeg, '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
            '-i', $input,
            '-map', '0:v:0', '-an', '-sn', '-dn',
            '-c:v', 'copy',
        ];
        if (in_array(strtolower(pathinfo($output, PATHINFO_EXTENSION)), self::FASTSTART_EXTENSIONS, true)) {
            // Moves the index to the start of the file so browsers can play it while downloading.
            array_push($command, '-movflags', '+faststart');
        }
        $command[] = $output;
        return $command;
    }

    /**
     * Run a command and return its standard output.
     *
     * Output goes to temporary files rather than pipes: reading pipes without blocking is not
     * portable to Windows, and ffmpeg can write enough to stderr to fill a pipe buffer.
     *
     * @param string[] $command Executable followed by its arguments.
     * @return string Standard output.
     * @throws moodle_exception On a non-zero exit code or timeout.
     */
    protected function run(array $command): string {
        $stdoutfile = tempnam(sys_get_temp_dir(), 'vai');
        $stderrfile = tempnam(sys_get_temp_dir(), 'vai');
        try {
            // Silenced: a missing executable is reported through the exception below instead of a PHP warning.
            $process = @proc_open($command, [
                0 => ['pipe', 'r'],
                1 => ['file', $stdoutfile, 'w'],
                2 => ['file', $stderrfile, 'w'],
            ], $pipes, null, null, ['bypass_shell' => true]);
            if (!is_resource($process)) {
                throw new moodle_exception('errorffmpegfailed', 'mod_videoai', '', null, 'proc_open failed: ' . $command[0]);
            }
            fclose($pipes[0]);

            $deadline = microtime(true) + $this->timeout;
            // Poll quickly at first so short commands such as ffprobe return fast, then back off.
            $sleep = 10000;
            do {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                if (microtime(true) > $deadline) {
                    proc_terminate($process);
                    proc_close($process);
                    throw new moodle_exception('errorffmpegtimeout', 'mod_videoai', '', $this->timeout);
                }
                usleep($sleep);
                $sleep = min($sleep * 2, 200000);
            } while (true);

            // The exit code is only reported by the first proc_get_status() call that sees the process stopped.
            $exitcode = $status['exitcode'];
            proc_close($process);

            if ($exitcode !== 0) {
                $stderr = trim((string) file_get_contents($stderrfile));
                throw new moodle_exception('errorffmpegfailed', 'mod_videoai', '', null,
                    "exit code {$exitcode}: " . \core_text::substr($stderr, -2000));
            }
            return (string) file_get_contents($stdoutfile);
        } finally {
            @unlink($stdoutfile);
            @unlink($stderrfile);
        }
    }
}
