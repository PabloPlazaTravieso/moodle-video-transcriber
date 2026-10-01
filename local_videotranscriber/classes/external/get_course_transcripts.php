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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_videotranscriber\local\discovery;
use local_videotranscriber\local\pulse_courses;
use local_videotranscriber\local\videos;

/**
 * Web service returning the transcripts of the videos in a course, for the question-answering AI.
 *
 * @package    local_videotranscriber
 * @copyright  2026 Awakelab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_course_transcripts extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'includesegments' => new external_value(PARAM_BOOL, 'Include the segments; false for a status overview',
                VALUE_DEFAULT, true),
        ]);
    }

    /**
     * Return the course's videos and their transcripts.
     *
     * @param int $courseid
     * @param bool $includesegments
     * @return array
     */
    public static function execute(int $courseid, bool $includesegments = true): array {
        global $DB;

        ['courseid' => $courseid, 'includesegments' => $includesegments] = self::validate_parameters(
            self::execute_parameters(), ['courseid' => $courseid, 'includesegments' => $includesegments]);
        $course = get_course($courseid);
        $context = context_course::instance($course->id);
        self::validate_context($context);
        require_capability('local/videotranscriber:view', $context);

        // Pulse asking for a course's transcripts means Pulse is active there: start transcribing it (the
        // "automatically if Pulse is already active" case), unless the site turned this off.
        $activated = false;
        if (discovery::scope() === 'pulse' && get_config('local_videotranscriber', 'enabled')
                && get_config('local_videotranscriber', 'autoenable') && !pulse_courses::is_enabled($course->id)
                && has_capability('local/videotranscriber:manage', $context)) {
            pulse_courses::enable($course->id, pulse_courses::SOURCE_REQUEST);
            $activated = true;
        }

        $locations = discovery::locations_in_course($course->id);
        $registered = $locations
            ? $DB->get_records_list('local_videotranscriber_video', 'contenthash', array_keys($locations), 'id')
            : [];
        $videos = [];
        foreach ($registered as $video) {
            $done = (int) $video->status === videos::STATUS_DONE;
            $videos[] = [
                'videoid' => (int) $video->id,
                'status' => (int) $video->status,
                'ready' => $done,
                'message' => (string) $video->message,
                'duration' => (float) $video->duration,
                'language' => (string) $video->language,
                'model' => (string) $video->model,
                'timetranscribed' => (int) $video->timetranscribed,
                'locations' => array_map(fn($l) => [
                    'cmid' => $l->cmid, 'modname' => $l->modname, 'activityname' => $l->activityname,
                    'filename' => $l->filename, 'visible' => $l->visible, 'url' => $l->url,
                ], $locations[$video->contenthash]),
                'text' => $done && $includesegments ? videos::as_timestamped_text($video->id) : '',
                'segments' => $done && $includesegments ? videos::get_segments($video->id) : [],
            ];
        }
        return [
            'courseid' => (int) $course->id,
            'inscope' => discovery::course_in_scope($course),
            'activated' => $activated,
            'unregistered' => count($locations) - count($registered),
            'videos' => $videos,
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
            'inscope' => new external_value(PARAM_BOOL, 'Whether the course is configured for automatic transcription'),
            'activated' => new external_value(PARAM_BOOL,
                'True when this request marked the course as Pulse-active and started transcribing it'),
            'unregistered' => new external_value(PARAM_INT, 'Videos in the course not found by the scheduled task yet'),
            'videos' => new external_multiple_structure(new external_single_structure([
                'videoid' => new external_value(PARAM_INT, 'Video id (one per distinct video content)'),
                'status' => new external_value(PARAM_INT, '0 new, 1 queued, 2 processing, 3 done, 4 error, 5 skipped'),
                'ready' => new external_value(PARAM_BOOL, 'Whether the transcript is available'),
                'message' => new external_value(PARAM_TEXT, 'Error or reason for skipping'),
                'duration' => new external_value(PARAM_FLOAT, 'Duration in seconds'),
                'language' => new external_value(PARAM_ALPHANUMEXT, 'Language code'),
                'model' => new external_value(PARAM_TEXT, 'Engine and model used'),
                'timetranscribed' => new external_value(PARAM_INT, 'When it was transcribed'),
                'locations' => new external_multiple_structure(new external_single_structure([
                    'cmid' => new external_value(PARAM_INT, 'Course module id, 0 for course sections and summary'),
                    'modname' => new external_value(PARAM_PLUGIN, 'Module type (resource, page, folder...)'),
                    'activityname' => new external_value(PARAM_TEXT, 'Activity name'),
                    'filename' => new external_value(PARAM_FILE, 'File name in that activity'),
                    'visible' => new external_value(PARAM_BOOL, 'Whether the activity is visible'),
                    'url' => new external_value(PARAM_URL, 'Link to the activity (or course)'),
                ]), 'Where the video appears in this course'),
                'text' => new external_value(PARAM_RAW, 'Transcript, one line per segment prefixed with [HH:MM:SS]'),
                'segments' => new external_multiple_structure(new external_single_structure([
                    'start' => new external_value(PARAM_FLOAT, 'Start, in seconds'),
                    'end' => new external_value(PARAM_FLOAT, 'End, in seconds'),
                    'text' => new external_value(PARAM_RAW, 'Spoken text'),
                ])),
            ])),
        ]);
    }
}
