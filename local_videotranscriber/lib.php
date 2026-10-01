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
 * Library functions for local_videotranscriber.
 *
 * @package    local_videotranscriber
 * @copyright  2026 Awakelab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Add the "Video transcripts" page to the course navigation ("More" menu) for those who can see it.
 *
 * @param navigation_node $navigation
 * @param stdClass $course
 * @param context $context
 */
function local_videotranscriber_extend_navigation_course(navigation_node $navigation, stdClass $course, context $context) {
    if (!has_capability('local/videotranscriber:view', $context)) {
        return;
    }
    $navigation->add(get_string('coursereport', 'local_videotranscriber'),
        new moodle_url('/local/videotranscriber/index.php', ['id' => $course->id]),
        navigation_node::TYPE_SETTING, null, 'local_videotranscriber', new pix_icon('i/report', ''));
}
