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

/**
 * Backup structure of a Video Transcriber activity.
 *
 * @package    mod_videoai
 * @copyright  2026 Awakelab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * The activity record, its transcript segments and all its files.
 *
 * Nothing in the activity belongs to a user, so the same content is backed up with or without user data:
 * the separated audio and the transcript are part of the activity, and keeping them saves transcribing again.
 */
class backup_videoai_activity_structure_step extends backup_activity_structure_step {

    protected function define_structure() {
        $videoai = new backup_nested_element('videoai', ['id'], [
            'name', 'intro', 'introformat', 'status', 'statusmessage', 'videohash', 'duration', 'timeprocessed',
            'timecreated', 'timemodified', 'transcriptstatus', 'transcriptmessage', 'transcripthash',
            'transcriptlanguage', 'transcriptmodel', 'timetranscribed',
        ]);
        $segments = new backup_nested_element('segments');
        $segment = new backup_nested_element('segment', ['id'], ['segmentno', 'starttime', 'endtime', 'text']);

        $videoai->add_child($segments);
        $segments->add_child($segment);

        $videoai->set_source_table('videoai', ['id' => backup::VAR_ACTIVITYID]);
        $segment->set_source_table('videoai_segments', ['videoaiid' => backup::VAR_PARENTID], 'segmentno ASC');

        $videoai->annotate_files('mod_videoai', 'intro', null);
        foreach (['video', 'audio', 'videoonly', 'transcript'] as $area) {
            $videoai->annotate_files('mod_videoai', $area, null);
        }

        return $this->prepare_activity_structure($videoai);
    }
}
