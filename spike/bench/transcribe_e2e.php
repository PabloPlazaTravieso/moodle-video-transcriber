<?php
// End-to-end test of the transcription phase: upload -> split -> transcribe (real transcriber service),
// idempotency, forced runs, service outages and rejected keys, web service access and deletion.
// Usage (inside the container, as www-data): [ENGINE=ffmpeg|service] php /bench/transcribe_e2e.php

define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');

use mod_videoai\external\get_transcript;
use mod_videoai\local\processor;
use mod_videoai\local\transcriber;
use mod_videoai\local\transcript;

\core\session\manager::set_user(get_admin());
$good = ['transcribe' => 1, 'transcriberurl' => 'http://transcriber:8000', 'transcriberkey' => 'spike-secret',
    'language' => 'es', 'transcribertimeout' => 3600, 'extractvideo' => 0,
    'pathtoffmpeg' => '/usr/bin/ffmpeg', 'pathtoffprobe' => '/usr/bin/ffprobe',
    'engine' => getenv('ENGINE') ?: 'ffmpeg',
    'whispermodel' => '/models/whisper.cpp/ggml-large-v3-turbo-q5_0.bin',
    'whispervadmodel' => '/models/whisper.cpp/ggml-silero-v5.1.2.bin',
    'whisperqueue' => 25, 'whispervadsilence' => 2, 'whisperthreads' => 8];
foreach ($good as $k => $v) {
    set_config($k, $v, 'mod_videoai');
}
$service = $good['engine'] === 'service';
echo "Engine: {$good['engine']}
";

$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $failures;
    $failures += $ok ? 0 : 1;
    printf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? " — $detail" : '');
}
function draft_with(string $path): int {
    global $USER;
    $draftid = file_get_unused_draft_itemid();
    get_file_storage()->create_file_from_pathname(['contextid' => context_user::instance($USER->id)->id,
        'component' => 'user', 'filearea' => 'draft', 'itemid' => $draftid, 'filepath' => '/',
        'filename' => basename($path)], $path);
    return $draftid;
}
function run_tasks(): float {
    global $DB;
    // Make retried tasks due now, so the test does not wait for the retry back-off.
    $DB->set_field_select('task_adhoc', 'nextruntime', 0, "classname LIKE '%mod_videoai%'");
    $start = microtime(true);
    exec('php /var/www/html/admin/cli/adhoc_task.php --execute 2>&1', $out);
    foreach ($out as $line) {
        if (preg_match('/failed|Exception|mod_videoai: /', $line)) {
            echo "    task> " . substr($line, 0, 200) . "\n";
        }
    }
    return microtime(true) - $start;
}
function tasks(string $class): int {
    global $DB;
    return $DB->count_records('task_adhoc', ['classname' => "\\mod_videoai\\task\\$class"]);
}
function rec(int $id): stdClass {
    global $DB;
    return $DB->get_record('videoai', ['id' => $id]);
}
function add_activity(stdClass $course, string $name, string $video): stdClass {
    global $DB;
    return add_moduleinfo((object) ['modulename' => 'videoai', 'module' => $DB->get_field('modules', 'id', ['name' => 'videoai']),
        'course' => $course->id, 'section' => 0, 'visible' => 1, 'name' => $name, 'intro' => '',
        'introformat' => FORMAT_HTML, 'cmidnumber' => '', 'videofile' => draft_with($video)], $course);
}
function update_video(stdClass $cm, stdClass $course, ?string $path): void {
    [$cm, , , $data] = get_moduleinfo_data(get_coursemodule_from_id('videoai', $cm->id), $course);
    if ($path) {
        $data->videofile = draft_with($path);
    } else {
        $draft = file_get_submitted_draft_itemid('videofile');
        file_prepare_draft_area($draft, context_module::instance($cm->id)->id, 'mod_videoai', 'video', 0,
            processor::video_filemanager_options());
        $data->videofile = $draft;
    }
    update_moduleinfo($cm, $data, $course);
}

$course = create_course((object) ['fullname' => 'Transcripción ' . time(), 'shortname' => 'tr' . time(), 'category' => 1]);

