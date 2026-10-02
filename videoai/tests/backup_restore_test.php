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

namespace mod_videoai;

use context_module;
use mod_videoai\local\processor;
use mod_videoai\local\transcriber;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Backup and restore of Video Transcriber activities: content kept, unfinished work queued again.
 *
 * No ffmpeg is run: the activities are given the files and records that processing would leave.
 *
 * @package    mod_videoai
 * @category   test
 * @copyright  2026 Awakelab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \backup_videoai_activity_structure_step
 * @covers     \restore_videoai_activity_structure_step
 * @covers     \mod_videoai\local\processor::restored
 */
final class backup_restore_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('transcribe', 1, 'mod_videoai');
        set_config('whispermodel', '/models/whisper.cpp/model.bin', 'mod_videoai');
    }

    /**
     * An activity as left by a finished separation and transcription.
     *
     * @param \stdClass $course
     * @return \stdClass The activity record, with cmid.
     */
    private function processed_activity(\stdClass $course): \stdClass {
        global $DB;
        $videoai = $this->getDataGenerator()->create_module('videoai', ['course' => $course->id,
            'intro' => '<p>Ver <a href="' . (new \moodle_url('/course/view.php', ['id' => $course->id]))->out(false)
                . '">el curso</a></p>']);
        $context = context_module::instance($videoai->cmid);
        $fs = get_file_storage();
        $file = fn(string $area, string $name, string $content) => $fs->create_file_from_string(['contextid' => $context->id,
            'component' => 'mod_videoai', 'filearea' => $area, 'itemid' => 0, 'filepath' => '/', 'filename' => $name], $content);
        $video = $file('video', 'clase.mp4', 'video ' . uniqid());
        $audio = $file('audio', 'clase-audio.wav', 'audio ' . uniqid());
        $file('transcript', 'clase.vtt', "WEBVTT\n");
        $DB->update_record('videoai', (object) ['id' => $videoai->id, 'status' => processor::STATUS_DONE,
            'videohash' => $video->get_contenthash(), 'duration' => 12.5, 'timeprocessed' => time(),
            'transcriptstatus' => transcriber::STATUS_DONE, 'transcripthash' => $audio->get_contenthash(),
            'transcriptlanguage' => 'es', 'transcriptmodel' => 'test', 'timetranscribed' => time()]);
        foreach ([[0, 0.0, 4.2, 'Buenos días.'], [1, 4.2, 12.5, 'Hoy hablamos de la fotosíntesis.']] as [$no, $start, $end, $text]) {
            $DB->insert_record('videoai_segments', ['videoaiid' => $videoai->id, 'segmentno' => $no,
                'starttime' => $start, 'endtime' => $end, 'text' => $text]);
        }
        $DB->delete_records('task_adhoc'); // Tasks queued while setting up.
        $record = $DB->get_record('videoai', ['id' => $videoai->id]);
        $record->cmid = $videoai->cmid;
        return $record;
    }

    /**
     * Back up a whole course and restore it into a new one.
     *
     * @param int $courseid
     * @param bool $files Whether the backup includes files (the "Include files" setting).
     * @return int New course id.
     */
    private function backup_and_restore_course(int $courseid, bool $files = true): int {
        global $USER;
        $bc = new \backup_controller(\backup::TYPE_1COURSE, $courseid, \backup::FORMAT_MOODLE, \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL, $USER->id);
        $bc->get_plan()->get_setting('files')->set_value($files);
        $bc->execute_plan();
        $file = $bc->get_results()['backup_destination'];
        $bc->destroy();
        $folder = 'videoai_' . uniqid();
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), make_backup_temp_directory($folder));
        $newid = \restore_dbops::create_new_course('Restaurado', 'restaurado' . uniqid(), get_course($courseid)->category);
        $rc = new \restore_controller($folder, $newid, \backup::INTERACTIVE_NO, \backup::MODE_GENERAL, $USER->id,
            \backup::TARGET_NEW_COURSE);
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();
        return $newid;
    }

    /**
     * The restored copy of the only activity in a course.
     *
     * @param int $courseid
     * @return array [record, context]
     */
    private function restored(int $courseid): array {
        global $DB;
        $record = $DB->get_record('videoai', ['course' => $courseid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('videoai', $record->id, $courseid, false, MUST_EXIST);
        return [$record, context_module::instance($cm->id)];
    }

    private function queued_tasks(string $class): int {
        global $DB;
        return $DB->count_records('task_adhoc', ['classname' => '\\mod_videoai\\task\\' . $class]);
    }

    public function test_course_restore_keeps_video_audio_and_transcript(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $original = $this->processed_activity($course);

        $newcourseid = $this->backup_and_restore_course($course->id);
        [$copy, $context] = $this->restored($newcourseid);

        $this->assertNotEquals($original->id, $copy->id);
        $this->assertEquals(processor::STATUS_DONE, $copy->status);
        $this->assertEquals(transcriber::STATUS_DONE, $copy->transcriptstatus);
        $this->assertSame($original->videohash, $copy->videohash);
        $this->assertSame('es', $copy->transcriptlanguage);
        $this->assertSame(['Buenos días.', 'Hoy hablamos de la fotosíntesis.'],
            array_values($DB->get_fieldset_select('videoai_segments', 'text', 'videoaiid = ? ORDER BY segmentno', [$copy->id])));
        foreach (['video', 'audio', 'transcript'] as $area) {
            $this->assertNotNull(processor::get_area_file($context, $area), "file area $area restored");
        }
        $this->assertSame($original->videohash, processor::get_video_file($context)->get_contenthash());
        // The link to the original course points to the new one.
        $this->assertStringContainsString('/course/view.php?id=' . $newcourseid, $copy->intro);
        // Nothing to redo: transcribing again would waste minutes of CPU per video.
        $this->assertSame(0, $this->queued_tasks('process_video'));
        $this->assertSame(0, $this->queued_tasks('transcribe_audio'));
        // The original is untouched.
        $this->assertSame(2, $DB->count_records('videoai_segments', ['videoaiid' => $original->id]));
    }

    public function test_duplicate_activity(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $original = $this->processed_activity($course);

        $newcm = duplicate_module($course, get_fast_modinfo($course)->get_cm($original->cmid));

        $copy = $DB->get_record('videoai', ['id' => $newcm->instance]);
        $this->assertEquals(processor::STATUS_DONE, $copy->status);
        $this->assertSame(2, $DB->count_records('videoai_segments', ['videoaiid' => $copy->id]));
        $this->assertNotNull(processor::get_area_file(context_module::instance($newcm->id), 'audio'));
        $this->assertSame(0, $this->queued_tasks('transcribe_audio'));
    }

    public function test_backup_taken_while_transcribing_queues_transcription(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $original = $this->processed_activity($course);
        $DB->delete_records('videoai_segments', ['videoaiid' => $original->id]);
        $DB->set_field('videoai', 'transcriptstatus', transcriber::STATUS_PROCESSING, ['id' => $original->id]);

        [$copy] = $this->restored($this->backup_and_restore_course($course->id));

        $this->assertEquals(processor::STATUS_DONE, $copy->status);
        $this->assertEquals(transcriber::STATUS_QUEUED, $copy->transcriptstatus);
        $this->assertSame(1, $this->queued_tasks('transcribe_audio'));
        $this->assertSame(0, $this->queued_tasks('process_video'));
    }

    public function test_backup_taken_while_separating_queues_processing(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $original = $this->processed_activity($course);
        $DB->set_field('videoai', 'status', processor::STATUS_PROCESSING, ['id' => $original->id]);

        [$copy] = $this->restored($this->backup_and_restore_course($course->id));

        $this->assertEquals(processor::STATUS_QUEUED, $copy->status);
        $this->assertSame(1, $this->queued_tasks('process_video'));
    }

    public function test_backup_without_files_restored_on_the_same_site(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->processed_activity($course);

        // Core takes the file contents from this site's file pool, so the copy is complete.
        [$copy, $context] = $this->restored($this->backup_and_restore_course($course->id, false));
        $this->assertEquals(processor::STATUS_DONE, $copy->status);
        $this->assertNotNull(processor::get_area_file($context, 'audio'));
        $this->assertSame(0, $this->queued_tasks('process_video'));
    }

    public function test_restored_without_the_video_resets_the_activity(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $videoai = $this->processed_activity($course);
        $context = context_module::instance($videoai->cmid);
        // As on another site restoring a backup made without files: the records come back, the files do not.
        get_file_storage()->delete_area_files($context->id, 'mod_videoai', 'video');

        processor::restored($videoai->id, $context);

        $record = $DB->get_record('videoai', ['id' => $videoai->id]);
        $this->assertEquals(processor::STATUS_NOVIDEO, $record->status);
        $this->assertEquals(transcriber::STATUS_NONE, $record->transcriptstatus);
        $this->assertNull(processor::get_area_file($context, 'audio'));
        $this->assertSame(0, $DB->count_records('videoai_segments', ['videoaiid' => $videoai->id]));
        $this->assertSame(0, $this->queued_tasks('process_video'));
    }
}
