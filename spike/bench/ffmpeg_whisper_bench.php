<?php
// Transcribe the referenced samples with FFmpeg's whisper filter and write results in the format of the
// spike/asr comparison, so asr/score.py scores them next to the other engines.
// Usage (in the webserver container):
//   php /bench/ffmpeg_whisper_bench.php <name> <queue seconds> <vad 0|1> [threads] [extra filter options, e.g. vad_min_silence_duration=2]
[, $name, $queue, $vad] = $argv + [null, 'ffmpeg-whisper', 20, 1];
$threads = (int) ($argv[4] ?? 8);
$extra = $argv[5] ?? '';
$files = ['tts_01_clase_fotosintesis', 'tts_02_tutoria_dos_voces', 'tts_03_silencio_y_musica',
    'tts_04_clase_programacion_mx', 'tts_05_clase_fotosintesis_ruido', 'real_04_wikipedia_caracas',
    'real_07_wikipedia_dolores_cacuango'];
$model = '/models/whisper.cpp/ggml-large-v3-turbo-q5_0.bin';
$vadmodel = '/models/whisper.cpp/ggml-silero-v5.1.2.bin';
$out = "/asr/results/$name";
// FILTER=1 applies the plugin's own post-processing (transcript::remove_hallucinations), as the plugin stores it.
$filter_hallucinations = getenv('FILTER') === '1';
if ($filter_hallucinations) {
    define('CLI_SCRIPT', true);
    require('/var/www/html/config.php');
}
@mkdir($out, 0777, true);

function parse_srt(string $srt): array {
    $segments = [];
    foreach (preg_split('/\R\R+/', trim($srt)) as $block) {
        $lines = preg_split('/\R/', trim($block));
        if (count($lines) < 3 || !preg_match('/(\d+):(\d+):(\d+),(\d+) --> (\d+):(\d+):(\d+),(\d+)/', $lines[1], $m)) {
            continue;
        }
        $t = fn($h, $mi, $s, $ms) => $h * 3600 + $mi * 60 + $s + $ms / 1000;
        $segments[] = ['start' => $t($m[1], $m[2], $m[3], $m[4]), 'end' => $t($m[5], $m[6], $m[7], $m[8]),
            'text' => trim(implode(' ', array_slice($lines, 2)))];
    }
    return $segments;
}

$total = ['audio' => 0, 'proc' => 0];
foreach ($files as $f) {
    $wav = "/samples/audio/$f.wav";
    $srt = "/tmp/$name-$f.srt";
    @unlink($srt);
    $filter = "whisper=model=$model:language=es:queue=$queue:use_gpu=false:destination=$srt:format=srt"
        . ($vad ? ":vad_model=$vadmodel" : '') . ($extra !== '' ? ":$extra" : '');
    $cmd = ['ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error', '-filter_threads', (string) $threads,
        '-i', $wav, '-vn', '-af', $filter, '-f', 'null', '-'];
    $start = microtime(true);
    $p = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $pipes);
    $stderr = stream_get_contents($pipes[2]);
    $code = proc_close($p);
    $proc = microtime(true) - $start;
    if ($code !== 0) {
        fwrite(STDERR, "$f failed ($code): $stderr\n");
        exit(1);
    }
    $segments = parse_srt((string) file_get_contents($srt));
    if ($filter_hallucinations) {
        $segments = \mod_videoai\local\transcript::remove_hallucinations($segments);
    }
    $secs = (float) trim(shell_exec('ffprobe -v error -show_entries format=duration -of csv=p=0 ' . escapeshellarg($wav)));
    $total['audio'] += $secs;
    $total['proc'] += $proc;
    file_put_contents("$out/$f.json", json_encode(['file' => $f, 'audio_seconds' => $secs, 'seconds' => $proc,
        'text' => implode(' ', array_column($segments, 'text')), 'segments' => $segments],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    printf("%-28s %-38s audio %6.1fs  proc %6.1fs  RTF %5.3f  %d segs\n", $name, $f, $secs, $proc, $proc / $secs, count($segments));
}
$version = strtok(shell_exec('ffmpeg -version'), "\n");
file_put_contents("$out/_meta.json", json_encode(['name' => $name, 'library' => $version,
    'model' => "large-v3-turbo q5_0, greedy, queue {$queue}s, VAD " . ($vad ? 'on' : 'off') . ($extra !== '' ? ", $extra" : ''), 'threads' => $threads,
    'load_seconds' => 0, 'audio_seconds' => $total['audio'], 'proc_seconds' => $total['proc'],
    'peak_rss_mb' => getrusage(1)['ru_maxrss'] / 1024], JSON_PRETTY_PRINT));
printf("TOTAL RTF %.3f, peak RSS %.0f MB\n", $total['proc'] / $total['audio'], getrusage(1)['ru_maxrss'] / 1024);