echo "A. New activity: split and transcription chained in one cron run\n";
$info = add_activity($course, 'Tutoría', '/samples/tts_02_tutoria_dos_voces.mp4');
$cm = get_coursemodule_from_id('videoai', $info->coursemodule);
$context = context_module::instance($cm->id);
$id = (int) $cm->instance;
$secs = run_tasks();
$r = rec($id);
$segments = transcript::get_segments($id);
check('audio separated', (int) $r->status === processor::STATUS_DONE);
check('transcribed', (int) $r->transcriptstatus === transcriber::STATUS_DONE && count($segments) > 5,
    sprintf('%d segments, lang=%s, cron %.1fs', count($segments), $r->transcriptlanguage, $secs));
check('segments ordered and timed', $segments && $segments[0]['start'] < 1 && end($segments)['end'] > 50
    && $segments === array_values(array_filter($segments, fn($s) => $s['end'] >= $s['start'])));
check('first sentence', str_starts_with($segments[0]['text'] ?? '', 'Hola, Daniel.'), $segments[0]['text'] ?? '-');
$files = array_map(fn($f) => $f->get_filename(), array_values(get_file_storage()->get_area_files($context->id,
    'mod_videoai', 'transcript', 0, 'filename', false)));
check('VTT and JSON copies', $files === ['tts_02_tutoria_dos_voces-transcript.json', 'tts_02_tutoria_dos_voces-transcript.vtt'],
    implode(', ', $files));
check('model recorded', str_contains((string) $r->transcriptmodel, 'large-v3-turbo')
    && str_contains((string) $r->transcriptmodel, $service ? 'faster-whisper' : 'FFmpeg'), (string) $r->transcriptmodel);
echo "  text for the AI:\n    " . str_replace("\n", "\n    ", implode("\n", array_slice(explode("\n",
    transcript::as_timestamped_text($id)), 0, 4))) . "\n";
$first = $r;

echo "B. Save the form without changes: nothing is re-transcribed\n";
update_video($cm, $course, null);
check('no task queued', tasks('process_video') === 0 && tasks('transcribe_audio') === 0);

echo "C. Force the separation: identical WAV, transcription skipped and status not stuck\n";
sleep(1);
processor::queue($id, true);
run_tasks();
$r = rec($id);
check('status back to done', (int) $r->transcriptstatus === transcriber::STATUS_DONE, "status {$r->transcriptstatus}");
check('not re-transcribed', (int) $r->timetranscribed === (int) $first->timetranscribed);

echo "D. \"Transcribe again\" forces a new transcription\n";
sleep(1);
transcriber::queue($id, true);
run_tasks();
$r = rec($id);
check('re-transcribed', (int) $r->transcriptstatus === transcriber::STATUS_DONE && $r->timetranscribed > $first->timetranscribed);

