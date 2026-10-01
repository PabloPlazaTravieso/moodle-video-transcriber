<?php
// End-to-end spike for mod_videoai: drives the same core APIs the activity form uses
// (add_moduleinfo / update_moduleinfo) and the real adhoc task runner.
// Usage (inside the container, as www-data): php /bench/e2e.php

define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');

use mod_videoai\local\processor;

\core\session\manager::set_user(get_admin());
set_config('pathtoffmpeg', '/usr/bin/ffmpeg', 'mod_videoai');
set_config('pathtoffprobe', '/usr/bin/ffprobe', 'mod_videoai');
// E2E_EXTRACTVIDEO=1 also checks the optional silent video track; default is audio only.
$extractvideo = (bool) getenv('E2E_EXTRACTVIDEO');
set_config('extractvideo', (int) $extractvideo, 'mod_videoai');
echo $extractvideo ? "Mode: audio + silent video\n" : "Mode: audio only\n";

$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $failures;
    $failures += $ok ? 0 : 1;
    printf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? " — $detail" : '');
}

function draft_with(string $path): int {
    global $USER;
    $draftid = file_get_unused_draft_itemid();
    get_file_storage()->create_file_from_pathname([
        'contextid' => context_user::instance($USER->id)->id, 'component' => 'user', 'filearea' => 'draft',
        'itemid' => $draftid, 'filepath' => '/', 'filename' => basename($path),
    ], $path);
    return $draftid;
}

function run_tasks(): float {
    $start = microtime(true);
    exec('php /var/www/html/admin/cli/adhoc_task.php --execute 2>&1', $out);
    foreach ($out as $line) {
        if (str_contains($line, 'mod_videoai') || str_contains($line, 'failed')) {
            echo "    task> $line\n";
        }
    }
    return microtime(true) - $start;
}

function queued(): int {
    global $DB;
    return $DB->count_records('task_adhoc', ['classname' => '\\mod_videoai\\task\\process_video']);
}

function state(int $id, context_module $context): array {
    global $DB;
    $r = $DB->get_record('videoai', ['id' => $id]);
    $audio = processor::get_area_file($context, 'audio');
    $video = processor::get_area_file($context, 'videoonly');
    return [
        'status' => (int) $r->status, 'message' => (string) $r->statusmessage, 'duration' => $r->duration,
        'timeprocessed' => (int) $r->timeprocessed,
        'audio' => $audio?->get_filename(), 'videoonly' => $video?->get_filename(),
    ];
}

function show(array $s): string {
    return sprintf('status=%d audio=%s videoonly=%s duration=%s%s', $s['status'], $s['audio'] ?? '-',
        $s['videoonly'] ?? '-', $s['duration'] ?? '-', $s['message'] ? ' msg=' . strtok($s['message'], "\n") : '');
}

$videos = '/tmp/videos';
$course = create_course((object) ['fullname' => 'Spike course ' . time(), 'shortname' => 'spike' . time(), 'category' => 1]);

echo "A. Create activity with h264_aac_1min.mp4\n";
$info = add_moduleinfo((object) [
    'modulename' => 'videoai', 'module' => $DB->get_field('modules', 'id', ['name' => 'videoai']),
    'course' => $course->id, 'section' => 0, 'visible' => 1, 'name' => 'Clase 1', 'intro' => '',
    'introformat' => FORMAT_HTML, 'cmidnumber' => '', 'videofile' => draft_with("$videos/h264_aac_1min.mp4"),
], $course);
$cm = get_coursemodule_from_id('videoai', $info->coursemodule);
$context = context_module::instance($cm->id);
$id = (int) $cm->instance;
check('queued after save', state($id, $context)['status'] === processor::STATUS_QUEUED && queued() === 1);
$secs = run_tasks();
$s = state($id, $context);
check('processed', $s['status'] === processor::STATUS_DONE, show($s) . sprintf(' (cron run %.1fs)', $secs));
check('audio named after video', $s['audio'] === 'h264_aac_1min-audio.wav');
check($extractvideo ? 'video-only named after video' : 'no silent video stored',
    $s['videoonly'] === ($extractvideo ? 'h264_aac_1min-video.mp4' : null));
check('duration ~60s', abs((float) $s['duration'] - 60) < 0.5);
$first = $s;

echo "B. Save the form again without changing the video\n";
$draft = file_get_submitted_draft_itemid('videofile');
file_prepare_draft_area($draft, $context->id, 'mod_videoai', 'video', 0, processor::video_filemanager_options());
[$cm, $context, , $data] = get_moduleinfo_data($cm, $course);
$data->videofile = $draft;
$data->name = 'Clase 1 (renombrada)';
update_moduleinfo($cm, $data, $course);
$s = state($id, $context);
check('not requeued', queued() === 0 && $s['status'] === processor::STATUS_DONE, show($s));

echo "C. Replace the video with vp9_opus_1min.webm\n";
$cm = get_coursemodule_from_id('videoai', $cm->id);
[$cm, $context, , $data] = get_moduleinfo_data($cm, $course);
$data->videofile = draft_with("$videos/vp9_opus_1min.webm");
update_moduleinfo($cm, $data, $course);
check('requeued', queued() === 1);
run_tasks();
$s = state($id, $context);
check('processed new video', $s['status'] === processor::STATUS_DONE && $s['audio'] === 'vp9_opus_1min-audio.wav'
    && $s['videoonly'] === ($extractvideo ? 'vp9_opus_1min-video.webm' : null), show($s));
