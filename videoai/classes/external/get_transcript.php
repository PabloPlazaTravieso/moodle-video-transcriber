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

namespace mod_videoai\external;

use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_videoai\local\transcriber;
use mod_videoai\local\transcript;

/**
 * Web service returning the transcript of a Video AI activity, for the question-answering AI.
 *
 * @package    mod_videoai
 * @copyright  2026 Awakelab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_transcript extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id of the Video AI activity'),
        ]);
    }

    /**
     * Return the transcript.
     *
     * @param int $cmid
     * @return array
     */
    public static function execute(int $cmid): array {
        global $DB;

        ['cmid' => $cmid] = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);
        $cm = get_coursemodule_from_id('videoai', $cmid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/videoai:viewtranscript', $context);

        $videoai = $DB->get_record('videoai', ['id' => $cm->instance], '*', MUST_EXIST);
        $done = (int) $videoai->transcriptstatus === transcriber::STATUS_DONE;
        $segments = $done ? transcript::get_segments($videoai->id) : [];

        return [
            'cmid' => $cm->id,
            'name' => format_string($videoai->name, true, ['context' => $context]),
            'status' => (int) $videoai->transcriptstatus,
            'ready' => $done,
            'language' => (string) $videoai->transcriptlanguage,
            'duration' => (float) $videoai->duration,
            'model' => (string) $videoai->transcriptmodel,
            'timetranscribed' => (int) $videoai->timetranscribed,
            'text' => $done ? transcript::as_timestamped_text($videoai->id) : '',
            'segments' => $segments,
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'name' => new external_value(PARAM_TEXT, 'Activity name'),
            'status' => new external_value(PARAM_INT, 'Transcription status: 0 none, 1 queued, 2 processing, 3 done, 4 error'),
            'ready' => new external_value(PARAM_BOOL, 'Whether a transcript is available'),
            'language' => new external_value(PARAM_ALPHANUMEXT, 'Detected or configured language code'),
            'duration' => new external_value(PARAM_FLOAT, 'Video duration in seconds'),
            'model' => new external_value(PARAM_TEXT, 'Engine and model used'),
            'timetranscribed' => new external_value(PARAM_INT, 'When the transcript was produced'),
            'text' => new external_value(PARAM_RAW, 'Whole transcript, one line per segment prefixed with [HH:MM:SS]'),
            'segments' => new external_multiple_structure(new external_single_structure([
                'start' => new external_value(PARAM_FLOAT, 'Start, in seconds from the beginning of the video'),
                'end' => new external_value(PARAM_FLOAT, 'End, in seconds'),
                'text' => new external_value(PARAM_RAW, 'Spoken text'),
            ])),
        ]);
    }
}
