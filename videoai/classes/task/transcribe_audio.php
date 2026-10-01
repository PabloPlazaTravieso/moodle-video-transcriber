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

namespace mod_videoai\task;

use mod_videoai\local\transcriber;

/**
 * Adhoc task that transcribes the separated audio of an activity.
 *
 * Custom data: {videoaiid: int, force: bool}. When the transcription service is unavailable the task
 * fails and core retries it later with an increasing delay.
 *
 * @package    mod_videoai
 * @copyright  2026 Awakelab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class transcribe_audio extends \core\task\adhoc_task {

    /**
     * Name shown in the task logs.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('tasktranscribeaudio', 'mod_videoai');
    }

    /**
     * Run the task.
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        transcriber::process((int) $data->videoaiid, !empty($data->force));
    }
}
