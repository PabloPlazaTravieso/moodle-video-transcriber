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
use local_videotranscriber\local\videos;

/**
 * Tests for finding course videos, scope, privacy exclusions, de-duplication and cleanup.
 *
 * Video "files" here are short strings with a .mp4 name: discovery only looks at the file records
 * (mime type from the extension, area, context), never at the content.
 *
 * @package    local_videotranscriber
 * @category   test
 * @copyright  2026 Awakelab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_videotranscriber\local\discovery
 * @covers     \local_videotranscriber\local\videos
 * @covers     \local_videotranscriber\external\get_course_transcripts
 */
final class discovery_test extends \advanced_testcase {

    /** @var \stdClass Category whose courses are in scope. */
    private $catin;
    /** @var \stdClass Course in $catin. */
    private $coursea;
    /** @var \stdClass Course in another category. */
    private $courseb;
    /** @var \stdClass File resource in course A. */
    private $resource;
    /** @var \stdClass Page in course A. */
    private $page;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser(); // The resource generator needs a current user.
        $gen = $this->getDataGenerator();
        $this->catin = $gen->create_category();
        $catout = $gen->create_category();
        $this->coursea = $gen->create_course(['category' => $this->catin->id]);
        $this->courseb = $gen->create_course(['category' => $catout->id]);
        $this->resource = $gen->create_module('resource', ['course' => $this->coursea->id, 'name' => 'Tema 1']);
        $this->page = $gen->create_module('page', ['course' => $this->coursea->id, 'name' => 'Tema 2']);

