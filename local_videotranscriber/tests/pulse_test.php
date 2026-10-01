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

namespace local_videotranscriber;

use local_videotranscriber\local\discovery;
use local_videotranscriber\local\pulse_courses;
use local_videotranscriber\local\videos;

/**
 * Tests for the Pulse trigger: courses are transcribed when Pulse is activated in them.
 *
 * @package    local_videotranscriber
 * @category   test
 * @copyright  2026 Awakelab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_videotranscriber\local\pulse_courses
 * @covers     \local_videotranscriber\external\set_course_enabled
 * @covers     \local_videotranscriber\external\get_course_transcripts
 * @covers     \local_videotranscriber\observer
 */
final class pulse_test extends \advanced_testcase {

    /** @var \stdClass */
    private $coursea;
    /** @var \stdClass */
    private $courseb;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enabled', 1, 'local_videotranscriber');
        set_config('scope', 'pulse', 'local_videotranscriber');
        set_config('autoenable', 1, 'local_videotranscriber');
        set_config('maxpertask', 10, 'local_videotranscriber');
        $gen = $this->getDataGenerator();
        $this->coursea = $gen->create_course();
        $this->courseb = $gen->create_course();
        foreach (['a' => $this->coursea, 'b' => $this->courseb] as $name => $course) {
            $res = $gen->create_module('resource', ['course' => $course->id]);
            get_file_storage()->create_file_from_string(['contextid' => \context_module::instance($res->cmid)->id,
                'component' => 'mod_resource', 'filearea' => 'content', 'itemid' => 0, 'filepath' => '/',
                'filename' => "$name.mp4"], "video $name");
        }
    }

    /**
     * Number of transcription tasks queued.
     *
     * @return int
     */
    private function queued_transcriptions(): int {
        global $DB;
        return $DB->count_records('task_adhoc', ['classname' => '\local_videotranscriber\task\transcribe_video']);
    }

    public function test_only_pulse_courses_are_transcribed_and_start_immediately(): void {
        $this->assertSame([], discovery::find_video_hashes(), 'No course is Pulse-active yet');

        $stats = pulse_courses::enable($this->coursea->id, pulse_courses::SOURCE_WEBSERVICE);
        $this->assertSame(['found' => 1, 'registered' => 1, 'queued' => 1], $stats, 'Queued right away, not at night');
        $this->assertSame(1, $this->queued_transcriptions());
        $this->assertSame([sha1('video a')], discovery::find_video_hashes());
        $this->assertTrue(discovery::course_in_scope($this->coursea));
        $this->assertFalse(discovery::course_in_scope($this->courseb));

        // Enabling again does not queue the same video twice.
        $this->assertSame(0, pulse_courses::enable($this->coursea->id, pulse_courses::SOURCE_WEBSERVICE)['queued']);
    }

    public function test_set_course_enabled_web_service(): void {
        $result = \core_external\external_api::clean_returnvalue(external\set_course_enabled::execute_returns(),
            external\set_course_enabled::execute($this->courseb->id, true));
        $this->assertSame(['courseid' => (int) $this->courseb->id, 'enabled' => true, 'pluginenabled' => true,
            'videosfound' => 1, 'videosqueued' => 1], $result);

        $result = external\set_course_enabled::execute($this->courseb->id, false);
        $this->assertFalse($result['enabled']);
        // Turning it off keeps what was registered; nothing new is picked up.
        $this->assertTrue(pulse_courses::get($this->courseb->id) !== null);
        $this->assertSame(['found' => 0, 'registered' => 0, 'queued' => 0], discovery::discover_course($this->courseb->id));

        // A teacher cannot switch it on: it costs CPU time for every video of the course.
        $teacher = $this->getDataGenerator()->create_and_enrol($this->courseb, 'editingteacher');
        $this->setUser($teacher);
        $this->expectException(\required_capability_exception::class);
        external\set_course_enabled::execute($this->courseb->id, true);
    }

    public function test_asking_for_transcripts_activates_the_course(): void {
        $result = \core_external\external_api::clean_returnvalue(external\get_course_transcripts::execute_returns(),
            external\get_course_transcripts::execute($this->courseb->id));
        $this->assertTrue($result['activated']);
        $this->assertTrue($result['inscope']);
        $this->assertSame(pulse_courses::SOURCE_REQUEST, pulse_courses::get($this->courseb->id)->source);
        $this->assertSame(1, $this->queued_transcriptions());
        $this->assertSame(videos::STATUS_QUEUED, $result['videos'][0]['status']);

        // Second call: already active, nothing new.
        $this->assertFalse(external\get_course_transcripts::execute($this->courseb->id)['activated']);

        // With the setting off, asking does not activate.
        set_config('autoenable', 0, 'local_videotranscriber');
        $result = external\get_course_transcripts::execute($this->coursea->id);
        $this->assertFalse($result['activated']);
        $this->assertFalse(pulse_courses::is_enabled($this->coursea->id));
    }

    public function test_editing_a_pulse_course_queues_one_discovery(): void {
        global $DB;
        $count = fn() => $DB->count_records('task_adhoc', ['classname' => '\local_videotranscriber\task\discover_course']);

        $this->getDataGenerator()->create_module('page', ['course' => $this->coursea->id]);
        $this->assertSame(0, $count(), 'Course A is not Pulse-active yet');

        pulse_courses::enable($this->coursea->id, pulse_courses::SOURCE_WEBSERVICE);
        $this->getDataGenerator()->create_module('page', ['course' => $this->coursea->id]);
        $this->getDataGenerator()->create_module('folder', ['course' => $this->coursea->id]);
        $this->assertSame(1, $count(), 'One pending discovery per course, however many edits');

        set_config('enabled', 0, 'local_videotranscriber');
        $this->getDataGenerator()->create_module('page', ['course' => $this->courseb->id]);
        $this->assertSame(1, $count(), 'Nothing when the plugin is switched off');
    }
}