$areas = get_file_storage()->get_area_files($context->id, 'mod_videoai', 'audio', 0, 'id', false);
check('old outputs replaced, not accumulated', count($areas) === 1);

echo "D. Replace the video with no_audio.mp4\n";
$cm = get_coursemodule_from_id('videoai', $cm->id);
[$cm, $context, , $data] = get_moduleinfo_data($cm, $course);
$data->videofile = draft_with("$videos/no_audio.mp4");
update_moduleinfo($cm, $data, $course);
run_tasks();
$s = state($id, $context);
check('error recorded', $s['status'] === processor::STATUS_ERROR && str_contains($s['message'], get_string('errornoaudio', 'mod_videoai')), show($s));
check('no stale outputs from the previous video', $s['audio'] === null && $s['videoonly'] === null, show($s));

echo "E. Back to a good video, then force reprocess twice (duplicate tasks)\n";
$cm = get_coursemodule_from_id('videoai', $cm->id);
[$cm, $context, , $data] = get_moduleinfo_data($cm, $course);
$data->videofile = draft_with("$videos/h264_aac_1min.mp4");
update_moduleinfo($cm, $data, $course);
run_tasks();
$before = state($id, $context);
check('recovered from error', $before['status'] === processor::STATUS_DONE, show($before));
sleep(1);
processor::queue($id, true);
processor::queue($id, false);
check('two tasks queued', queued() === 2);
run_tasks();
$after = state($id, $context);
check('forced run reprocessed', $after['status'] === processor::STATUS_DONE && $after['timeprocessed'] > $before['timeprocessed'], show($after));
check('no tasks left behind', queued() === 0);

echo "F. Permissions on the separated files\n";
$users = [];
foreach (['student', 'editingteacher'] as $role) {
    $users[$role] = create_user_record("spike{$role}" . time(), 'Spike1234!');
    $DB->update_record('user', ['id' => $users[$role]->id, 'firstname' => 'Spike', 'lastname' => $role,
        'email' => "{$users[$role]->username}@example.com"]);
    enrol_try_internal_enrol($course->id, $users[$role]->id, $DB->get_field('role', 'id', ['shortname' => $role]));
}
check('student can view the activity', has_capability('mod/videoai:view', $context, $users['student']));
check('student cannot access separated files', !has_capability('mod/videoai:process', $context, $users['student']));
check('teacher can access separated files', has_capability('mod/videoai:process', $context, $users['editingteacher']));

echo "G. Task queued for an activity that is then deleted\n";
processor::queue($id, true);
$contextid = $context->id;
course_delete_module($cm->id);
run_tasks();
check('instance removed', !$DB->record_exists('videoai', ['id' => $id]));
check('files removed', !$DB->record_exists('files', ['contextid' => $contextid, 'component' => 'mod_videoai']));
check('orphan task drained', queued() === 0);

echo "H. HTTP: view page and pluginfile for a fresh activity\n";
$info = add_moduleinfo((object) [
    'modulename' => 'videoai', 'module' => $DB->get_field('modules', 'id', ['name' => 'videoai']),
    'course' => $course->id, 'section' => 0, 'visible' => 1, 'name' => 'Clase HTTP', 'intro' => '',
    'introformat' => FORMAT_HTML, 'cmidnumber' => '', 'videofile' => draft_with("$videos/clase 1 introducción á.mov"),
], $course);
run_tasks();
$context = context_module::instance($info->coursemodule);
$s = state((int) $info->instance, $context);
check('accented filename processed', $s['status'] === processor::STATUS_DONE, show($s));
file_put_contents('/tmp/e2e_cmid', $info->coursemodule);

echo "I. Silent video left over from when the option was on is removed on reprocess\n";
set_config('extractvideo', 1, 'mod_videoai');
processor::queue((int) $info->instance, true);
run_tasks();
$on = state((int) $info->instance, $context);
set_config('extractvideo', (int) $extractvideo, 'mod_videoai');
processor::queue((int) $info->instance, true);
run_tasks();
$s = state((int) $info->instance, $context);
check('option on creates the silent video', $on['videoonly'] !== null, show($on));
check($extractvideo ? 'silent video kept' : 'silent video removed once the option is off',
    ($s['videoonly'] !== null) === $extractvideo && $s['audio'] !== null, show($s));

echo "J. Storage used by one activity\n";
$sizes = $DB->get_records_sql("SELECT filearea, SUM(filesize) AS bytes FROM {files}
    WHERE contextid = ? AND component = 'mod_videoai' AND filename <> '.' GROUP BY filearea", [$context->id]);
foreach ($sizes as $area => $row) {
    printf("  %-10s %8.1f MB\n", $area, $row->bytes / 1048576);
}
printf("  %-10s %8.1f MB (outputs are %.0f%% of the source video)\n", 'total',
    array_sum(array_column($sizes, 'bytes')) / 1048576,
    100 * (array_sum(array_column($sizes, 'bytes')) - $sizes['video']->bytes) / $sizes['video']->bytes);

echo $failures ? "\n$failures FAILED\n" : "\nALL PASSED\n";
exit($failures ? 1 : 0);