if ($service) {
echo "E. Service unavailable: error recorded, task kept for retry, transcript of the same audio kept\n";
set_config('transcriberurl', 'http://transcriber:9999', 'mod_videoai');
transcriber::queue($id, true);
run_tasks();
$r = rec($id);
$message = (string) $r->transcriptmessage;
check('error recorded', (int) $r->transcriptstatus === transcriber::STATUS_ERROR
    && (str_contains($message, 'no está disponible') || str_contains($message, 'not available')), strtok($message, "\n"));
check('task kept for retry', tasks('transcribe_audio') === 1);
check('previous transcript kept', count(transcript::get_segments($id)) === count($segments));
set_config('transcriberurl', $good['transcriberurl'], 'mod_videoai');
run_tasks();
$r = rec($id);
check('retry succeeds once the service is back', (int) $r->transcriptstatus === transcriber::STATUS_DONE
    && tasks('transcribe_audio') === 0);

echo "F. Wrong API key: rejected, not retried\n";
set_config('transcriberkey', 'wrong', 'mod_videoai');
transcriber::queue($id, true);
run_tasks();
$r = rec($id);
check('rejected with HTTP 401', (int) $r->transcriptstatus === transcriber::STATUS_ERROR
    && str_contains((string) $r->transcriptmessage, '401'), strtok((string) $r->transcriptmessage, "\n"));
check('not retried', tasks('transcribe_audio') === 0);
set_config('transcriberkey', $good['transcriberkey'], 'mod_videoai');
} else {
    echo "E. FFmpeg engine with a missing model: clear error, not retried, transcript of the same audio kept
";
    set_config('whispermodel', '/models/nope.bin', 'mod_videoai');
    transcriber::queue($id, true);
    run_tasks();
    $r = rec($id);
    check('error recorded', (int) $r->transcriptstatus === transcriber::STATUS_ERROR
        && str_contains((string) $r->transcriptmessage, 'nope.bin'), strtok((string) $r->transcriptmessage, "
"));
    check('not retried', tasks('transcribe_audio') === 0);
    check('previous transcript kept', count(transcript::get_segments($id)) === count($segments));
    set_config('whispermodel', $good['whispermodel'], 'mod_videoai');
}

echo "G. Transcription disabled: separation works, nothing is sent\n";
set_config('transcribe', 0, 'mod_videoai');
processor::queue($id, true);
run_tasks();
check('no transcription task', tasks('transcribe_audio') === 0 && (int) rec($id)->status === processor::STATUS_DONE);
set_config('transcribe', 1, 'mod_videoai');

echo "H. Silence and music: no invented text, speech after the pause keeps its real time\n";
update_video($cm, $course, '/samples/tts_03_silencio_y_musica.mp4');
run_tasks();
$gap = array_filter(transcript::get_segments($id), fn($s) => $s['start'] < 70 && $s['end'] > 6);
check('nothing between 6 s and 70 s', (int) rec($id)->transcriptstatus === transcriber::STATUS_DONE && !$gap,
    implode(' | ', array_column($gap, 'text')));
echo "    " . str_replace("\n", "\n    ", transcript::as_timestamped_text($id)) . "\n";

echo "I. Video without audio: transcript removed\n";
exec('ffmpeg -nostdin -loglevel error -y -f lavfi -i testsrc2=size=320x240:rate=10:duration=5 -c:v libx264 /tmp/no_audio.mp4');
update_video($cm, $course, '/tmp/no_audio.mp4');
run_tasks();
$r = rec($id);
check('separation error, transcript cleared', (int) $r->status === processor::STATUS_ERROR
    && (int) $r->transcriptstatus === transcriber::STATUS_NONE && !transcript::get_segments($id)
    && !get_file_storage()->get_area_files($context->id, 'mod_videoai', 'transcript', 0, 'id', false));

echo "J. Web service function mod_videoai_get_transcript (REST is checked from outside by bench/ws_rest.sh)\n";
update_video($cm, $course, '/samples/tts_01_clase_fotosintesis.mp4');
run_tasks();
$data = \core_external\external_api::clean_returnvalue(get_transcript::execute_returns(), get_transcript::execute($cm->id));
check('returns the transcript', $data['ready'] && count($data['segments']) > 10
    && str_starts_with($data['text'], '[00:00:00] Buenos días a todos.'), count($data['segments']) . ' segments');
$student = create_user_record('trstudent' . time(), 'Spike1234!');
$DB->update_record('user', ['id' => $student->id, 'firstname' => 'Alumna', 'lastname' => 'Prueba',
    'email' => $student->username . '@example.com']);
$student = $DB->get_record('user', ['id' => $student->id]);
enrol_try_internal_enrol($course->id, $student->id, $DB->get_field('role', 'id', ['shortname' => 'student']));
\core\session\manager::set_user($student);
try {
    get_transcript::execute($cm->id);
    check('students cannot read it by default', false);
} catch (\required_capability_exception $e) {
    check('students cannot read it by default', true, $e->errorcode);
}
\core\session\manager::set_user(get_admin());
file_put_contents('/tmp/ws_cmid', $cm->id);

echo "K. Delete an activity\n";
$info2 = add_activity($course, 'Borrar', '/samples/tts_03_silencio_y_musica.mp4');
run_tasks();
$had = $DB->count_records('videoai_segments', ['videoaiid' => $info2->instance]);
course_delete_module($info2->coursemodule);
check('segments deleted', $had > 0 && !$DB->record_exists('videoai_segments', ['videoaiid' => $info2->instance]), "had $had");

echo $failures ? "\n$failures FAILED\n" : "\nALL PASSED\n";
exit($failures ? 1 : 0);
