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

namespace mod_videoai\local;

/**
 * The transcription service could not be reached or failed on its side; the request can be retried later.
 *
 * @package    mod_videoai
 * @copyright  2026 Awakelab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class transcriber_unavailable_exception extends \moodle_exception {

    /**
     * Constructor.
     *
     * @param string $url Service URL.
     * @param string $detail What went wrong.
     */
    public function __construct(string $url, string $detail) {
        parent::__construct('errortranscriberunavailable', 'mod_videoai', '', $url, $detail);
    }
}
