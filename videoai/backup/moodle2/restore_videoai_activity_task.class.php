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
 * Restore task of a Video Transcriber activity.
 *
 * @package    mod_videoai
 * @copyright  2026 Awakelab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/videoai/backup/moodle2/restore_videoai_stepslib.php');

/**
 * Restores one Video Transcriber activity.
 */
class restore_videoai_activity_task extends restore_activity_task {

    protected function define_my_settings() {
    }

    protected function define_my_steps() {
        $this->add_step(new restore_videoai_activity_structure_step('videoai_structure', 'videoai.xml'));
    }

    public static function define_decode_contents() {
        return [new restore_decode_content('videoai', ['intro'], 'videoai')];
    }

    public static function define_decode_rules() {
        return [
            new restore_decode_rule('VIDEOAIVIEWBYID', '/mod/videoai/view.php?id=$1', 'course_module'),
            new restore_decode_rule('VIDEOAIINDEX', '/mod/videoai/index.php?id=$1', 'course'),
        ];
    }

    public static function define_restore_log_rules() {
        return [];
    }

    public static function define_restore_log_rules_for_course() {
        return [];
    }
}
