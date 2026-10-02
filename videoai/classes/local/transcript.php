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

namespace mod_videoai\local;

use context_module;
use stdClass;

/**
 * Stored transcript of an activity: timestamped segments in {videoai_segments} plus WebVTT and JSON copies
 * in the "transcript" file area.
 *
 * This is the API for consumers such as the question-answering AI:
 *
 *     $segments = transcript::get_segments($videoaiid);        // [{start, end, text}, ...]
 *     $prompt   = transcript::as_timestamped_text($videoaiid); // "[00:01:05] ..." one line per segment
 *
 * @package    mod_videoai
 * @copyright  2026 Awakelab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class transcript {

    /** File area holding the .vtt and .json copies. */
    public const FILEAREA = 'transcript';

    /**
     * Segments of an activity, in order.
     *
     * @param int $videoaiid
     * @return array<int, array{start: float, end: float, text: string}>
     */
    public static function get_segments(int $videoaiid): array {
        global $DB;
        $records = $DB->get_records('videoai_segments', ['videoaiid' => $videoaiid], 'segmentno', 'segmentno, starttime, endtime, text');
        return array_values(array_map(fn($r) => [
            'start' => (float) $r->starttime,
            'end' => (float) $r->endtime,
            'text' => $r->text,
        ], $records));
    }

    /**
     * The transcript as plain text, one line per segment prefixed with its start time.
     * Suited to an LLM prompt, so answers can cite where in the video something is said.
     *
     * @param int $videoaiid
     * @return string
     */
    public static function as_timestamped_text(int $videoaiid): string {
        return implode("\n", array_map(fn($s) => '[' . self::clock($s['start']) . '] ' . $s['text'],
            self::get_segments($videoaiid)));
    }

    /**
     * Replace the stored transcript.
     *
     * @param stdClass $videoai Activity record.
     * @param context_module $context
     * @param array $segments [{start, end, text}]
     * @param string $basename File name without extension for the .vtt and .json copies.
     * @param array $meta Extra fields for the JSON copy (language, model...).
     */
    public static function save(stdClass $videoai, context_module $context, array $segments, string $basename,
            array $meta = []): void {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records('videoai_segments', ['videoaiid' => $videoai->id]);
        $records = [];
        foreach (array_values($segments) as $i => $s) {
            $records[] = [
                'videoaiid' => $videoai->id,
                'segmentno' => $i,
                'starttime' => round((float) $s['start'], 3),
                'endtime' => round((float) $s['end'], 3),
                'text' => (string) $s['text'],
            ];
        }
        if ($records) {
            $DB->insert_records('videoai_segments', $records);
        }
        $transaction->allow_commit();

        $fs = get_file_storage();
        $fs->delete_area_files($context->id, processor::COMPONENT, self::FILEAREA);
        $file = [
            'contextid' => $context->id, 'component' => processor::COMPONENT, 'filearea' => self::FILEAREA,
            'itemid' => 0, 'filepath' => '/',
        ];
        $fs->create_file_from_string($file + ['filename' => clean_filename($basename . '.vtt')], self::to_vtt($segments));
        $json = $meta + ['segments' => array_values($segments)];
        $fs->create_file_from_string($file + ['filename' => clean_filename($basename . '.json')],
            json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Delete the stored transcript.
     *
     * @param int $videoaiid
     * @param context_module|null $context Also delete the file copies when given.
     */
    public static function clear(int $videoaiid, ?context_module $context = null): void {
        global $DB;
        $DB->delete_records('videoai_segments', ['videoaiid' => $videoaiid]);
        if ($context) {
            get_file_storage()->delete_area_files($context->id, processor::COMPONENT, self::FILEAREA);
        }
    }

    /**
     * WebVTT subtitles for the segments.
     *
     * @param array $segments [{start, end, text}]
     * @return string
     */
    public static function to_vtt(array $segments): string {
        $out = "WEBVTT\n";
        foreach (array_values($segments) as $i => $s) {
            // A blank line inside a cue would end it, and "-->" would be read as a timing line.
            $text = str_replace('-->', '->', preg_replace("/\n\s*\n/", "\n", trim((string) $s['text'])));
            $out .= "\n" . ($i + 1) . "\n" . self::clock($s['start'], true) . ' --> ' . self::clock($s['end'], true)
                . "\n" . $text . "\n";
        }
        return $out;
    }

    /**
     * Phrases Whisper is known to invent over silence and music, learnt from video subtitles.
     * Compared after normalisation (see normalise_phrase()).
     */
    private const HALLUCINATIONS = [
        'gracias', 'muchas gracias', 'gracias por ver', 'gracias por ver el video', 'gracias por ver el vídeo',
        'gracias por su atención', 'subtítulos realizados por la comunidad de amara org',
        'subtítulos por la comunidad de amara org', 'subtitulado por la comunidad de amara org', 'suscríbete',
        'suscríbete al canal', 'no olvides suscribirte', 'thank you', 'thanks for watching', 'thank you for watching',
        'subtitles by the amara org community',
    ];

    /**
     * Drop segments that consist only of a phrase Whisper typically invents (e.g. "Gracias por ver el video.").
     *
     * Only whole segments are dropped, never words inside a real sentence. FFmpeg's whisper filter does not
     * expose the decoder settings that would avoid them, and in the spike its VAD let music through.
     * The cost is losing a genuine "Gracias." said on its own, which carries no content for the AI.
     *
     * @param array $segments [{start, end, text}]
     * @return array
     */
    public static function remove_hallucinations(array $segments): array {
        $known = array_flip(self::HALLUCINATIONS);
        return array_values(array_filter($segments, fn($s) => !isset($known[self::normalise_phrase($s['text'])])));
    }

    /**
     * Lower case, punctuation removed and spaces collapsed, for comparing short phrases.
     *
     * @param string $text
     * @return string
     */
    private static function normalise_phrase(string $text): string {
        $text = \core_text::strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
        return trim($text);
    }

    /**
     * Read SRT subtitles (as written by FFmpeg's whisper filter) into segments.
     *
     * @param string $srt
     * @return array<int, array{start: float, end: float, text: string}>
     */
    public static function parse_srt(string $srt): array {
        $segments = [];
        $time = '(\d+):(\d{2}):(\d{2})[,.](\d{3})';
        foreach (preg_split('/\R\s*\R/', trim(str_replace("\xEF\xBB\xBF", '', $srt))) as $block) {
            $lines = preg_split('/\R/', trim($block));
            // Optional numeric index, then the timing line, then one or more lines of text.
            if ($lines && ctype_digit(trim($lines[0]))) {
                array_shift($lines);
            }
            if (count($lines) < 2 || !preg_match("/^$time\s*-->\s*$time/", trim($lines[0]), $m)) {
                continue;
            }
            $text = trim(preg_replace('/\s+/u', ' ', implode(' ', array_slice($lines, 1))));
            if ($text === '') {
                continue;
            }
            $segments[] = [
                'start' => (float) ($m[1] * 3600 + $m[2] * 60 + $m[3] + $m[4] / 1000),
                'end' => (float) ($m[5] * 3600 + $m[6] * 60 + $m[7] + $m[8] / 1000),
                'text' => $text,
            ];
        }
        return $segments;
    }

    /**
     * Format seconds as HH:MM:SS, or HH:MM:SS.mmm for WebVTT.
     *
     * @param float $seconds
     * @param bool $millis
     * @return string
     */
    public static function clock(float $seconds, bool $millis = false): string {
        $ms = (int) round(max(0, $seconds) * 1000);
        $time = sprintf('%02d:%02d:%02d', intdiv($ms, 3600000), intdiv($ms, 60000) % 60, intdiv($ms, 1000) % 60);
        return $millis ? sprintf('%s.%03d', $time, $ms % 1000) : $time;
    }

    /**
     * Format a video length as a player shows it: M:SS, or H:MM:SS from one hour.
     *
     * Used instead of format_time(), whose strings some language packs do not inflect ("1 minutos").
     *
     * @param float $seconds
     * @return string
     */
    public static function length(float $seconds): string {
        $s = (int) round(max(0, $seconds));
        return $s >= 3600
            ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s, 60) % 60, $s % 60)
            : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
    }
}
