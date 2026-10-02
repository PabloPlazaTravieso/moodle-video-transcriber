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
 * Videos found in a course and their transcripts.
 *
 * @package    local_videotranscriber
 * @copyright  2026 Awakelab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_videotranscriber\local\discovery;
use local_videotranscriber\local\pulse_courses;
use local_videotranscriber\local\videos;
use mod_videoai\local\transcript;

$courseid = required_param('id', PARAM_INT);
$videoid = optional_param('videoid', 0, PARAM_INT);

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/videotranscriber:view', $context);

$url = new moodle_url('/local/videotranscriber/index.php', ['id' => $course->id]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('coursereport', 'local_videotranscriber'));
$PAGE->set_heading(format_string($course->fullname));

// Turning Pulse on or off by hand (normally Pulse does it through the web service).
$action = optional_param('pulse', '', PARAM_ALPHA);
$canmanage = has_capability('local/videotranscriber:manage', $context);
if ($action !== '' && $canmanage) {
    require_sesskey();
    if ($action === 'enable') {
        $stats = pulse_courses::enable($course->id, pulse_courses::SOURCE_MANUAL);
        $message = $stats['queued'] ? get_string('pulseenabledmsg', 'local_videotranscriber', $stats['queued'])
            : get_string('pulseenabledmsgnone', 'local_videotranscriber');
        redirect($url, $message, null, \core\output\notification::NOTIFY_SUCCESS);
    }
    pulse_courses::disable($course->id, pulse_courses::SOURCE_MANUAL);
    redirect($url, get_string('pulsedisabledmsg', 'local_videotranscriber'), null, \core\output\notification::NOTIFY_INFO);
}

$locations = discovery::locations_in_course($course->id);
$registered = $locations
    ? $DB->get_records_list('local_videotranscriber_video', 'contenthash', array_keys($locations), 'id')
    : [];

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('coursereport', 'local_videotranscriber'));

if (discovery::scope() === 'pulse') {
    $pulse = pulse_courses::get($course->id);
    $active = $pulse && $pulse->enabled;
    $text = $active
        ? get_string('pulseactive', 'local_videotranscriber', (object) ['date' => userdate($pulse->timemodified),
            'source' => get_string('source' . $pulse->source, 'local_videotranscriber')])
        : get_string('pulseinactive', 'local_videotranscriber');
    if ($canmanage) {
        $button = new single_button(new moodle_url($url, ['pulse' => $active ? 'disable' : 'enable']),
            get_string($active ? 'disablepulse' : 'enablepulse', 'local_videotranscriber'), 'post');
        $button->class .= ' d-inline-block ml-2 ms-2';
        if ($active) {
            $button->add_action(new confirm_action(get_string('disablepulseconfirm', 'local_videotranscriber')));
        }
        $text .= ' ' . $OUTPUT->render($button);
    }
    echo $OUTPUT->notification($text, $active ? 'success' : 'info', false);
}
if (!get_config('local_videotranscriber', 'enabled')) {
    echo $OUTPUT->notification(get_string('pluginoff', 'local_videotranscriber'), 'warning', false);
} else if (discovery::scope() !== 'pulse' && !discovery::course_in_scope($course)) {
    // In Pulse mode the notice above already says whether the course is transcribed.
    echo $OUTPUT->notification(get_string('notinscope', 'local_videotranscriber'), 'info');
}
$unregistered = count($locations) - count($registered);
if ($unregistered > 0) {
    echo $OUTPUT->notification(get_string('unregistered', 'local_videotranscriber', $unregistered), 'info');
}

$badges = [videos::STATUS_DONE => 'success', videos::STATUS_ERROR => 'danger', videos::STATUS_SKIPPED => 'warning'];
if (!$registered) {
    echo html_writer::tag('p', get_string('novideos', 'local_videotranscriber'));
} else {
    $table = new html_table();
    $table->head = [get_string('where', 'local_videotranscriber'), get_string('status'),
        get_string('duration', 'local_videotranscriber'), get_string('timetranscribed', 'local_videotranscriber'), ''];
    $table->colclasses = ['', '', 'text-nowrap', 'text-nowrap', 'text-nowrap'];
    foreach ($registered as $video) {
        $where = [];
        foreach ($locations[$video->contenthash] as $l) {
            $where[] = html_writer::link($l->url, s($l->activityname)) . ' · ' . html_writer::span(s($l->filename), 'text-muted');
        }
        $class = $badges[(int) $video->status] ?? 'info';
        $status = html_writer::span(get_string(videos::STATUS_STRINGS[(int) $video->status], 'local_videotranscriber'),
            "badge badge-$class bg-$class");
        if ($video->message) {
            $status .= html_writer::div(s(strtok($video->message, "\n")), 'small text-muted');
        }
        $action = (int) $video->status === videos::STATUS_DONE
            ? html_writer::link(new moodle_url($url, ['videoid' => $video->id]), get_string('showtranscript', 'local_videotranscriber'))
            : '';
        $table->data[] = [implode('<br>', $where), $status, $video->duration ? transcript::length((float) $video->duration) : '-',
            $video->timetranscribed ? userdate($video->timetranscribed, get_string('strftimedatetimeshort', 'langconfig')) : '-',
            $action];
    }
    echo html_writer::table($table);
}

// The transcript of one video, only if that video is in this course.
if ($videoid && ($video = $registered[$videoid] ?? null)) {
    echo $OUTPUT->heading(get_string('transcriptof', 'local_videotranscriber',
        s($locations[$video->contenthash][0]->filename)), 3);
    // Engine details help whoever configures the site, not teachers.
    $details = array_filter([has_capability('moodle/site:config', context_system::instance()) ? $video->model : '',
        $video->language]);
    if ($details) {
        echo html_writer::div(s(implode(' · ', $details)), 'small text-muted mb-2');
    }
    $lines = '';
    foreach (videos::get_segments($video->id) as $segment) {
        $lines .= html_writer::tag('p', html_writer::span(transcript::clock($segment['start']),
            'text-muted small font-monospace mr-2 me-2') . s($segment['text']), ['class' => 'mb-1']);
    }
    echo html_writer::div($lines ?: get_string('nospeech', 'local_videotranscriber'), 'border rounded p-2',
        ['style' => 'max-height: 480px; overflow-y: auto;']);
}

echo $OUTPUT->footer();
