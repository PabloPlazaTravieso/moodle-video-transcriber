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
 * Admin settings for local_videotranscriber.
 *
 * @package    local_videotranscriber
 * @copyright  2026 Awakelab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_videotranscriber', get_string('pluginname', 'local_videotranscriber'));
    $ADMIN->add('localplugins', $settings);

    if ($ADMIN->fulltree) {
        $engineurl = new moodle_url('/admin/settings.php', ['section' => 'modsettingvideoai']);
        $settings->add(new admin_setting_heading('local_videotranscriber/intro', '',
            get_string('settingsintro', 'local_videotranscriber', $engineurl->out())));

        $settings->add(new admin_setting_configcheckbox('local_videotranscriber/enabled',
            get_string('enabled', 'local_videotranscriber'), get_string('enabled_desc', 'local_videotranscriber'), 0));

        $settings->add(new admin_setting_configselect('local_videotranscriber/scope',
            get_string('scope', 'local_videotranscriber'), get_string('scope_desc', 'local_videotranscriber'), 'pulse', [
                'pulse' => get_string('scopepulse', 'local_videotranscriber'),
                'categories' => get_string('scopecategories', 'local_videotranscriber'),
                'all' => get_string('scopeall', 'local_videotranscriber'),
            ]));

        $settings->add(new admin_setting_configcheckbox('local_videotranscriber/autoenable',
            get_string('autoenable', 'local_videotranscriber'), get_string('autoenable_desc', 'local_videotranscriber'), 1));

        $settings->add(new admin_setting_configmultiselect('local_videotranscriber/categories',
            get_string('categories', 'local_videotranscriber'), get_string('categories_desc', 'local_videotranscriber'),
            [], core_course_category::make_categories_list()));

        $settings->add(new admin_setting_configtext('local_videotranscriber/maxpertask',
            get_string('maxpertask', 'local_videotranscriber'), get_string('maxpertask_desc', 'local_videotranscriber'),
            10, PARAM_INT, 5));

        $settings->add(new admin_setting_configtext('local_videotranscriber/maxduration',
            get_string('maxduration', 'local_videotranscriber'), get_string('maxduration_desc', 'local_videotranscriber'),
            180, PARAM_INT, 5));
    }
}
