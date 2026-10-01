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
 * List of Video AI activities in a course.
 *
 * @package    mod_videoai
 * @copyright  2026 Awakelab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);

require_course_login($course, true);
$PAGE->set_pagelayout('incourse');

\mod_videoai\event\course_module_instance_list_viewed::create([
    'context' => context_course::instance($course->id),
])->trigger();

$strplural = get_string('modulenameplural', 'mod_videoai');
$PAGE->set_url('/mod/videoai/index.php', ['id' => $course->id]);
$PAGE->set_title($strplural);
$PAGE->set_heading(format_string($course->fullname));
$PAGE->navbar->add($strplural);

echo $OUTPUT->header();
echo $OUTPUT->heading($strplural);

$instances = get_all_instances_in_course('videoai', $course);
if (!$instances) {
    notice(get_string('thereareno', 'moodle', $strplural), new moodle_url('/course/view.php', ['id' => $course->id]));
}

$table = new html_table();
$table->head = [get_string('name'), get_string('moduleintro')];
foreach ($instances as $instance) {
    $link = html_writer::link(new moodle_url('/mod/videoai/view.php', ['id' => $instance->coursemodule]),
        format_string($instance->name), $instance->visible ? [] : ['class' => 'dimmed']);
    $table->data[] = [$link, format_module_intro('videoai', $instance, $instance->coursemodule)];
}
echo html_writer::table($table);
echo $OUTPUT->footer();
