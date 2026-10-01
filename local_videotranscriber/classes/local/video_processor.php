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

namespace local_videotranscriber\local;

use mod_videoai\local\ffmpeg;
use mod_videoai\local\transcriber;
use mod_videoai\local\transcriber_unavailable_exception;
use moodle_exception;

/**
 * Separates and transcribes one registered video with the engine of mod_videoai.
 *
 * The audio is only kept in a temporary file: unlike the activity, nothing but the transcript is stored.
 *
 * @package    local_videotranscriber
 * @copyright  2026 Awakelab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class video_processor {

    /**
     * Transcribe a video. Errors are recorded on the video; only an unavailable external service is rethrown,
     * so the task is retried later.
     *
     * @param int $videoid
     * @throws transcriber_unavailable_exception
     * @throws moodle_exception When another run holds the lock.
     */
    public static function process(int $videoid): void {
        global $DB;

        $lock = \core\lock\lock_config::get_lock_factory('local_videotranscriber')->get_lock('video_' . $videoid, 10);
        if (!$lock) {
            throw new moodle_exception('errorlocked', 'local_videotranscriber');
        }
        try {
            $video = $DB->get_record('local_videotranscriber_video', ['id' => $videoid]);
            if (!$video || (int) $video->status === videos::STATUS_DONE) {
                return; // Deleted meanwhile, or already done by a duplicate task.
            }
            $file = discovery::get_file($video->contenthash);
            if (!$file) {
                videos::delete($videoid);
                return;
            }

            videos::set_status($videoid, videos::STATUS_PROCESSING);
            $workdir = make_temp_directory('local_videotranscriber/' . uniqid('', true));
            try {
                self::transcribe($video, $file, $workdir);
            } catch (\Throwable $e) {
                $message = $e->getMessage() . ($e instanceof moodle_exception && $e->debuginfo ? "\n" . $e->debuginfo : '');
                mtrace("local_videotranscriber: video {$videoid} ({$file->get_filename()}) failed: {$message}");
                videos::set_status($videoid, videos::STATUS_ERROR, $message);
                if ($e instanceof transcriber_unavailable_exception) {
                    throw $e;
                }
            } finally {
                fulldelete($workdir);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Probe, extract the audio, transcribe and store.
     *
     * @param \stdClass $video
     * @param \stored_file $file
     * @param string $workdir
     */
    protected static function transcribe(\stdClass $video, \stored_file $file, string $workdir): void {
        $config = get_config('mod_videoai');
        $ffmpeg = ffmpeg::from_config();

        // Read the video where Moodle keeps it instead of copying gigabytes into the temp folder.
        $source = get_file_storage()->get_file_system()->get_local_path_from_storedfile($file, true);
        $info = $ffmpeg->probe($source);
        if (!$info['hasaudio']) {
            videos::set_status($video->id, videos::STATUS_SKIPPED, get_string('skippednoaudio', 'local_videotranscriber'),
                ['duration' => $info['duration']]);
            return;
        }
        $maxminutes = (int) get_config('local_videotranscriber', 'maxduration');
        if ($maxminutes > 0 && $info['duration'] !== null && $info['duration'] > $maxminutes * 60) {
            videos::set_status($video->id, videos::STATUS_SKIPPED,
                get_string('skippedtoolong', 'local_videotranscriber', $maxminutes), ['duration' => $info['duration']]);
            return;
        }

        $wav = $workdir . '/audio.wav';
        $ffmpeg->extract_audio($source, $wav, (int) ($config->samplerate ?? 16000) ?: 16000,
            (int) ($config->channels ?? 1) ?: 1);
        $result = transcriber::transcribe_audio_file($wav,
            pathinfo($file->get_filename(), PATHINFO_FILENAME) . '-audio.wav');

        videos::save_segments($video->id, $result['segments']);
        videos::set_status($video->id, videos::STATUS_DONE, null, [
            'duration' => $info['duration'],
            'language' => substr((string) $result['language'], 0, 10),
            'model' => \core_text::substr((string) $result['model'], 0, 255),
            'timetranscribed' => time(),
        ]);
    }
}
