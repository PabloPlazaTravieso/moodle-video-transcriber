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
 * Courses where Pulse, the question-answering chatbot, is active: the trigger for automatic transcription.
 *
 * A course becomes active when Pulse calls local_videotranscriber_set_course_enabled (source "webservice"), when
 * Pulse asks for the transcripts of a course it is already active in (source "request", see the "autoenable"
 * setting) or from the course report page (source "manual"). Enabling starts transcribing its videos right away.
 *
 * @package    local_videotranscriber
 * @copyright  2026 Awakelab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pulse_courses {

    /** Pulse activated the course through the web service. */
    public const SOURCE_WEBSERVICE = 'webservice';
    /** Pulse asked for the course's transcripts while it was not active yet. */
    public const SOURCE_REQUEST = 'request';
    /** Someone turned it on from the course report page. */
    public const SOURCE_MANUAL = 'manual';

    /**
     * Whether Pulse is active in a course.
     *
     * @param int $courseid
     * @return bool
     */
    public static function is_enabled(int $courseid): bool {
        global $DB;
        return $DB->record_exists('local_videotranscriber_crs', ['courseid' => $courseid, 'enabled' => 1]);
    }

    /**
     * The activation record of a course, if any.
     *
     * @param int $courseid
     * @return \stdClass|null
     */
    public static function get(int $courseid): ?\stdClass {
        global $DB;
        return $DB->get_record('local_videotranscriber_crs', ['courseid' => $courseid]) ?: null;
    }

    /**
     * Mark Pulse as active in a course and start transcribing its videos.
     *
     * @param int $courseid
     * @param string $source One of the SOURCE_ constants.
     * @return array{found: int, registered: int, queued: int} What discover_course() did.
     */
    public static function enable(int $courseid, string $source): array {
        self::store($courseid, true, $source);
        return discovery::discover_course($courseid);
    }

    /**
     * Mark Pulse as no longer active: no new videos of the course are transcribed. Existing transcripts are kept,
     * so a course can be turned back on without transcribing everything again.
     *
     * @param int $courseid
     * @param string $source
     */
    public static function disable(int $courseid, string $source): void {
        self::store($courseid, false, $source);
    }

    /**
     * Insert or update the activation record.
     *
     * @param int $courseid
     * @param bool $enabled
     * @param string $source
     */
    protected static function store(int $courseid, bool $enabled, string $source): void {
        global $DB, $USER;
        $now = time();
        $record = $DB->get_record('local_videotranscriber_crs', ['courseid' => $courseid]);
        $fields = ['enabled' => (int) $enabled, 'source' => $source, 'usermodified' => (int) ($USER->id ?? 0),
            'timemodified' => $now];
        if ($record) {
            $DB->update_record('local_videotranscriber_crs', (object) (['id' => $record->id] + $fields));
        } else {
            $DB->insert_record('local_videotranscriber_crs', (object) (['courseid' => $courseid, 'timecreated' => $now]
                + $fields));
        }
    }
}
