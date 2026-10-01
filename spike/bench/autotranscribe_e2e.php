<?php
// End-to-end test of local_videotranscriber in a real Moodle with real transcription (mod_videoai engine).
// Usage (inside the container, as www-data): php /bench/autotranscribe_e2e.php
// Writes /tmp/auto_e2e.json with the ids used, for bench/auto_http.sh.

define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->libdir . '/testing/generator/lib.php');

use local_videotranscriber\local\discovery;
use local_videotranscriber\local\videos;

\core\session\manager::set_user(get_admin());
$gen = new testing_data_generator();

$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $failures;
    $failures += $ok ? 0 : 1;
    printf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? " — $detail" : '');
}
function run_cron(): array {
    $out = [];
    exec("php /var/www/html/admin/cli/scheduled_task.php --execute='\\local_videotranscriber\\task\\discover_videos' 2>&1", $out);
    $summary = preg_grep('/local_videotranscriber:/', $out);
    $start = microtime(true);
    exec('php /var/www/html/admin/cli/adhoc_task.php --execute 2>&1', $adhoc);
    foreach (preg_grep('/failed|Exception/', $adhoc) as $line) {
        echo "    task> " . substr($line, 0, 200) . "\n";
    }
    return [trim((string) reset($summary)), microtime(true) - $start];
}
function stats_of(string $summary): array {
    preg_match('/(\d+) videos in scope, (\d+) new, (\d+) queued, (\d+) removed/', $summary, $m);
    return array_map('intval', array_slice($m, 1)) + [0, 0, 0, 0];
}
function add_file(context $context, string $component, string $filearea, string $path, ?string $name = null): stored_file {
    return get_file_storage()->create_file_from_pathname(['contextid' => $context->id, 'component' => $component,
        'filearea' => $filearea, 'itemid' => 0, 'filepath' => '/', 'filename' => $name ?? sample_name($path)], $path);
}
function sample_name(string $path): string {
    return preg_replace('/^auto_\d+-/', '', basename($path)); // Original sample name, without the per-run prefix.
}
function draft_with(string $path): int {
    global $USER;
    $draftid = file_get_unused_draft_itemid();
    get_file_storage()->create_file_from_pathname(['contextid' => context_user::instance($USER->id)->id,
        'component' => 'user', 'filearea' => 'draft', 'itemid' => $draftid, 'filepath' => '/',
        'filename' => sample_name($path)], $path);
    return $draftid;
}
function ws(int $courseid): array {
    return \core_external\external_api::clean_returnvalue(
        \local_videotranscriber\external\get_course_transcripts::execute_returns(),
        \local_videotranscriber\external\get_course_transcripts::execute($courseid));
}

$t = time();
// Transcripts are shared by content hash across the whole site, so videos from an earlier run would already be
// transcribed. Re-wrap each sample (stream copy plus a metadata tag) to get new content for this run.
$fresh = function(string $name) use ($t): string {
    $out = "/tmp/auto_$t-$name.mp4";
    exec('ffmpeg -nostdin -loglevel error -y -i ' . escapeshellarg("/samples/$name.mp4") . ' -c copy -metadata comment='
        . escapeshellarg("run $t") . ' ' . escapeshellarg($out), $o, $code);
    if ($code !== 0) {
        throw new coding_exception("Could not prepare $name");
    }
    return $out;
};
$tutoria = $fresh('tts_02_tutoria_dos_voces');
$programacion = $fresh('tts_04_clase_programacion_mx');
$fotosintesis = $fresh('tts_01_clase_fotosintesis');
$silencio = $fresh('tts_03_silencio_y_musica');
$ruido = $fresh('tts_05_clase_fotosintesis_ruido');

$cata = $gen->create_category(['name' => "Auto A $t"]);
$catsub = $gen->create_category(['name' => "Auto A sub $t", 'parent' => $cata->id]);
$catb = $gen->create_category(['name' => "Auto B $t"]);
$a1 = $gen->create_course(['category' => $cata->id, 'fullname' => "Curso A1 $t", 'shortname' => "a1$t"]);
$a2 = $gen->create_course(['category' => $catsub->id, 'fullname' => "Curso A2 $t", 'shortname' => "a2$t"]);
$b1 = $gen->create_course(['category' => $catb->id, 'fullname' => "Curso B1 $t", 'shortname' => "b1$t"]);

foreach (['enabled' => 1, 'scope' => 'categories', 'categories' => (string) $cata->id, 'maxpertask' => 10,
        'maxduration' => 180] as $k => $v) {
    set_config($k, $v, 'local_videotranscriber');
}
set_config('engine', 'ffmpeg', 'mod_videoai');
echo "Engine: " . get_config('mod_videoai', 'engine') . "\n";

echo "A. Videos in different places of the courses\n";
$res = $gen->create_module('resource', ['course' => $a1->id, 'name' => 'Tema 1: tutoría', 'files' => draft_with($tutoria)]);
$page = $gen->create_module('page', ['course' => $a1->id, 'name' => 'Tema 2: API REST',
    'content' => '<video controls src="@@PLUGINFILE@@/clase_programacion.mp4"></video>', 'contentformat' => FORMAT_HTML]);
