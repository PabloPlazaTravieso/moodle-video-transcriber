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
 * Activity settings form for mod_videoai.
 *
 * @package    mod_videoai
 * @copyright  2026 Awakelab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

use mod_videoai\local\processor;

/**
 * Activity settings form.
 */
class mod_videoai_mod_form extends moodleform_mod {

    /**
     * Form definition.
     */
    public function definition() {
        global $COURSE;

        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));
        $mform->addElement('text', 'name', get_string('name'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $this->standard_intro_elements();

        $mform->addElement('filemanager', 'videofile', get_string('videofile', 'mod_videoai'), null,
            processor::video_filemanager_options((int) $COURSE->maxbytes));
        $mform->addHelpButton('videofile', 'videofile', 'mod_videoai');
        $mform->addRule('videofile', null, 'required', null, 'client');

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Load the existing video into the draft area.
     *
     * @param array $defaultvalues
     */
    public function data_preprocessing(&$defaultvalues) {
        $draftitemid = file_get_submitted_draft_itemid('videofile');
        file_prepare_draft_area($draftitemid, $this->context->id, processor::COMPONENT, 'video', 0,
            processor::video_filemanager_options());
        $defaultvalues['videofile'] = $draftitemid;
    }

    /**
     * Require a video in the draft area (the client-side rule alone can be bypassed).
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        global $USER;

        $errors = parent::validation($data, $files);
        $usercontext = context_user::instance($USER->id);
        $draftfiles = get_file_storage()->get_area_files($usercontext->id, 'user', 'draft', $data['videofile'] ?? 0,
            'id', false);
        if (!$draftfiles) {
            $errors['videofile'] = get_string('required');
        }
        return $errors;
    }
}
