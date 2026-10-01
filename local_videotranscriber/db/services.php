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

/**
 * Web service definitions for local_videotranscriber.
 *
 * @package    local_videotranscriber
 * @copyright  2026 Awakelab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_videotranscriber_get_course_transcripts' => [
        'classname' => \local_videotranscriber\external\get_course_transcripts::class,
        'description' => 'Timestamped transcripts of the videos found in a course, with the activity each video is in.',
        'type' => 'read',
        'capabilities' => 'local/videotranscriber:view',
    ],
    'local_videotranscriber_set_course_enabled' => [
        'classname' => \local_videotranscriber\external\set_course_enabled::class,
        'description' => 'Called by Pulse when it is turned on or off in a course; turning it on starts transcribing the course videos.',
        'type' => 'write',
        'capabilities' => 'local/videotranscriber:manage',
    ],
];
