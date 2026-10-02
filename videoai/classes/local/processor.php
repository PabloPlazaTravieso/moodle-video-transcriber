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
use stdClass;
use stored_file;

/**
 * Splits the uploaded video of an activity into an audio track and a silent video track.
 *
 * File areas (all itemid 0, in the module context):
 *  - video:     the source video uploaded by the teacher.
 *  - audio:     the extracted audio as WAV, ready for speech-to-text.
 *  - videoonly: the video track with the audio removed.
 *
 * @package    mod_videoai
 * @copyright  2026 Awakelab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class processor {

    /** No source video uploaded. */
    public const STATUS_NOVIDEO = 0;
    /** Waiting for the adhoc task to run. */
    public const STATUS_QUEUED = 1;
    /** The adhoc task is running ffmpeg. */
    public const STATUS_PROCESSING = 2;
    /** Audio (and video-only track, if enabled) are available. */
    public const STATUS_DONE = 3;
    /** Processing failed; see statusmessage. */
    public const STATUS_ERROR = 4;

    /** Component name used for file storage. */
    public const COMPONENT = 'mod_videoai';

    /** File areas written by the processor. */
    public const OUTPUT_AREAS = ['audio', 'videoonly'];

    /**
     * File manager options for the source video.
     *
     * @param int $maxbytes Maximum file size, 0 for the site limit.
     * @return array
     */
    public static function video_filemanager_options(int $maxbytes = 0): array {
        global $CFG;
        // FILE_INTERNAL lives here and is not loaded outside pages that render a file picker (e.g. cron, CLI).
        require_once($CFG->dirroot . '/repository/lib.php');
        return [
            'subdirs' => 0,
            'maxfiles' => 1,
            'maxbytes' => $maxbytes,
            'accepted_types' => ['video'],
            'return_types' => FILE_INTERNAL,
        ];
    }

    /**
     * The source video of an activity, if any.
     *
     * @param context_module $context
     * @return stored_file|null
     */
    public static function get_video_file(context_module $context): ?stored_file {
        return self::get_area_file($context, 'video');
    }

    /**
     * The single file stored in one of the plugin file areas, if any.
     *
     * @param context_module $context
     * @param string $filearea
     * @return stored_file|null
     */
    public static function get_area_file(context_module $context, string $filearea): ?stored_file {
        $files = get_file_storage()->get_area_files($context->id, self::COMPONENT, $filearea, 0, 'id', false);
        return $files ? reset($files) : null;
    }

    /**
     * Called after the activity is saved: queue processing if the source video is new or changed.
     *
     * @param stdClass $videoai Activity record.
     * @param context_module $context
     */
    public static function video_saved(stdClass $videoai, context_module $context): void {
        $file = self::get_video_file($context);
        if (!$file) {
            self::clear_outputs($context);
            self::clear_transcript($videoai->id, $context);
            self::set_status($videoai->id, self::STATUS_NOVIDEO, null, ['videohash' => null, 'duration' => null]);
            return;
        }
        $unchanged = $file->get_contenthash() === $videoai->videohash
            && in_array((int) $videoai->status, [self::STATUS_QUEUED, self::STATUS_PROCESSING, self::STATUS_DONE], true);
        if (!$unchanged) {
            self::queue($videoai->id);
        }
    }

    /**
     * Called after an activity is restored from a backup: requeue the work the backup did not finish.
     *
     * A backup taken while a task was queued or running holds that transient status, and a backup made
     * without files has none of the outputs the status claims. Up-to-date outputs are kept as restored.
     *
     * @param int $videoaiid Restored activity instance id.
     * @param context_module $context Its module context.
     */
    public static function restored(int $videoaiid, context_module $context): void {
        global $DB;
        $videoai = $DB->get_record('videoai', ['id' => $videoaiid], '*', MUST_EXIST);
        $file = self::get_video_file($context);
        if (!$file) {
            self::clear_outputs($context);
            self::clear_transcript($videoaiid, $context);
            self::set_status($videoaiid, self::STATUS_NOVIDEO, null, ['videohash' => null, 'duration' => null]);
            return;
        }
        $separated = (int) $videoai->status === self::STATUS_DONE && $videoai->videohash === $file->get_contenthash()
            && self::get_area_file($context, 'audio');
        if (!$separated) {
            if ((int) $videoai->status !== self::STATUS_ERROR || $videoai->videohash !== $file->get_contenthash()) {
                // Processing queues the transcription when it is done.
                self::queue($videoaiid, true);
            }
            return;
        }
        $transcribed = (int) $videoai->transcriptstatus === transcriber::STATUS_DONE
            && $DB->record_exists('videoai_segments', ['videoaiid' => $videoaiid]);
        if (!$transcribed && in_array((int) $videoai->transcriptstatus,
                [transcriber::STATUS_QUEUED, transcriber::STATUS_PROCESSING, transcriber::STATUS_DONE], true)) {
            // Reset first: queue() does nothing when transcription is disabled on this site.
            transcriber::set_status($videoaiid, transcriber::STATUS_NONE);
            transcriber::queue($videoaiid);
        }
    }

    /**
     * Queue the adhoc task that processes an activity.
     *
     * @param int $videoaiid Activity instance id.
     * @param bool $force Reprocess even if the outputs are up to date.
     */
    public static function queue(int $videoaiid, bool $force = false): void {
        $task = new \mod_videoai\task\process_video();
        $task->set_custom_data(['videoaiid' => $videoaiid, 'force' => $force]);
        // No de-duplication: a queued run that finds its work already done is a cheap no-op,
        // whereas de-duplicating against a task that is already running would lose a newer upload.
        \core\task\manager::queue_adhoc_task($task);
        self::set_status($videoaiid, self::STATUS_QUEUED);
    }

    /**
     * Split the source video of an activity. Errors are recorded on the activity rather than thrown.
     *
     * @param int $videoaiid Activity instance id.
     * @param bool $force Reprocess even if the outputs are up to date.
     * @throws moodle_exception Only if another run holds the lock, so the task is retried later.
     */
    public static function process(int $videoaiid, bool $force = false): void {
        global $DB;

        $lockfactory = \core\lock\lock_config::get_lock_factory('mod_videoai');
        $lock = $lockfactory->get_lock('process_' . $videoaiid, 10);
        if (!$lock) {
            throw new moodle_exception('errorlocked', 'mod_videoai');
        }

        try {
            $videoai = $DB->get_record('videoai', ['id' => $videoaiid]);
            if (!$videoai) {
                // The activity was deleted after the task was queued.
                return;
            }
            $cm = get_coursemodule_from_instance('videoai', $videoai->id, $videoai->course, false, MUST_EXIST);
            $context = context_module::instance($cm->id);

            $file = self::get_video_file($context);
            if (!$file) {
                self::clear_outputs($context);
                self::clear_transcript($videoai->id, $context);
                self::set_status($videoai->id, self::STATUS_NOVIDEO, null, ['videohash' => null, 'duration' => null]);
                return;
            }
            if (!$force && $videoai->videohash === $file->get_contenthash() && (int) $videoai->timeprocessed > 0
                    && self::get_area_file($context, 'audio')) {
                // Up to date: undo the "queued" status set when this run was queued.
                self::set_status($videoai->id, self::STATUS_DONE);
                return;
            }

            self::set_status($videoai->id, self::STATUS_PROCESSING);
            try {
                $duration = self::split($file, $context);
                self::set_status($videoai->id, self::STATUS_DONE, null, [
                    'videohash' => $file->get_contenthash(),
                    'duration' => $duration,
                    'timeprocessed' => time(),
                ]);
                // Transcribe the new audio. Re-running the same video yields a byte-identical WAV, which the
                // transcription task recognises by its hash and skips: transcribing is the expensive step, so
                // forcing the separation does not force it (the page has its own "transcribe again" action).
                transcriber::queue($videoai->id);
            } catch (\Throwable $e) {
                $message = $e->getMessage();
                if ($e instanceof moodle_exception && $e->debuginfo) {
                    $message .= "\n" . $e->debuginfo;
                }
                mtrace("mod_videoai: processing of instance {$videoai->id} failed: {$message}");
                $extra = ['videohash' => $file->get_contenthash()];
                // Outputs of a previous, different video must not survive next to the new one:
                // later phases would transcribe the wrong audio. A failed rerun of the same video keeps them.
                $outputsmatch = (int) $videoai->status === self::STATUS_DONE && $videoai->videohash === $file->get_contenthash();
                if (!$outputsmatch) {
                    self::clear_outputs($context);
                    self::clear_transcript($videoai->id, $context);
                    $extra['duration'] = null;
                }
                self::set_status($videoai->id, self::STATUS_ERROR, $message, $extra);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Run ffmpeg on the source file and replace the output files.
     *
     * @param stored_file $file Source video.
     * @param context_module $context
     * @return float|null Duration of the video in seconds.
     */
    protected static function split(stored_file $file, context_module $context): ?float {
        $ffmpeg = ffmpeg::from_config();
        $config = get_config('mod_videoai');
        $samplerate = (int) ($config->samplerate ?? 16000) ?: 16000;
        $channels = (int) ($config->channels ?? 1) ?: 1;
        $extractvideo = !empty($config->extractvideo);

        $filename = $file->get_filename();
        $basename = pathinfo($filename, PATHINFO_FILENAME);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION)) ?: 'mp4';

        $workdir = make_temp_directory('mod_videoai/' . uniqid('', true));
        try {
            $source = $workdir . '/source.' . $extension;
            $file->copy_content_to($source);

            $info = $ffmpeg->probe($source);
            if (!$info['hasaudio']) {
                throw new moodle_exception('errornoaudio', 'mod_videoai');
            }

            $audio = $workdir . '/audio.wav';
            $ffmpeg->extract_audio($source, $audio, $samplerate, $channels);

            $videoonly = null;
            if ($extractvideo && $info['hasvideo']) {
                $videoonly = $workdir . '/videoonly.' . $extension;
                $ffmpeg->extract_video_only($source, $videoonly);
            }

            // Only replace the previous outputs once ffmpeg has fully succeeded.
            self::clear_outputs($context);
            self::store($context, 'audio', $audio, $basename . '-audio.wav');
            if ($videoonly) {
                self::store($context, 'videoonly', $videoonly, $basename . '-video.' . $extension);
            }
            return $info['duration'];
        } finally {
            fulldelete($workdir);
        }
    }

    /**
     * Save a local file into a plugin file area.
     *
     * @param context_module $context
     * @param string $filearea
     * @param string $path Local file.
     * @param string $filename Name to store it under.
     */
    protected static function store(context_module $context, string $filearea, string $path, string $filename): void {
        get_file_storage()->create_file_from_pathname([
            'contextid' => $context->id,
            'component' => self::COMPONENT,
            'filearea' => $filearea,
            'itemid' => 0,
            'filepath' => '/',
            'filename' => clean_filename($filename),
        ], $path);
    }

    /**
     * Delete all processor outputs of an activity.
     *
     * @param context_module $context
     */
    public static function clear_outputs(context_module $context): void {
        $fs = get_file_storage();
        foreach (self::OUTPUT_AREAS as $area) {
            $fs->delete_area_files($context->id, self::COMPONENT, $area);
        }
    }

    /**
     * Delete the transcript of an activity whose audio is gone.
     *
     * @param int $videoaiid
     * @param context_module $context
     */
    protected static function clear_transcript(int $videoaiid, context_module $context): void {
        transcript::clear($videoaiid, $context);
        transcriber::set_status($videoaiid, transcriber::STATUS_NONE, null, ['transcripthash' => null]);
    }

    /**
     * Update the processing status of an activity.
     *
     * @param int $videoaiid
     * @param int $status One of the STATUS_ constants.
     * @param string|null $message Error message, or null to clear it.
     * @param array $extra Further fields to update.
     */
    protected static function set_status(int $videoaiid, int $status, ?string $message = null, array $extra = []): void {
        global $DB;
        $record = (object) array_merge($extra, [
            'id' => $videoaiid,
            'status' => $status,
            'statusmessage' => $message,
        ]);
        $DB->update_record('videoai', $record);
    }
}
