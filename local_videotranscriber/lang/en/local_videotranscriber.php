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
 * English strings for local_videotranscriber.
 *
 * @package    local_videotranscriber
 * @copyright  2026 Awakelab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['autoenable'] = 'Activate a course when Pulse asks for its transcripts';
$string['autoenable_desc'] = 'For courses where Pulse is already active: the first time Pulse requests a course\'s transcripts, the course is marked as Pulse-active and its videos start being transcribed. Needs the local/videotranscriber:manage capability for the account Pulse uses.';
$string['categories'] = 'Categories';
$string['categories_desc'] = 'Courses in these categories, and in their subcategories, are processed when the scope is "Selected categories".';
$string['coursematerial'] = 'Course page (sections and summary)';
$string['coursereport'] = 'Video transcripts';
$string['disablepulse'] = 'Deactivate';
$string['duration'] = 'Duration';
$string['enabled'] = 'Transcribe course videos automatically';
$string['enabled_desc'] = 'Transcribe the videos teachers have added to the courses in scope. With the "Courses where Pulse is active" scope, transcription starts as soon as Pulse is activated in a course, and new videos are picked up when activities change; a nightly run catches anything missed.';
$string['enablepulse'] = 'Activate now';
$string['errorlocked'] = 'This video is already being processed. The task will be retried.';
$string['maxduration'] = 'Maximum video length (minutes)';
$string['maxduration_desc'] = 'Longer videos are skipped. 0 for no limit.';
$string['maxpertask'] = 'Videos queued per run';
$string['maxpertask_desc'] = 'How many new videos each nightly run sends to transcription; the rest wait for the next nights. Transcribing takes roughly as long as the video on 8 CPU cores with the FFmpeg engine.';
$string['nospeech'] = 'No speech was found in this video.';
$string['notinscope'] = 'Automatic transcription is not active for this course. Videos already transcribed are still shown.';
$string['novideos'] = 'No transcribed videos in this course yet.';
$string['pluginname'] = 'Video Transcriber: course videos';
$string['pluginoff'] = 'Automatic transcription is switched off in the site administration, so no videos are being transcribed.';
$string['privacy:metadata'] = 'The plugin only transcribes course material added by teachers and stores no personal data.';
$string['pulseactive'] = 'Pulse is active in this course since {$a->date} ({$a->source}): its videos are transcribed automatically.';
$string['pulsedisabledmsg'] = 'Pulse deactivated for this course: new videos will not be transcribed. Existing transcripts are kept.';
$string['pulseenabledmsg'] = 'Pulse activated for this course: {$a} video(s) sent to transcription.';
$string['pulseinactive'] = 'Pulse is not active in this course, so its videos are not transcribed.';
$string['scope'] = 'Courses to process';
$string['scope_desc'] = 'Which courses are searched for videos. "Courses where Pulse is active" is driven by Pulse: it activates a course through the local_videotranscriber_set_course_enabled web service (or, if enabled below, by asking for its transcripts).';
$string['scopeall'] = 'All courses';
$string['scopecategories'] = 'Selected categories';
$string['scopepulse'] = 'Courses where Pulse is active';
$string['settingsintro'] = 'Videos are separated and transcribed with the engine configured in the <a href="{$a}">Video Transcriber activity settings</a> (FFmpeg or external service, models, language). Only course material is searched (File, Folder, Page, Book, Lesson, Label, activity descriptions, course sections, H5P, content bank), never files uploaded by students.';
$string['showtranscript'] = 'Show transcript';
$string['skippednoaudio'] = 'The video has no audio track.';
$string['skippedtoolong'] = 'Longer than the maximum of {$a} minutes.';
$string['sourcemanual'] = 'activated from this page';
$string['sourcerequest'] = 'activated when Pulse asked for the transcripts';
$string['sourcewebservice'] = 'activated by Pulse';
$string['statusdone'] = 'Transcribed';
$string['statuserror'] = 'Error';
$string['statusnew'] = 'Waiting';
$string['statusprocessing'] = 'Transcribing';
$string['statusqueued'] = 'Queued';
$string['statusskipped'] = 'Skipped';
$string['taskdiscover'] = 'Find and queue course videos for transcription';
$string['taskdiscovercourse'] = 'Find and queue the videos of a course';
$string['tasktranscribe'] = 'Transcribe a course video';
$string['timetranscribed'] = 'Transcribed on';
$string['transcriptof'] = 'Transcript: {$a}';
$string['unregistered'] = '{$a} video(s) in this course have not been picked up yet; they will be in a few minutes, or at the latest in the next nightly run.';
$string['videotranscriber:manage'] = 'Turn automatic transcription on or off for a course (for the Pulse account)';
$string['videotranscriber:view'] = 'View the transcripts of the course videos';
$string['where'] = 'Where';
