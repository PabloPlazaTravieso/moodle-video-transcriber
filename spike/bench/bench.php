<?php
// Runs the plugin's own ffmpeg wrapper over every test video, outside Moodle (minimal stubs below).
class moodle_exception extends Exception {
    public $errorcode; public $debuginfo;
    public function __construct($code, $mod = '', $link = '', $a = null, $debug = null) {
        $this->errorcode = $code; $this->debuginfo = $debug; parent::__construct($code);
    }
}
class core_text { static function substr($s, $st) { return mb_substr($s, $st); } }
require '/var/www/html/mod/videoai/classes/local/ffmpeg.php';
use mod_videoai\local\ffmpeg;

$ff = new ffmpeg('/usr/bin/ffmpeg', '/usr/bin/ffprobe', 1800);
$out = '/tmp/out'; @mkdir($out);
printf("%-34s %8s %8s | %7s %7s %7s | %9s %9s | %s\n", 'file', 'dur(s)', 'MB', 'probe', 'audio', 'video', 'wav MB', 'video MB', 'result');
foreach (glob('/tmp/videos/*') as $src) {
    $name = basename($src);
    $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
    $t = []; $res = 'OK';
    $wav = "$out/$name.wav"; $vid = "$out/$name.video.$ext";
    @unlink($wav); @unlink($vid);
    try {
        $s = microtime(true); $info = $ff->probe($src); $t['probe'] = microtime(true) - $s;
        if (!$info['hasaudio']) { throw new moodle_exception('errornoaudio'); }
        $s = microtime(true); $ff->extract_audio($src, $wav); $t['audio'] = microtime(true) - $s;
        $s = microtime(true); $ff->extract_video_only($src, $vid); $t['video'] = microtime(true) - $s;
        $w = $ff->probe($wav); $v = $ff->probe($vid);
        $wavinfo = json_decode(shell_exec('ffprobe -v error -show_entries stream=codec_name,sample_rate,channels -of csv=p=0 ' . escapeshellarg($wav)));
        $res = sprintf('OK wav=%s audio-in-video=%s video-in-wav=%s wavdur=%.1f',
            trim(shell_exec('ffprobe -v error -show_entries stream=codec_name,sample_rate,channels -of csv=p=0 ' . escapeshellarg($wav))),
            $v['hasaudio'] ? 'YES(bad)' : 'no', $w['hasvideo'] ? 'YES(bad)' : 'no', $w['duration']);
    } catch (moodle_exception $e) {
        $res = 'ERR ' . $e->errorcode . ($e->debuginfo ? ': ' . substr(str_replace("\n", ' ', $e->debuginfo), 0, 90) : '');
    }
    printf("%-34s %8s %8.1f | %7s %7s %7s | %9s %9s | %s\n", substr($name, 0, 34),
        isset($info['duration']) ? round($info['duration']) : '-', filesize($src) / 1048576,
        isset($t['probe']) ? sprintf('%.2fs', $t['probe']) : '-', isset($t['audio']) ? sprintf('%.2fs', $t['audio']) : '-',
        isset($t['video']) ? sprintf('%.2fs', $t['video']) : '-',
        is_file($wav) ? sprintf('%.1f', filesize($wav) / 1048576) : '-', is_file($vid) ? sprintf('%.1f', filesize($vid) / 1048576) : '-', $res);
    unset($info);
}
