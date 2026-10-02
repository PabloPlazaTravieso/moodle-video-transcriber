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
 * Library of functions for mod_videoai.
 *
 * @package    mod_videoai
 * @copyright  2026 Awakelab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_videoai\local\processor;

/**
 * Features supported by this module.
 *
 * @param string $feature FEATURE_xx constant.
 * @return mixed True/false if supported, null if unknown.
 */
function videoai_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
        case FEATURE_SHOW_DESCRIPTION:
        case FEATURE_COMPLETION_TRACKS_VIEWS:
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
        case FEATURE_GROUPS:
        case FEATURE_GROUPINGS:
            return false;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_CONTENT;
        default:
            return null;
    }
}

/**
 * Create a new instance.
 *
 * @param stdClass $data Form data.
 * @param mod_videoai_mod_form|null $mform
 * @return int New instance id.
 */
function videoai_add_instance($data, $mform = null) {
    global $DB;

    $data->timecreated = time();
    $data->timemodified = $data->timecreated;
    $data->status = processor::STATUS_NOVIDEO;
    $data->id = $DB->insert_record('videoai', $data);

    videoai_save_video($data);
    return $data->id;
}

/**
 * Update an existing instance.
 *
 * @param stdClass $data Form data.
 * @param mod_videoai_mod_form|null $mform
 * @return bool
 */
function videoai_update_instance($data, $mform = null) {
    global $DB;

    $data->id = $data->instance;
    $data->timemodified = time();
    // Processing fields are owned by the processor, never by the form.
    unset($data->status, $data->statusmessage, $data->videohash, $data->duration, $data->timeprocessed);
    $DB->update_record('videoai', $data);

    videoai_save_video($data);
    return true;
}

/**
 * Store the uploaded video and queue processing when it changed.
 *
 * @param stdClass $data Form data with id, coursemodule and videofile (draft item id).
 */
function videoai_save_video(stdClass $data): void {
    global $DB;

    $context = context_module::instance($data->coursemodule);
    if (isset($data->videofile)) {
        file_save_draft_area_files($data->videofile, $context->id, processor::COMPONENT, 'video', 0,
            processor::video_filemanager_options());
    }
    processor::video_saved($DB->get_record('videoai', ['id' => $data->id], '*', MUST_EXIST), $context);
}

/**
 * Delete an instance. Files are removed by core together with the module context.
 *
 * @param int $id Instance id.
 * @return bool
 */
function videoai_delete_instance($id) {
    global $DB;

    if (!$DB->record_exists('videoai', ['id' => $id])) {
        return false;
    }
    $DB->delete_records('videoai_segments', ['videoaiid' => $id]);
    $DB->delete_records('videoai', ['id' => $id]);
    return true;
}

/**
 * File areas for the file browser.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @return array
 */
function videoai_get_file_areas($course, $cm, $context) {
    return [
        'video' => get_string('videofile', 'mod_videoai'),
        'audio' => get_string('audiofile', 'mod_videoai'),
        'videoonly' => get_string('videoonlyfile', 'mod_videoai'),
        'transcript' => get_string('transcript', 'mod_videoai'),
    ];
}

/**
 * Serve the plugin files.
 *
 * URLs have the form /pluginfile.php/{contextid}/mod_videoai/{filearea}/{revision}/{filename}.
 * The revision only busts browser caches after reprocessing; it is not used for lookup.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool False if the file is not found; otherwise the file is sent and the script exits.
 */
function videoai_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }
    if (!in_array($filearea, ['video', 'audio', 'videoonly', 'transcript'], true)) {
        return false;
    }

    require_course_login($course, true, $cm);
    require_capability('mod/videoai:view', $context);
    // A 404 rather than an exception when not allowed: a capability exception is reported as HTTP 500.
    $required = ['audio' => 'mod/videoai:process', 'videoonly' => 'mod/videoai:process',
        'transcript' => 'mod/videoai:viewtranscript'];
    if (isset($required[$filearea]) && !has_capability($required[$filearea], $context)) {
        return false;
    }

    array_shift($args); // Revision.
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $file = get_file_storage()->get_file($context->id, processor::COMPONENT, $filearea, 0, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }
    send_stored_file($file, DAYSECS, 0, $forcedownload, $options);
}

/**
 * Mark the activity viewed and trigger the viewed event.
 *
 * @param stdClass $videoai
 * @param stdClass $course
 * @param cm_info|stdClass $cm
 * @param context_module $context
 */
function videoai_view($videoai, $course, $cm, $context) {
    $event = \mod_videoai\event\course_module_viewed::create([
        'objectid' => $videoai->id,
        'context' => $context,
    ]);
    $event->add_record_snapshot('course', $course);
    $event->add_record_snapshot('videoai', $videoai);
    $event->trigger();

    $completion = new completion_info($course);
    $completion->set_module_viewed($cm);
}
