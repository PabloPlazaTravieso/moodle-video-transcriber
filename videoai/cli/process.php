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
 * Process a Video AI activity immediately, without waiting for cron. Useful to debug ffmpeg.
 *
 * Usage: php mod/videoai/cli/process.php --id=<instance id> [--force]
 *
 * @package    mod_videoai
 * @copyright  2026 Awakelab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params(
    ['id' => 0, 'force' => false, 'help' => false],
    ['h' => 'help', 'f' => 'force']
);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognised)));
}
if ($options['help'] || !$options['id']) {
    cli_writeln("Separate the audio and video of a Video AI activity.

Options:
  --id=<id>     Instance id (the 'id' column of mdl_videoai, not the course module id).
  -f, --force   Process even if the outputs are up to date.
  -h, --help    Print this help.");
    exit(0);
}

$id = (int) $options['id'];
\mod_videoai\local\processor::process($id, (bool) $options['force']);

$record = $DB->get_record('videoai', ['id' => $id], 'status, statusmessage, duration', MUST_EXIST);
cli_writeln("status: {$record->status}  duration: {$record->duration}");
if ($record->statusmessage) {
    cli_writeln($record->statusmessage);
}
exit($record->status == \mod_videoai\local\processor::STATUS_DONE ? 0 : 1);