$pagevideo = add_file(context_module::instance($page->cmid), 'mod_page', 'content', $programacion, 'clase_programacion.mp4');
$folder = $gen->create_module('folder', ['course' => $a1->id, 'name' => 'Material extra']);
add_file(context_module::instance($folder->cmid), 'mod_folder', 'content', $fotosintesis);
add_file(context_module::instance($folder->cmid), 'mod_folder', 'content', $tutoria, 'tutoria_copia.mp4');
$assign = $gen->create_module('assign', ['course' => $a1->id, 'name' => 'Entrega']);
add_file(context_module::instance($assign->cmid), 'assignsubmission_file', 'submission_files', $ruido, 'mi_entrega.mp4');
$resa2 = $gen->create_module('resource', ['course' => $a2->id, 'name' => 'Tutoría (A2)', 'files' => draft_with($tutoria)]);
$gen->create_module('resource', ['course' => $b1->id, 'name' => 'Fuera de alcance', 'files' => draft_with($silencio)]);

[$summary, $secs] = run_cron();
[$found, $new, $queued, $removed] = stats_of($summary);
check('3 distinct videos found in scope (tutoría counted once, submission and course B ignored)',
    $found === 3 && $new === 3 && $queued === 3, $summary);
$done = $DB->count_records('local_videotranscriber_video', ['status' => videos::STATUS_DONE]);
check('all 3 transcribed', $done >= 3, sprintf('%.0fs for the adhoc run', $secs));

echo "B. Web service per course\n";
$wa1 = ws($a1->id);
$byname = [];
foreach ($wa1['videos'] as $v) {
    $byname[$v['locations'][0]['filename']] = $v;
}
check('course A1 has 3 videos, all ready', count($wa1['videos']) === 3 && !in_array(false, array_column($wa1['videos'], 'ready')),
    implode(', ', array_keys($byname)));
$tut = $byname['tts_02_tutoria_dos_voces.mp4'] ?? null;
check('the tutoría is listed in both places it appears in A1',
    $tut && array_column($tut['locations'], 'activityname') === ['Tema 1: tutoría', 'Material extra'],
    $tut ? implode(' + ', array_column($tut['locations'], 'activityname')) : '-');
check('transcript text ready for the AI', $tut && str_starts_with($tut['text'], '[00:00:00] Hola, Daniel.'),
    $tut ? strtok($tut['text'], "\n") : '-');
$wa2 = ws($a2->id);
check('course A2 (subcategory) shares the same transcript', count($wa2['videos']) === 1 && $tut
    && $wa2['videos'][0]['videoid'] === $tut['videoid']);
$wb1 = ws($b1->id);
check('course B1 out of scope: nothing transcribed, video reported as not picked up',
    !$wb1['inscope'] && count($wb1['videos']) === 0 && $wb1['unregistered'] === 1);

echo "C. Running again changes nothing\n";
[$summary] = run_cron();
[$found, $new, $queued, $removed] = stats_of($summary);
check('no new, nothing queued', $new === 0 && $queued === 0 && $removed === 0, $summary);

echo "D. Teacher replaces the video in the page\n";
$oldhash = $pagevideo->get_contenthash();
$pagevideo->delete();
add_file(context_module::instance($page->cmid), 'mod_page', 'content', $silencio, 'clase_programacion.mp4');
[$summary] = run_cron();
[$found, $new, $queued, $removed] = stats_of($summary);
check('new video registered and the replaced one forgotten', $new === 1 && $removed === 1, $summary);
check('old transcript gone', !$DB->record_exists('local_videotranscriber_video', ['contenthash' => $oldhash]));
$silent = $DB->get_record('local_videotranscriber_video', ['contenthash' => sha1_file($silencio)]);
check('new video transcribed', $silent && (int) $silent->status === videos::STATUS_DONE,
    $silent ? str_replace("\n", ' | ', videos::as_timestamped_text($silent->id)) : '-');

echo "E. A copy is deleted, another remains\n";
course_delete_module($resa2->cmid);
[$summary] = run_cron();
[, , , $removed] = stats_of($summary);
check('tutoría kept (still in A1)', $removed === 0 && $tut
    && $DB->record_exists('local_videotranscriber_video', ['id' => $tut['videoid']]), $summary);

echo "F. Maximum duration\n";
set_config('maxduration', 1, 'local_videotranscriber');
add_file(context_module::instance($folder->cmid), 'mod_folder', 'content', $ruido, 'larga.mp4');
run_cron();
$long = $DB->get_record('local_videotranscriber_video', ['contenthash' => sha1_file($ruido)]);
check('video longer than 1 minute skipped with a reason', $long && (int) $long->status === videos::STATUS_SKIPPED,
    $long ? (string) $long->message : '-');
set_config('maxduration', 180, 'local_videotranscriber');

// For the HTTP checks: allow the function in the REST service used by the spike, and a student in A1.
$service = $DB->get_record('external_services', ['shortname' => 'videoai_ai']);
if ($service && !$DB->record_exists('external_services_functions', ['externalserviceid' => $service->id,
        'functionname' => 'local_videotranscriber_get_course_transcripts'])) {
    $DB->insert_record('external_services_functions', ['externalserviceid' => $service->id,
        'functionname' => 'local_videotranscriber_get_course_transcripts']);
}
$student = $gen->create_and_enrol($a1, 'student', ['username' => "autostudent$t", 'password' => 'Spike1234!']);
file_put_contents('/tmp/auto_e2e.json', json_encode(['courseid' => $a1->id, 'student' => $student->username]));

echo $failures ? "\n$failures FAILED\n" : "\nALL PASSED\n";
exit($failures ? 1 : 0);
