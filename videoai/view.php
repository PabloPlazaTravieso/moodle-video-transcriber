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
 * Display a Video AI activity.
 *
 * @package    mod_videoai
 * @copyright  2026 Awakelab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use mod_videoai\local\processor;
use mod_videoai\local\transcriber;
use mod_videoai\local\transcript;

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'videoai');
$videoai = $DB->get_record('videoai', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/videoai:view', $context);

$url = new moodle_url('/mod/videoai/view.php', ['id' => $cm->id]);

if ($action === 'reprocess') {
    require_sesskey();
    require_capability('mod/videoai:process', $context);
    processor::queue($videoai->id, true);
    redirect($url, get_string('reprocessqueued', 'mod_videoai'), null, \core\output\notification::NOTIFY_SUCCESS);
}
if ($action === 'retranscribe') {
    require_sesskey();
    require_capability('mod/videoai:process', $context);
    transcriber::queue($videoai->id, true);
    redirect($url, get_string('retranscribequeued', 'mod_videoai'), null, \core\output\notification::NOTIFY_SUCCESS);
}

videoai_view($videoai, $course, $cm, $context);

$PAGE->set_url($url);
$PAGE->set_title(format_string($videoai->name));
$PAGE->set_heading(format_string($course->fullname));

$canprocess = has_capability('mod/videoai:process', $context);
$cantranscript = has_capability('mod/videoai:viewtranscript', $context);
$status = (int) $videoai->status;
$tstatus = (int) $videoai->transcriptstatus;
$working = in_array($status, [processor::STATUS_QUEUED, processor::STATUS_PROCESSING], true);
$tworking = in_array($tstatus, [transcriber::STATUS_QUEUED, transcriber::STATUS_PROCESSING], true);
if (($canprocess && $working) || (($canprocess || $cantranscript) && $tworking)) {
    $PAGE->set_periodic_refresh_delay(15);
}

/**
 * URL of the single file in a plugin file area.
 *
 * @param stored_file|null $file
 * @param int $revision Cache-busting revision.
 * @return moodle_url|null
 */
$fileurl = function(?stored_file $file, int $revision = 0): ?moodle_url {
    if (!$file) {
        return null;
    }
    return moodle_url::make_pluginfile_url($file->get_contextid(), $file->get_component(), $file->get_filearea(),
        $revision, $file->get_filepath(), $file->get_filename());
};

/**
 * A button that asks for confirmation before queueing work again.
 *
 * @param string $action
 * @param string $label Language string of the button.
 * @param string $confirm Language string of the question.
 * @return string HTML.
 */
$actionbutton = function(string $action, string $label, string $confirm) use ($OUTPUT, $url): string {
    $button = new single_button(new moodle_url($url, ['action' => $action]), get_string($label, 'mod_videoai'), 'post');
    $button->add_action(new confirm_action(get_string($confirm, 'mod_videoai')));
    return $OUTPUT->render($button);
};

$templatedata = ['videohtml' => null, 'canprocess' => $canprocess];

$video = processor::get_video_file($context);
if ($video) {
    $mediamanager = core_media_manager::instance($PAGE);
    $templatedata['videohtml'] = $mediamanager->embed_url($fileurl($video, (int) $videoai->timemodified), $video->get_filename(), 0, 0,
        [core_media_manager::OPTION_BLOCK => true]);
}

if ($canprocess) {
    $statuskeys = [
        processor::STATUS_NOVIDEO => ['statusnovideo', 'secondary'],
        processor::STATUS_QUEUED => ['statusqueued', 'info'],
        processor::STATUS_PROCESSING => ['statusprocessing', 'info'],
        processor::STATUS_DONE => ['statusdone', 'success'],
        processor::STATUS_ERROR => ['statuserror', 'danger'],
    ];
    [$statuskey, $statusclass] = $statuskeys[$status] ?? $statuskeys[processor::STATUS_NOVIDEO];

    $audio = processor::get_area_file($context, 'audio');
    $videoonly = processor::get_area_file($context, 'videoonly');
    $revision = (int) $videoai->timeprocessed;

    $templatedata += [
        'statuslabel' => get_string($statuskey, 'mod_videoai'),
        'statusclass' => $statusclass,
        'iserror' => $status === processor::STATUS_ERROR,
        'statusmessage' => $videoai->statusmessage,
        'duration' => $videoai->duration !== null ? transcript::length((float) $videoai->duration) : null,
        'timeprocessed' => $videoai->timeprocessed ? userdate($videoai->timeprocessed) : null,
        'audio' => $audio ? [
            'url' => $fileurl($audio, $revision)->out(false),
            'filename' => $audio->get_filename(),
            'size' => display_size($audio->get_filesize()),
        ] : null,
        'videoonly' => $videoonly ? [
            'url' => $fileurl($videoonly, $revision)->out(false),
            'filename' => $videoonly->get_filename(),
            'size' => display_size($videoonly->get_filesize()),
        ] : null,
        'reprocessbutton' => $video && !$working ? $actionbutton('reprocess', 'reprocess', 'reprocessconfirm') : null,
    ];
}

if ($cantranscript || $canprocess) {
    $tstatuskeys = [
        transcriber::STATUS_NONE => ['transcriptnone', 'secondary'],
        transcriber::STATUS_QUEUED => ['statusqueued', 'info'],
        transcriber::STATUS_PROCESSING => ['statusprocessing', 'info'],
        transcriber::STATUS_DONE => ['statusdone', 'success'],
        transcriber::STATUS_ERROR => ['statuserror', 'danger'],
    ];
    [$tkey, $tclass] = $tstatuskeys[$tstatus] ?? $tstatuskeys[transcriber::STATUS_NONE];
    $tdone = $tstatus === transcriber::STATUS_DONE;
    $segments = $cantranscript && $tdone ? transcript::get_segments($videoai->id) : [];
    $downloads = [];
    // While a new transcription is queued or running, the previous files and date no longer describe what is shown.
    if ($cantranscript && $tdone) {
        foreach (get_file_storage()->get_area_files($context->id, processor::COMPONENT, transcript::FILEAREA, 0,
                'filename', false) as $file) {
            $downloads[] = [
                'url' => $fileurl($file, (int) $videoai->timetranscribed)->out(false) . '?forcedownload=1',
                'filename' => $file->get_filename(),
            ];
        }
    }
    $templatedata['transcript'] = [
        'enabled' => transcriber::is_enabled(),
        'statuslabel' => get_string($tkey, 'mod_videoai'),
        'statusclass' => $tclass,
        'iserror' => $tstatus === transcriber::STATUS_ERROR,
        'message' => $videoai->transcriptmessage,
        'language' => $videoai->transcriptlanguage,
        // Engine details help whoever configures the site, not teachers.
        'model' => $tdone && has_capability('moodle/site:config', context_system::instance()) ? $videoai->transcriptmodel : null,
        'timetranscribed' => $tdone && $videoai->timetranscribed ? userdate($videoai->timetranscribed) : null,
        'cantranscript' => $cantranscript,
        'segments' => array_map(fn($seg) => ['time' => transcript::clock($seg['start']), 'text' => $seg['text']],
            $segments),
        'empty' => $cantranscript && $tdone && !$segments,
        'downloads' => $downloads,
        // Not while the audio is being separated again: the transcription would use the old audio.
        'retranscribebutton' => $canprocess && transcriber::is_enabled() && !$tworking && !$working
            && processor::get_area_file($context, 'audio')
            ? $actionbutton('retranscribe', 'retranscribe', 'retranscribeconfirm') : null,
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_videoai/view', $templatedata);
echo $OUTPUT->footer();
