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

namespace local_videotranscriber;

use local_videotranscriber\local\discovery;

/**
 * Event observers: when teachers add or change course content, look for new videos in that course.
 *
 * @package    local_videotranscriber
 * @copyright  2026 Awakelab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {

    /**
     * An activity was added or updated, or a section or the course itself was edited.
     *
     * Only queues a cheap adhoc discovery (one per course at a time); the files are inspected outside the request.
     *
     * @param \core\event\base $event
     */
    public static function course_content_changed(\core\event\base $event): void {
        if (empty($event->courseid) || (int) $event->courseid === SITEID || !get_config('local_videotranscriber', 'enabled')) {
            return;
        }
        discovery::queue_course_discovery((int) $event->courseid);
    }
}
