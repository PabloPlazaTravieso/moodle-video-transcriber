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
 * Version information for local_videotranscriber.
 *
 * @package    local_videotranscriber
 * @copyright  2026 Awakelab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_videotranscriber';
$plugin->version   = 2026100101;
$plugin->requires  = 2024100100; // Moodle 4.5.
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.2.0 (transcripción automática al activar Pulse en un curso)';
// The audio separation and transcription engine (FFmpeg / Whisper settings) lives in mod_videoai.
$plugin->dependencies = ['mod_videoai' => 2026093002];
