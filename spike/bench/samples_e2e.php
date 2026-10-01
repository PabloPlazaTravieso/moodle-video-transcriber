<?php
// Upload every video in /samples to a "Muestras de transcripción" course as a Video AI activity,
// process them with the real adhoc task runner (audio only) and report the separated audio.
// Usage (inside the container, as www-data): php /bench/samples_e2e.php

define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');

use mod_videoai\local\processor;

\core\session\manager::set_user(get_admin());
set_config('pathtoffmpeg', '/usr/bin/ffmpeg', 'mod_videoai');
set_config('pathtoffprobe', '/usr/bin/ffprobe', 'mod_videoai');
set_config('extractvideo', 0, 'mod_videoai');

$course = $DB->get_record('course', ['shortname' => 'muestras'])
    ?: create_course((object) ['fullname' => 'Muestras de transcripción', 'shortname' => 'muestras', 'category' => 1]);
$moduleid = $DB->get_field('modules', 'id', ['name' => 'videoai']);

$files = array_merge(glob('/samples/*.mp4'), glob('/samples/*.webm'));
sort($files);
$created = [];
foreach ($files as $path) {
    $name = pathinfo($path, PATHINFO_FILENAME);
    if ($DB->record_exists('videoai', ['course' => $course->id, 'name' => $name])) {
        continue;
    }
    $draftid = file_get_unused_draft_itemid();
    get_file_storage()->create_file_from_pathname([
        'contextid' => context_user::instance($USER->id)->id, 'component' => 'user', 'filearea' => 'draft',
        'itemid' => $draftid, 'filepath' => '/', 'filename' => basename($path),
    ], $path);
    $reference = "/samples/reference/$name.txt";
    $intro = is_file($reference)
        ? '<p><strong>Transcripción de referencia:</strong></p><p>' . nl2br(s(file_get_contents($reference))) . '</p>'
        : '<p>Voz real (Wikimedia Commons). Ver samples/SOURCES.md para autoría y licencia.</p>';
    $info = add_moduleinfo((object) [
        'modulename' => 'videoai', 'module' => $moduleid, 'course' => $course->id, 'section' => 0, 'visible' => 1,
        'name' => $name, 'intro' => $intro, 'introformat' => FORMAT_HTML, 'cmidnumber' => '', 'videofile' => $draftid,
    ], $course);
    $created[] = $info->instance;
}

$start = microtime(true);
exec('php /var/www/html/admin/cli/adhoc_task.php --execute 2>&1', $out, $code);
printf("Created %d activities, adhoc run took %.1fs (exit %d)\n\n", count($created), microtime(true) - $start, $code);

$failures = 0;
printf("%-40s %-6s %9s %9s %10s  %s\n", 'activity', 'status', 'video MB', 'dur (s)', 'audio MB', 'audio file');
foreach ($DB->get_records('videoai', ['course' => $course->id], 'name') as $v) {
    $cm = get_coursemodule_from_instance('videoai', $v->id, $course->id);
    $context = context_module::instance($cm->id);
    $video = processor::get_video_file($context);
    $audio = processor::get_area_file($context, 'audio');
    $ok = (int) $v->status === processor::STATUS_DONE && $audio && !processor::get_area_file($context, 'videoonly');
    if ($audio) {
        // Export the plugin's WAV, byte for byte, as input for the transcription comparison.
        $audio->copy_content_to("/samples/audio/{$v->name}.wav");
    }
    $failures += $ok ? 0 : 1;
    printf("%-40s %-6s %9.1f %9.1f %10.1f  %s%s\n", $v->name, $ok ? 'OK' : 'FAIL', $video->get_filesize() / 1048576,
        $v->duration, $audio ? $audio->get_filesize() / 1048576 : 0, $audio ? $audio->get_filename() : '-',
        $v->statusmessage ? '  ' . strtok($v->statusmessage, "\n") : '');
}
echo "\nCourse: {$CFG->wwwroot}/course/view.php?id={$course->id}\n";
echo $failures ? "$failures FAILED\n" : "ALL PROCESSED\n";
exit($failures ? 1 : 0);
