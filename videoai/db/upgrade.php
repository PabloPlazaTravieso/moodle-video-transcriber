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
 * Upgrade steps for mod_videoai.
 *
 * @package    mod_videoai
 * @copyright  2026 Awakelab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the plugin.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_videoai_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026093001) {
        // Transcription: status fields on the activity and a table of timestamped segments.
        $table = new xmldb_table('videoai');
        $fields = [
            new xmldb_field('transcriptstatus', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'timemodified'),
            new xmldb_field('transcriptmessage', XMLDB_TYPE_TEXT, null, null, null, null, null, 'transcriptstatus'),
            new xmldb_field('transcripthash', XMLDB_TYPE_CHAR, '40', null, null, null, null, 'transcriptmessage'),
            new xmldb_field('transcriptlanguage', XMLDB_TYPE_CHAR, '10', null, null, null, null, 'transcripthash'),
            new xmldb_field('transcriptmodel', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'transcriptlanguage'),
            new xmldb_field('timetranscribed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'transcriptmodel'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        $table = new xmldb_table('videoai_segments');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('videoaiid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('segmentno', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('starttime', XMLDB_TYPE_NUMBER, '10, 3', null, XMLDB_NOTNULL, null, null);
        $table->add_field('endtime', XMLDB_TYPE_NUMBER, '10, 3', null, XMLDB_NOTNULL, null, null);
        $table->add_field('text', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('videoaiid', XMLDB_KEY_FOREIGN, ['videoaiid'], 'videoai', ['id']);
        $table->add_index('videoaiid-segmentno', XMLDB_INDEX_UNIQUE, ['videoaiid', 'segmentno']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2026093001, 'videoai');
    }

    return true;
}
