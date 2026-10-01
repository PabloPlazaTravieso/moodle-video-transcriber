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
 * Finds new videos in the configured courses and queues their transcription.
 *
 * @package    local_videotranscriber
 * @copyright  2026 Awakelab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class discover_videos extends \core\task\scheduled_task {

    /**
     * Name shown in the task list.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskdiscover', 'local_videotranscriber');
    }

    /**
     * Run the task.
     */
    public function execute(): void {
        $stats = discovery::run();
        mtrace("local_videotranscriber: {$stats['found']} videos in scope, {$stats['registered']} new, "
            . "{$stats['queued']} queued, {$stats['removed']} removed.");
    }
}