        set_config('enabled', 1, 'local_videotranscriber');
        set_config('scope', 'categories', 'local_videotranscriber');
        set_config('categories', (string) $this->catin->id, 'local_videotranscriber');
        set_config('maxpertask', 10, 'local_videotranscriber');
    }

    /**
     * Store a fake video.
     *
     * @param \context $context
     * @param string $component
     * @param string $filearea
     * @param string $content Identical content = same contenthash.
     * @param string $filename
     * @return \stored_file
     */
    private function add_video(\context $context, string $component, string $filearea, string $content,
            string $filename = 'clase.mp4'): \stored_file {
        return get_file_storage()->create_file_from_string(['contextid' => $context->id, 'component' => $component,
            'filearea' => $filearea, 'itemid' => 0, 'filepath' => '/', 'filename' => $filename], $content);
    }

    public function test_scope_areas_and_deduplication(): void {
        global $DB;
        $ctxres = \context_module::instance($this->resource->cmid);
        $ctxpage = \context_module::instance($this->page->cmid);
        $shared = $this->add_video($ctxres, 'mod_resource', 'content', 'shared video');
        $this->add_video($ctxpage, 'mod_page', 'content', 'page video', 'demo.webm');
        $this->add_video(\context_course::instance($this->coursea->id), 'course', 'section', 'section video');
        // The same video in a course outside the scope, and one only there.
        $res2 = $this->getDataGenerator()->create_module('resource', ['course' => $this->courseb->id]);
        $this->add_video(\context_module::instance($res2->cmid), 'mod_resource', 'content', 'shared video');
        $this->add_video(\context_module::instance($res2->cmid), 'mod_resource', 'content', 'only in b', 'b.mp4');
        // Never transcribed: a student submission, a private file and a non-video.
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $this->coursea->id]);
        $this->add_video(\context_module::instance($assign->cmid), 'assignsubmission_file', 'submission_files', 'student');
        $user = $this->getDataGenerator()->create_user();
        $this->add_video(\context_user::instance($user->id), 'user', 'private', 'private video');
        $this->add_video($ctxres, 'mod_resource', 'content', 'not a video', 'notes.pdf');

        $hashes = discovery::find_video_hashes();
        sort($hashes);
        $expected = array_map('sha1', ['page video', 'section video', 'shared video']);
        sort($expected);
        $this->assertSame($expected, $hashes);
        $this->assertSame($shared->get_contenthash(), sha1('shared video'));

        set_config('scope', 'all', 'local_videotranscriber');
        $this->assertCount(4, discovery::find_video_hashes(), 'All courses adds the video only in course B');

        // Registering twice does not duplicate.
        $this->assertSame(4, discovery::register(discovery::find_video_hashes()));
        $this->assertSame(0, discovery::register(discovery::find_video_hashes()));
        $this->assertSame(4, $DB->count_records('local_videotranscriber_video'));
    }

    public function test_run_queues_up_to_the_limit(): void {
        global $DB;
        $ctx = \context_module::instance($this->resource->cmid);
        foreach (['a', 'b', 'c'] as $name) {
            $this->add_video($ctx, 'mod_resource', 'content', "video $name", "$name.mp4");
        }
        set_config('maxpertask', 2, 'local_videotranscriber');

        $stats = discovery::run();
        $this->assertSame(['found' => 3, 'registered' => 3, 'queued' => 2, 'removed' => 0], $stats);
        $this->assertSame(2, $DB->count_records('task_adhoc', ['classname' => '\local_videotranscriber\task\transcribe_video']));
        $this->assertSame(1, $DB->count_records('local_videotranscriber_video', ['status' => videos::STATUS_NEW]));

        $stats = discovery::run();
        $this->assertSame(1, $stats['queued'], 'The remaining one goes on the next run');

        set_config('enabled', 0, 'local_videotranscriber');
        $this->assertSame(['found' => 0, 'registered' => 0, 'queued' => 0, 'removed' => 0], discovery::run());
    }

    public function test_orphans_are_removed_only_when_the_last_copy_is_gone(): void {
        global $DB;
        $first = $this->add_video(\context_module::instance($this->resource->cmid), 'mod_resource', 'content', 'video');
        $second = $this->add_video(\context_module::instance($this->page->cmid), 'mod_page', 'content', 'video');
        discovery::run();
        $video = $DB->get_record('local_videotranscriber_video', ['contenthash' => sha1('video')], '*', MUST_EXIST);
        videos::save_segments($video->id, [['start' => 0, 'end' => 1, 'text' => 'Hola.']]);

        $first->delete();
        $this->assertSame(0, discovery::remove_orphans(), 'Still in the page');
        // Narrowing the scope does not delete transcripts either.
        set_config('categories', '', 'local_videotranscriber');
        $this->assertSame(0, discovery::remove_orphans());

        $second->delete();
        $this->assertSame(1, discovery::remove_orphans());
        $this->assertFalse($DB->record_exists('local_videotranscriber_video', ['id' => $video->id]));
        $this->assertFalse($DB->record_exists('local_videotranscriber_seg', ['videoid' => $video->id]));
    }

    public function test_locations_and_web_service(): void {
        global $DB;
        $this->add_video(\context_module::instance($this->resource->cmid), 'mod_resource', 'content', 'video', 'tema1.mp4');
        $this->add_video(\context_module::instance($this->page->cmid), 'mod_page', 'content', 'video', 'repaso.mp4');
        discovery::run();
        $video = $DB->get_record('local_videotranscriber_video', ['contenthash' => sha1('video')], '*', MUST_EXIST);
        videos::save_segments($video->id, [['start' => 0.5, 'end' => 2, 'text' => 'Buenos días.'],
            ['start' => 65, 'end' => 67, 'text' => 'Empezamos.']]);
        videos::set_status($video->id, videos::STATUS_DONE, null, ['duration' => 70.0, 'timetranscribed' => time()]);

        $locations = discovery::locations_in_course($this->coursea->id)[sha1('video')];
        $this->assertSame([(int) $this->resource->cmid, (int) $this->page->cmid], array_column($locations, 'cmid'));
        $this->assertSame(['resource', 'page'], array_column($locations, 'modname'));

        $teacher = $this->getDataGenerator()->create_and_enrol($this->coursea, 'editingteacher');
        $this->setUser($teacher);
        $result = \core_external\external_api::clean_returnvalue(
            external\get_course_transcripts::execute_returns(),
            external\get_course_transcripts::execute($this->coursea->id));
        $this->assertTrue($result['inscope']);
        $this->assertCount(1, $result['videos']);
        $this->assertSame("[00:00:00] Buenos días.\n[00:01:05] Empezamos.", $result['videos'][0]['text']);
        $this->assertSame(['tema1.mp4', 'repaso.mp4'], array_column($result['videos'][0]['locations'], 'filename'));

        $student = $this->getDataGenerator()->create_and_enrol($this->coursea, 'student');
        $this->setUser($student);
        $this->expectException(\required_capability_exception::class);
        external\get_course_transcripts::execute($this->coursea->id);
    }
}
