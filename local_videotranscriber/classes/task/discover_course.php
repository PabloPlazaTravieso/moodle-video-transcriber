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

namespace local_videotranscriber\task;

use local_videotranscriber\local\discovery;

/**
 * Adhoc task that finds and queues the new videos of one course. Custom data: {courseid: int}.
 *
 * Queued when the content of a course in scope changes, so new videos are transcribed without waiting
 * for the nightly run.
 *
 * @package    local_videotranscriber
 * @copyright  2026 Awakelab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class discover_course extends \core\task\adhoc_task {

    /**
     * Name shown in the task logs.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskdiscovercourse', 'local_videotranscriber');
    }

    /**
     * Run the task.
     */
    public function execute(): void {
        $courseid = (int) $this->get_custom_data()->courseid;
        $stats = discovery::discover_course($courseid);
        mtrace("local_videotranscriber: course {$courseid}: {$stats['found']} videos, {$stats['registered']} new, "
            . "{$stats['queued']} queued.");
    }
}
