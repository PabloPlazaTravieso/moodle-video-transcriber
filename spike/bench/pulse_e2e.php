<?php
// Helper for bench/pulse_e2e.sh: sets up courses and a Pulse service account, adds videos and reports state.
// Usage (inside the container, as www-data):
//   php /bench/pulse_e2e.php setup                 -> JSON {p1, p2, p3, token}
//   php /bench/pulse_e2e.php addvideo <courseid> <sample> -> adds a folder with a fresh copy of the sample
//   php /bench/pulse_e2e.php state <courseid>      -> JSON {enabled, source, videos: [{file, status, segments, first}]}

define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->libdir . '/testing/generator/lib.php');

use local_videotranscriber\local\discovery;
use local_videotranscriber\local\pulse_courses;
use local_videotranscriber\local\videos;

\core\session\manager::set_user(get_admin());
$gen = new testing_data_generator();
$cmd = $argv[1] ?? '';

/**
 * A copy of a sample video with new content (same streams, different metadata), so it is not already transcribed.
 */
function fresh_copy(string $sample): string {
    $out = '/tmp/pulse_' . uniqid() . "_$sample.mp4";
    exec('ffmpeg -nostdin -loglevel error -y -i ' . escapeshellarg("/samples/$sample.mp4") . ' -c copy -metadata comment='
        . escapeshellarg(uniqid('pulse', true)) . ' ' . escapeshellarg($out), $o, $code);
    if ($code !== 0) {
        throw new coding_exception("ffmpeg failed for $sample");
    }
    return $out;
}

function add_video_folder(testing_data_generator $gen, int $courseid, string $sample, string $name): void {
    $path = fresh_copy($sample);
    $folder = $gen->create_module('folder', ['course' => $courseid, 'name' => $name]);
    get_file_storage()->create_file_from_pathname(['contextid' => context_module::instance($folder->cmid)->id,
        'component' => 'mod_folder', 'filearea' => 'content', 'itemid' => 0, 'filepath' => '/',
        'filename' => "$sample.mp4"], $path);
    // A teacher adding a folder fires course_module_created; the generator triggers it too.
}

if ($cmd === 'setup') {
    foreach (['enabled' => 1, 'scope' => 'pulse', 'autoenable' => 1, 'maxpertask' => 10, 'maxduration' => 180] as $k => $v) {
        set_config($k, $v, 'local_videotranscriber');
    }
    set_config('engine', 'ffmpeg', 'mod_videoai');
    $t = time();
    $courses = [];
    foreach (['p1' => 'tts_02_tutoria_dos_voces', 'p2' => 'tts_04_clase_programacion_mx', 'p3' => 'tts_01_clase_fotosintesis']
            as $key => $sample) {
        $course = $gen->create_course(['fullname' => "Pulse $key $t", 'shortname' => "pulse$key$t"]);
        // Content created before Pulse is active must not be picked up yet.
        add_video_folder($gen, $course->id, $sample, 'Vídeo inicial');
        $courses[$key] = (int) $course->id;
    }

    // The account Pulse uses: only the two capabilities of the plugin, at site level, and a token for REST.
    $user = $gen->create_user(['username' => "pulsebot$t", 'firstname' => 'Pulse', 'lastname' => 'Bot']);
    $roleid = $DB->get_field('role', 'id', ['shortname' => 'pulsebot']) ?: create_role('Pulse', 'pulsebot',
        'Cuenta de servicio del chatbot Pulse');
    set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
    $system = context_system::instance();
    // moodle/course:view: the account is not enrolled in the courses, and web services check course access.
    foreach (['local/videotranscriber:view', 'local/videotranscriber:manage', 'webservice/rest:use', 'moodle/course:view']
            as $cap) {
        assign_capability($cap, CAP_ALLOW, $roleid, $system->id, true);
    }
    role_assign($roleid, $user->id, $system->id);
    set_config('enablewebservices', 1);
    set_config('webserviceprotocols', 'rest');
    $service = $DB->get_record('external_services', ['shortname' => 'pulse_video']);
    if (!$service) {
        $service = (object) ['name' => 'Pulse: transcripciones de vídeo', 'shortname' => 'pulse_video', 'enabled' => 1,
            'restrictedusers' => 0, 'downloadfiles' => 0, 'uploadfiles' => 0, 'timecreated' => time(), 'timemodified' => time()];
        $service->id = $DB->insert_record('external_services', $service);
        foreach (['local_videotranscriber_set_course_enabled', 'local_videotranscriber_get_course_transcripts'] as $fn) {
            $DB->insert_record('external_services_functions', ['externalserviceid' => $service->id, 'functionname' => $fn]);
        }
    }
    $token = \core_external\util::generate_token(EXTERNAL_TOKEN_PERMANENT, $service, $user->id, $system);
    // Discovery queued by the folders above must not run for inactive courses: drain it now.
    exec('php /var/www/html/admin/cli/adhoc_task.php --execute 2>&1');
    echo json_encode($courses + ['token' => $token]), "\n";
} else if ($cmd === 'addvideo') {
    add_video_folder($gen, (int) $argv[2], $argv[3], 'Vídeo añadido');
    echo "ok\n";
} else if ($cmd === 'state') {
    $courseid = (int) $argv[2];
    $pulse = pulse_courses::get($courseid);
    $out = ['enabled' => (bool) ($pulse->enabled ?? false), 'source' => $pulse->source ?? '', 'videos' => []];
    foreach (discovery::locations_in_course($courseid) as $hash => $locs) {
        $v = $DB->get_record('local_videotranscriber_video', ['contenthash' => $hash]);
        $segments = $v ? videos::get_segments($v->id) : [];
        $out['videos'][] = ['file' => $locs[0]->filename, 'status' => $v ? (int) $v->status : -1,
            'segments' => count($segments), 'first' => $segments[0]['text'] ?? ''];
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE), "\n";
}
