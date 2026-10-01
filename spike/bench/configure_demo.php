<?php
// Configures both plugins for the local environment and creates the demo course once (called by setup.sh).
// The demo course has videos in a File, a Page and a Folder with Pulse NOT active yet (activate it from
// "Más → Transcripciones de vídeos"), plus a Video Transcriber activity that transcribes itself.
// Usage (inside the container, as www-data): php /bench/configure_demo.php

define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->libdir . '/testing/generator/lib.php');

\core\session\manager::set_user(get_admin());

// Engine: FFmpeg 8 with the whisper filter, models downloaded by setup.sh.
foreach ([
    'pathtoffmpeg' => '/usr/bin/ffmpeg', 'pathtoffprobe' => '/usr/bin/ffprobe', 'transcribe' => 1, 'engine' => 'ffmpeg',
    'whispermodel' => '/models/whisper.cpp/ggml-large-v3-turbo-q5_0.bin',
    'whispervadmodel' => '/models/whisper.cpp/ggml-silero-v5.1.2.bin', 'language' => 'es',
    // The external service, if started with "docker compose --profile service up -d transcriber".
    'transcriberurl' => 'http://transcriber:8000', 'transcriberkey' => 'spike-secret',
] as $name => $value) {
    set_config($name, $value, 'mod_videoai');
}
foreach (['enabled' => 1, 'scope' => 'pulse', 'autoenable' => 1, 'maxpertask' => 10, 'maxduration' => 180] as $name => $value) {
    set_config($name, $value, 'local_videotranscriber');
}
if (is_dir($CFG->dataroot . '/lang/es')) {
    $DB->set_field('user', 'lang', 'es', ['username' => 'admin']);
}
echo "Plugin settings applied (engine: FFmpeg + Whisper, scope: courses where Pulse is active).\n";

if ($DB->record_exists('course', ['shortname' => 'demopulse'])) {
    echo "Demo course already exists.\n";
    exit(0);
}

/**
 * A copy of a sample with new content, so it is never "already transcribed" from earlier tests.
 */
function fresh(string $sample): string {
    $out = '/tmp/demo_' . uniqid() . "_$sample.mp4";
    exec('ffmpeg -nostdin -loglevel error -y -i ' . escapeshellarg("/samples/$sample.mp4")
        . ' -c copy -metadata comment=' . escapeshellarg(uniqid('demo', true)) . ' ' . escapeshellarg($out), $o, $code);
    if ($code !== 0) {
        throw new coding_exception("Sample $sample missing: run setup.sh again");
    }
    return $out;
}
function draft(string $path, string $name): int {
    global $USER;
    $draftid = file_get_unused_draft_itemid();
    get_file_storage()->create_file_from_pathname(['contextid' => context_user::instance($USER->id)->id,
        'component' => 'user', 'filearea' => 'draft', 'itemid' => $draftid, 'filepath' => '/', 'filename' => $name], $path);
    return $draftid;
}
function add_file(int $cmid, string $component, string $area, string $path, string $name): void {
    get_file_storage()->create_file_from_pathname(['contextid' => context_module::instance($cmid)->id,
        'component' => $component, 'filearea' => $area, 'itemid' => 0, 'filepath' => '/', 'filename' => $name], $path);
}

$gen = new testing_data_generator();
$course = $gen->create_course(['fullname' => 'Demo: Transcriptor de vídeo con Pulse', 'shortname' => 'demopulse',
    'summary' => 'Curso de demostración. Sus vídeos se transcriben solos cuando Pulse se activa: '
        . 'Más → Transcripciones de vídeos → Activar ahora.', 'numsections' => 3]);

$gen->create_module('resource', ['course' => $course->id, 'section' => 1, 'name' => 'Tema 1: tutoría sobre el trabajo final',
    'files' => draft(fresh('tts_02_tutoria_dos_voces'), 'tutoria.mp4')]);
$page = $gen->create_module('page', ['course' => $course->id, 'section' => 2, 'name' => 'Tema 2: nuestra primera API REST',
    'content' => '<p>Vídeo de la clase:</p><video controls width="640" src="@@PLUGINFILE@@/clase_api_rest.mp4"></video>',
    'contentformat' => FORMAT_HTML]);
add_file($page->cmid, 'mod_page', 'content', fresh('tts_04_clase_programacion_mx'), 'clase_api_rest.mp4');
$folder = $gen->create_module('folder', ['course' => $course->id, 'section' => 3, 'name' => 'Material extra']);
add_file($folder->cmid, 'mod_folder', 'content', fresh('tts_05_clase_fotosintesis_ruido'), 'fotosintesis_aula_ruidosa.mp4');
// The activity plugin: the video uploaded into a Video Transcriber activity is separated and transcribed at once.
add_moduleinfo((object) ['modulename' => 'videoai', 'module' => $DB->get_field('modules', 'id', ['name' => 'videoai']),
    'course' => $course->id, 'section' => 0, 'visible' => 1, 'name' => 'Clase grabada: fotosíntesis', 'intro' => '',
    'introformat' => FORMAT_HTML, 'cmidnumber' => '', 'videofile' => draft(fresh('tts_01_clase_fotosintesis'), 'fotosintesis.mp4')],
    $course);
// Files added directly (Page, Folder) queued discovery tasks; with Pulse not active they do nothing.
echo "Demo course created.\n";
