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

namespace local_videotranscriber\external;

use context_course;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_videotranscriber\local\pulse_courses;

/**
 * Web service for Pulse: called when Pulse is turned on or off in a course. Turning it on starts transcribing
 * the course's videos immediately.
 *
 * @package    local_videotranscriber
 * @copyright  2026 Awakelab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_course_enabled extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'enabled' => new external_value(PARAM_BOOL, 'Whether Pulse is active in the course'),
        ]);
    }

    /**
     * Enable or disable a course.
     *
     * @param int $courseid
     * @param bool $enabled
     * @return array
     */
    public static function execute(int $courseid, bool $enabled): array {
        ['courseid' => $courseid, 'enabled' => $enabled] = self::validate_parameters(self::execute_parameters(),
            ['courseid' => $courseid, 'enabled' => $enabled]);
        $course = get_course($courseid);
        $context = context_course::instance($course->id);
        self::validate_context($context);
        require_capability('local/videotranscriber:manage', $context);

        $stats = ['found' => 0, 'registered' => 0, 'queued' => 0];
        if ($enabled) {
            $stats = pulse_courses::enable($course->id, pulse_courses::SOURCE_WEBSERVICE);
        } else {
            pulse_courses::disable($course->id, pulse_courses::SOURCE_WEBSERVICE);
        }
        return [
            'courseid' => (int) $course->id,
            'enabled' => pulse_courses::is_enabled($course->id),
            'pluginenabled' => (bool) get_config('local_videotranscriber', 'enabled'),
            'videosfound' => $stats['found'],
            'videosqueued' => $stats['queued'],
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'enabled' => new external_value(PARAM_BOOL, 'Whether the course is now marked as Pulse-active'),
            'pluginenabled' => new external_value(PARAM_BOOL,
                'Whether automatic transcription is switched on in the site settings (if not, nothing is transcribed)'),
            'videosfound' => new external_value(PARAM_INT, 'Videos found in the course'),
            'videosqueued' => new external_value(PARAM_INT, 'Videos sent to transcription now (others follow later)'),
        ]);
    }
}
