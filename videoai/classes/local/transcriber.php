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

use context_module;
use moodle_exception;
use stored_file;

/**
 * Transcribes the separated audio of an activity and stores the result.
 *
 * Two engines, chosen in the plugin settings:
 *  - ffmpeg (default): the Whisper filter of FFmpeg 8+ (whisper.cpp inside), run on the Moodle server by the same
 *    ffmpeg binary that separates the audio. Needs ffmpeg built with --enable-whisper and a whisper.cpp model.
 *  - service: the transcriber/ HTTP service (faster-whisper), for high volumes or a separate GPU server. It answers
 *    POST {url}/transcribe with {"language", "duration", "segments": [{start, end, text}], "model"}.
 *
 * @package    mod_videoai
 * @copyright  2026 Awakelab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class transcriber {

    /** No transcript (transcription disabled, or no audio yet). */
    public const STATUS_NONE = 0;
    /** Waiting for the adhoc task. */
    public const STATUS_QUEUED = 1;
    /** Waiting for the transcription service. */
    public const STATUS_PROCESSING = 2;
    /** Transcript stored. */
    public const STATUS_DONE = 3;
    /** Transcription failed; see transcriptmessage. */
    public const STATUS_ERROR = 4;

    /** Engine: FFmpeg whisper filter on the Moodle server. */
    public const ENGINE_FFMPEG = 'ffmpeg';
    /** Engine: external transcriber service. */
    public const ENGINE_SERVICE = 'service';

    /**
     * Whether transcription is enabled and configured.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        $config = get_config('mod_videoai');
        if (empty($config->transcribe)) {
            return false;
        }
        return self::engine() === self::ENGINE_SERVICE
            ? trim((string) ($config->transcriberurl ?? '')) !== ''
            : trim((string) ($config->whispermodel ?? '')) !== '';
    }

    /**
     * The configured engine.
     *
     * @return string One of the ENGINE_ constants.
     */
    public static function engine(): string {
        return get_config('mod_videoai', 'engine') === self::ENGINE_SERVICE ? self::ENGINE_SERVICE : self::ENGINE_FFMPEG;
    }

    /**
     * Queue the transcription task of an activity, if transcription is enabled.
     *
     * @param int $videoaiid
     * @param bool $force Transcribe even if the stored transcript matches the current audio.
     */
    public static function queue(int $videoaiid, bool $force = false): void {
        if (!self::is_enabled()) {
            return;
        }
        $task = new \mod_videoai\task\transcribe_audio();
        $task->set_custom_data(['videoaiid' => $videoaiid, 'force' => $force]);
        \core\task\manager::queue_adhoc_task($task);
        self::set_status($videoaiid, self::STATUS_QUEUED);
    }

    /**
     * Transcribe the audio of an activity.
     *
     * Errors caused by the request (bad audio, rejected API key...) are recorded and not retried. When the
     * service is unreachable or fails on its side, the error is recorded and rethrown so the task is retried.
     *
     * @param int $videoaiid
     * @param bool $force
     * @throws transcriber_unavailable_exception When the service should be tried again later.
     * @throws moodle_exception When another run holds the lock.
     */
    public static function process(int $videoaiid, bool $force = false): void {
        global $DB;

        $lock = \core\lock\lock_config::get_lock_factory('mod_videoai')->get_lock('transcribe_' . $videoaiid, 10);
        if (!$lock) {
            throw new moodle_exception('errorlocked', 'mod_videoai');
        }
        try {
            $videoai = $DB->get_record('videoai', ['id' => $videoaiid]);
            if (!$videoai) {
                return;
            }
            $cm = get_coursemodule_from_instance('videoai', $videoai->id, $videoai->course, false, MUST_EXIST);
            $context = context_module::instance($cm->id);

            $audio = processor::get_area_file($context, 'audio');
            if (!$audio) {
                transcript::clear($videoai->id, $context);
                self::set_status($videoai->id, self::STATUS_NONE, null, ['transcripthash' => null]);
                return;
            }
            $hash = $audio->get_contenthash();
            if (!$force && $videoai->transcripthash === $hash && (int) $videoai->timetranscribed > 0
                    && $DB->record_exists('videoai_segments', ['videoaiid' => $videoai->id])) {
                // Up to date: undo the "queued" status set when this run was queued.
                self::set_status($videoai->id, self::STATUS_DONE);
                return;
            }

            self::set_status($videoai->id, self::STATUS_PROCESSING);
            try {
                $result = self::engine() === self::ENGINE_SERVICE ? self::request($audio) : self::transcribe_with_ffmpeg($audio);
            } catch (\Throwable $e) {
                $message = $e->getMessage();
                if ($e instanceof moodle_exception && $e->debuginfo) {
                    $message .= "\n" . $e->debuginfo;
                }
                mtrace("mod_videoai: transcription of instance {$videoai->id} failed: {$message}");
                // As with the audio: a transcript of a previous, different audio must not survive.
                if ($videoai->transcripthash !== $hash) {
                    transcript::clear($videoai->id, $context);
                }
                self::set_status($videoai->id, self::STATUS_ERROR, $message, ['transcripthash' => $hash]);
                if ($e instanceof transcriber_unavailable_exception) {
                    throw $e;
                }
                return;
            }

            // Both engines can invent stock phrases over music; drop segments made only of them.
            $result['segments'] = transcript::remove_hallucinations($result['segments']);
            $basename = preg_replace('/-audio$/', '', pathinfo($audio->get_filename(), PATHINFO_FILENAME)) . '-transcript';
            transcript::save($videoai, $context, $result['segments'], $basename, [
                'language' => $result['language'],
                'duration' => $result['duration'] ?? ($videoai->duration !== null ? (float) $videoai->duration : null),
                'model' => $result['model'],
            ]);
            self::set_status($videoai->id, self::STATUS_DONE, null, [
                'transcripthash' => $hash,
                'transcriptlanguage' => substr((string) $result['language'], 0, 10),
                'transcriptmodel' => \core_text::substr($result['model'], 0, 255),
                'timetranscribed' => time(),
            ]);
        } finally {
            $lock->release();
        }
    }

    /**
     * Transcribe an audio file with FFmpeg's whisper filter on this server.
     *
     * Failures are not retried: they come from the configuration (no whisper filter, missing model) or from
     * the audio itself, and repeating a CPU-heavy run would not change the outcome.
     *
     * @param stored_file $audio
     * @return array{language: ?string, duration: ?float, model: string, segments: array}
     * @throws moodle_exception
     */
    public static function transcribe_with_ffmpeg(stored_file $audio): array {
        $workdir = make_temp_directory('mod_videoai/' . uniqid('', true));
        try {
            $wav = $workdir . '/audio.wav';
            $audio->copy_content_to($wav);
            return self::run_whisper_filter($wav);
        } finally {
            fulldelete($workdir);
        }
    }

    /**
     * Transcribe an audio file on disk with the configured engine, for callers outside this activity
     * (e.g. local_videotranscriber, which transcribes videos found in courses).
     *
     * @param string $path Audio file, ideally the 16 kHz mono WAV produced by ffmpeg::extract_audio().
     * @param string $filename Name sent to the external service.
     * @return array{language: ?string, duration: ?float, model: string, segments: array} Segments already
     *     cleaned with transcript::remove_hallucinations().
     * @throws transcriber_unavailable_exception When the external service should be tried again later.
     * @throws moodle_exception Any other failure.
     */
    public static function transcribe_audio_file(string $path, string $filename = 'audio.wav'): array {
        $result = self::engine() === self::ENGINE_SERVICE
            ? self::request_upload(new \CURLFile($path, 'audio/wav', $filename))
            : self::run_whisper_filter($path);
        $result['segments'] = transcript::remove_hallucinations($result['segments']);
        return $result;
    }

    /**
     * Run FFmpeg's whisper filter over a local audio file.
     *
     * @param string $wav
     * @return array{language: ?string, duration: ?float, model: string, segments: array}
     * @throws moodle_exception
     */
    protected static function run_whisper_filter(string $wav): array {
        $config = get_config('mod_videoai');
        $model = trim((string) ($config->whispermodel ?? ''));
        $vadmodel = trim((string) ($config->whispervadmodel ?? ''));
        foreach (array_filter([$model, $vadmodel]) as $path) {
            if (!is_readable($path)) {
                throw new moodle_exception('errorwhispermodel', 'mod_videoai', '', $path);
            }
        }
        $ffmpeg = ffmpeg::from_config((int) ($config->transcribertimeout ?? 7200) ?: 7200);
        if (!$ffmpeg->has_whisper()) {
            throw new moodle_exception('errornowhisperfilter', 'mod_videoai');
        }

        $workdir = make_temp_directory('mod_videoai/' . uniqid('', true));
        try {
            $srt = $workdir . '/transcript.srt';
            $language = trim((string) ($config->language ?? ''));
            $ffmpeg->transcribe($wav, $srt, [
                'model' => $model,
                'vadmodel' => $vadmodel,
                'vadsilence' => (float) ($config->whispervadsilence ?? 2),
                'language' => $language,
                'queue' => (int) ($config->whisperqueue ?? 25) ?: 25,
                'threads' => (int) ($config->whisperthreads ?? 0),
            ]);
            // No file means no speech was found at all.
            $segments = is_file($srt) ? transcript::parse_srt((string) file_get_contents($srt)) : [];
        } finally {
            fulldelete($workdir);
        }
        return [
            'language' => $language !== '' ? $language : null,
            'duration' => null,
            'model' => 'FFmpeg whisper ' . basename($model) . ($vadmodel !== '' ? ' + VAD' : ''),
            'segments' => $segments,
        ];
    }

    /**
     * Send an audio file to the service.
     *
     * @param stored_file $audio
     * @return array{language: ?string, duration: ?float, model: string, segments: array}
     * @throws transcriber_unavailable_exception Connection problems, timeouts and 5xx answers.
     * @throws moodle_exception Any other failure.
     */
    public static function request(stored_file $audio): array {
        return self::request_upload($audio);
    }

    /**
     * POST an audio file to the service.
     *
     * @param stored_file|\CURLFile $file Moodle turns both into a multipart file upload.
     * @return array{language: ?string, duration: ?float, model: string, segments: array}
     * @throws transcriber_unavailable_exception Connection problems, timeouts and 5xx answers.
     * @throws moodle_exception Any other failure.
     */
    protected static function request_upload(stored_file|\CURLFile $file): array {
        $config = get_config('mod_videoai');
        $url = rtrim(trim((string) $config->transcriberurl), '/') . '/transcribe';

        // The service URL is set by the site administrator and usually points to a host on the internal
        // network, which the curl security helper blocks by default (same approach as mlbackend_python).
        $curl = new \curl(['ignoresecurity' => true]);
        if (!empty($config->transcriberkey)) {
            $curl->setHeader('Authorization: Bearer ' . $config->transcriberkey);
        }
        $body = $curl->post($url, ['file' => $file, 'language' => (string) ($config->language ?? '')], [
            'CURLOPT_CONNECTTIMEOUT' => 15,
            'CURLOPT_TIMEOUT' => (int) ($config->transcribertimeout ?? 7200) ?: 7200,
        ]);

        $code = (int) ($curl->get_info()['http_code'] ?? 0);
        if ($curl->get_errno() || $code === 0 || $code >= 500) {
            $detail = $curl->get_errno() ? $curl->error : "HTTP {$code}: " . \core_text::substr((string) $body, 0, 500);
            throw new transcriber_unavailable_exception($url, $detail);
        }
        if ($code !== 200) {
            throw new moodle_exception('errortranscriberrejected', 'mod_videoai', '', $code,
                \core_text::substr((string) $body, 0, 1000));
        }
        return self::parse_response((string) $body);
    }

    /**
     * Validate the JSON answer of the service.
     *
     * @param string $body
     * @return array{language: ?string, duration: ?float, model: string, segments: array}
     * @throws moodle_exception If it is not the expected shape.
     */
    public static function parse_response(string $body): array {
        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['segments']) || !is_array($data['segments'])) {
            throw new moodle_exception('errortranscriberresponse', 'mod_videoai', '', null,
                \core_text::substr($body, 0, 500));
        }
        $segments = [];
        foreach ($data['segments'] as $s) {
            if (!is_array($s) || !is_numeric($s['start'] ?? null) || !is_numeric($s['end'] ?? null) || !isset($s['text'])) {
                throw new moodle_exception('errortranscriberresponse', 'mod_videoai', '', null, json_encode($s));
            }
            $text = trim((string) $s['text']);
            if ($text !== '') {
                $segments[] = ['start' => (float) $s['start'], 'end' => (float) $s['end'], 'text' => $text];
            }
        }
        return [
            'language' => isset($data['language']) ? (string) $data['language'] : null,
            'duration' => is_numeric($data['duration'] ?? null) ? (float) $data['duration'] : null,
            'model' => (string) ($data['model'] ?? ''),
            'segments' => $segments,
        ];
    }

    /**
     * Update the transcription status of an activity.
     *
     * @param int $videoaiid
     * @param int $status One of the STATUS_ constants.
     * @param string|null $message Error message, or null to clear it.
     * @param array $extra Further fields to update.
     */
    public static function set_status(int $videoaiid, int $status, ?string $message = null, array $extra = []): void {
        global $DB;
        $DB->update_record('videoai', (object) array_merge($extra, [
            'id' => $videoaiid,
            'transcriptstatus' => $status,
            'transcriptmessage' => $message,
        ]));
    }
}
