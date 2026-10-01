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

/**
 * Tests for transcript formatting and the transcription service response parser.
 *
 * @package    mod_videoai
 * @category   test
 * @copyright  2026 Awakelab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_videoai\local\transcript
 * @covers     \mod_videoai\local\transcriber
 */
final class transcript_test extends \advanced_testcase {

    public function test_clock(): void {
        $this->assertSame('00:00:00', transcript::clock(0));
        $this->assertSame('00:01:05', transcript::clock(65.4));
        $this->assertSame('01:02:03.456', transcript::clock(3723.456, true));
        $this->assertSame('00:00:00.000', transcript::clock(-1, true));
    }

    public function test_to_vtt(): void {
        $vtt = transcript::to_vtt([
            ['start' => 0.5, 'end' => 2.25, 'text' => 'Buenos días.'],
            ['start' => 2.25, 'end' => 4, 'text' => "Uno --> dos\n\ntres"],
        ]);
        $this->assertSame("WEBVTT\n\n1\n00:00:00.500 --> 00:00:02.250\nBuenos días.\n\n"
            . "2\n00:00:02.250 --> 00:00:04.000\nUno -> dos\ntres\n", $vtt);
    }

    public function test_remove_hallucinations(): void {
        $segments = [
            ['start' => 0.0, 'end' => 2.0, 'text' => 'Vamos a empezar.'],
            ['start' => 35.0, 'end' => 36.0, 'text' => 'Gracias por ver el video.'],
            ['start' => 40.0, 'end' => 41.0, 'text' => '¡GRACIAS!'],
            ['start' => 45.0, 'end' => 47.0, 'text' => 'Subtítulos realizados por la comunidad de Amara.org'],
            ['start' => 70.0, 'end' => 72.0, 'text' => 'Gracias por ver el video de ayer, hoy seguimos.'],
        ];
        // Only whole stock phrases go; a real sentence that contains one stays.
        $this->assertSame([$segments[0], $segments[4]], transcript::remove_hallucinations($segments));
    }

    public function test_parse_srt(): void {
        // As written by FFmpeg's whisper filter: index from 0, comma milliseconds; plus CRLF, a two-line cue,
        // a cue without index and an empty cue.
        $srt = "0\n00:00:00,000 --> 00:00:03,880\nHola, \"Daniel\".\n\n1\r\n00:01:04,660 --> 00:01:10,080\r\n"
            . "Primera línea\r\nsegunda línea\r\n\r\n00:01:11.000 --> 00:01:12.500\nSin índice\n\n"
            . "3\n00:01:13,000 --> 00:01:14,000\n   \n";
        $this->assertSame([
            ['start' => 0.0, 'end' => 3.88, 'text' => 'Hola, "Daniel".'],
            ['start' => 64.66, 'end' => 70.08, 'text' => 'Primera línea segunda línea'],
            ['start' => 71.0, 'end' => 72.5, 'text' => 'Sin índice'],
        ], transcript::parse_srt($srt));
        $this->assertSame([], transcript::parse_srt(''));
    }

    public function test_parse_response(): void {
        $result = transcriber::parse_response(json_encode([
            'language' => 'es', 'duration' => 10.5, 'model' => 'faster-whisper test',
            'segments' => [
                ['start' => 0, 'end' => 1.5, 'text' => ' Hola. '],
                ['start' => 1.5, 'end' => 2, 'text' => '  '],
            ],
        ]));
        $this->assertSame('es', $result['language']);
        $this->assertSame(10.5, $result['duration']);
        // Blank segments are dropped and text is trimmed.
        $this->assertSame([['start' => 0.0, 'end' => 1.5, 'text' => 'Hola.']], $result['segments']);
    }

    public function test_parse_response_rejects_bad_shapes(): void {
        foreach (['not json', '{"text": "no segments"}', '{"segments": [{"start": "x", "end": 1, "text": "a"}]}'] as $body) {
            try {
                transcriber::parse_response($body);
                $this->fail("Accepted: $body");
            } catch (\moodle_exception $e) {
                $this->assertSame('errortranscriberresponse', $e->errorcode);
            }
        }
    }

    public function test_save_and_read(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $cm = get_coursemodule_from_instance('videoai', $this->create_instance($course->id), $course->id);
        $context = \context_module::instance($cm->id);
        $videoai = $DB->get_record('videoai', ['id' => $cm->instance]);

        $segments = [['start' => 0.0, 'end' => 1.0, 'text' => 'Uno.'], ['start' => 65.0, 'end' => 66.0, 'text' => 'Dos.']];
        transcript::save($videoai, $context, $segments, 'clase-transcript', ['language' => 'es']);

        $this->assertSame($segments, transcript::get_segments($videoai->id));
        $this->assertSame("[00:00:00] Uno.\n[00:01:05] Dos.", transcript::as_timestamped_text($videoai->id));
        $files = get_file_storage()->get_area_files($context->id, 'mod_videoai', 'transcript', 0, 'filename', false);
        $this->assertSame(['clase-transcript.json', 'clase-transcript.vtt'],
            array_values(array_map(fn($f) => $f->get_filename(), $files)));

        // Saving again replaces rather than appends.
        transcript::save($videoai, $context, [$segments[0]], 'clase-transcript');
        $this->assertCount(1, transcript::get_segments($videoai->id));

        transcript::clear($videoai->id, $context);
        $this->assertSame([], transcript::get_segments($videoai->id));
        $this->assertEmpty(get_file_storage()->get_area_files($context->id, 'mod_videoai', 'transcript', 0, 'id', false));
    }

    /**
     * Create a bare activity without going through the form or queuing any task.
     *
     * @param int $courseid
     * @return int Instance id.
     */
    private function create_instance(int $courseid): int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        $id = $DB->insert_record('videoai', ['course' => $courseid, 'name' => 'Test', 'intro' => '', 'introformat' => 1]);
        $moduleid = $DB->get_field('modules', 'id', ['name' => 'videoai']);
        $cmid = add_course_module((object) ['course' => $courseid, 'module' => $moduleid, 'instance' => $id, 'section' => 0]);
        course_add_cm_to_section($courseid, $cmid, 0);
        return $id;
    }
}
