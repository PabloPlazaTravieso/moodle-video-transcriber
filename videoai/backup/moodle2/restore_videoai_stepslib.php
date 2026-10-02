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
 * Restore structure of a Video Transcriber activity.
 *
 * @package    mod_videoai
 * @copyright  2026 Awakelab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restores the activity record, its transcript segments and its files, then requeues unfinished work.
 */
class restore_videoai_activity_structure_step extends restore_activity_structure_step {

    protected function define_structure() {
        $paths = [
            new restore_path_element('videoai', '/activity/videoai'),
            new restore_path_element('videoai_segment', '/activity/videoai/segments/segment'),
        ];
        return $this->prepare_activity_structure($paths);
    }

    /**
     * @param array $data
     */
    protected function process_videoai($data) {
        global $DB;
        $data = (object) $data;
        $data->course = $this->get_courseid();
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);
        $newid = $DB->insert_record('videoai', $data);
        $this->apply_activity_instance($newid);
    }

    /**
     * @param array $data
     */
    protected function process_videoai_segment($data) {
        global $DB;
        $data = (object) $data;
        $data->videoaiid = $this->get_new_parentid('videoai');
        $DB->insert_record('videoai_segments', $data);
    }

    protected function after_execute() {
        $this->add_related_files('mod_videoai', 'intro', null);
        foreach (['video', 'audio', 'videoonly', 'transcript'] as $area) {
            $this->add_related_files('mod_videoai', $area, null);
        }
        // The backup may have been taken mid-processing, or without files: bring the status in line with
        // what was restored, and queue whatever is still to be done.
        \mod_videoai\local\processor::restored($this->get_new_parentid('videoai'),
            context_module::instance($this->task->get_moduleid()));
    }
}
