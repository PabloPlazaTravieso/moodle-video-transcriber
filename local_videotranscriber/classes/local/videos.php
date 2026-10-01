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

/**
 * Registered videos ({local_videotranscriber_video}) and their transcripts ({local_videotranscriber_seg}).
 *
 * @package    local_videotranscriber
 * @copyright  2026 Awakelab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class videos {

    /** Found, not queued yet (waiting for a run with room under the per-run limit). */
    public const STATUS_NEW = 0;
    /** Transcription task queued. */
    public const STATUS_QUEUED = 1;
    /** Being separated and transcribed. */
    public const STATUS_PROCESSING = 2;
    /** Transcript stored. */
    public const STATUS_DONE = 3;
    /** Failed; see message. */
    public const STATUS_ERROR = 4;
    /** Not transcribed on purpose (no audio, longer than the maximum); see message. */
    public const STATUS_SKIPPED = 5;

    /** Language string per status. */
    public const STATUS_STRINGS = [
        self::STATUS_NEW => 'statusnew',
        self::STATUS_QUEUED => 'statusqueued',
        self::STATUS_PROCESSING => 'statusprocessing',
        self::STATUS_DONE => 'statusdone',
        self::STATUS_ERROR => 'statuserror',
        self::STATUS_SKIPPED => 'statusskipped',
    ];

    /**
     * Queue the transcription of a registered video.
     *
     * @param int $videoid
     */
    public static function queue(int $videoid): void {
        $task = new \local_videotranscriber\task\transcribe_video();
        $task->set_custom_data(['videoid' => $videoid]);
        \core\task\manager::queue_adhoc_task($task);
        self::set_status($videoid, self::STATUS_QUEUED, null, ['timequeued' => time()]);
    }

    /**
     * Update the status of a video.
     *
     * @param int $videoid
     * @param int $status One of the STATUS_ constants.
     * @param string|null $message
     * @param array $extra Further fields to update.
     */
    public static function set_status(int $videoid, int $status, ?string $message = null, array $extra = []): void {
        global $DB;
        $DB->update_record('local_videotranscriber_video', (object) array_merge($extra, [
            'id' => $videoid, 'status' => $status, 'message' => $message, 'timemodified' => time(),
        ]));
    }

    /**
     * Replace the transcript of a video.
     *
     * @param int $videoid
     * @param array $segments [{start, end, text}]
     */
    public static function save_segments(int $videoid, array $segments): void {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records('local_videotranscriber_seg', ['videoid' => $videoid]);
        $records = [];
        foreach (array_values($segments) as $i => $s) {
            $records[] = ['videoid' => $videoid, 'segmentno' => $i, 'starttime' => round((float) $s['start'], 3),
                'endtime' => round((float) $s['end'], 3), 'text' => (string) $s['text']];
        }
        if ($records) {
            $DB->insert_records('local_videotranscriber_seg', $records);
        }
        $transaction->allow_commit();
    }

    /**
     * Segments of a video, in order.
     *
     * @param int $videoid
     * @return array<int, array{start: float, end: float, text: string}>
     */
    public static function get_segments(int $videoid): array {
        global $DB;
        $records = $DB->get_records('local_videotranscriber_seg', ['videoid' => $videoid], 'segmentno',
            'segmentno, starttime, endtime, text');
        return array_values(array_map(fn($r) => ['start' => (float) $r->starttime, 'end' => (float) $r->endtime,
            'text' => $r->text], $records));
    }

    /**
     * Transcript as "[HH:MM:SS] text" lines, for an LLM prompt.
     *
     * @param int $videoid
     * @return string
     */
    public static function as_timestamped_text(int $videoid): string {
        return implode("\n", array_map(fn($s) => '[' . \mod_videoai\local\transcript::clock($s['start']) . '] ' . $s['text'],
            self::get_segments($videoid)));
    }

    /**
     * Forget a video and its transcript.
     *
     * @param int $videoid
     */
    public static function delete(int $videoid): void {
        global $DB;
        $DB->delete_records('local_videotranscriber_seg', ['videoid' => $videoid]);
        $DB->delete_records('local_videotranscriber_video', ['id' => $videoid]);
    }
}
