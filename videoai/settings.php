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
 * Admin settings for mod_videoai.
 *
 * @package    mod_videoai
 * @copyright  2026 Awakelab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_configexecutable('mod_videoai/pathtoffmpeg',
        get_string('pathtoffmpeg', 'mod_videoai'), get_string('pathtoffmpeg_desc', 'mod_videoai'), '/usr/bin/ffmpeg'));

    $settings->add(new admin_setting_configexecutable('mod_videoai/pathtoffprobe',
        get_string('pathtoffprobe', 'mod_videoai'), get_string('pathtoffprobe_desc', 'mod_videoai'), '/usr/bin/ffprobe'));

    $settings->add(new admin_setting_configselect('mod_videoai/samplerate',
        get_string('samplerate', 'mod_videoai'), get_string('samplerate_desc', 'mod_videoai'), 16000, [
            16000 => '16000 Hz',
            22050 => '22050 Hz',
            44100 => '44100 Hz',
            48000 => '48000 Hz',
        ]));

    $settings->add(new admin_setting_configselect('mod_videoai/channels',
        get_string('channels', 'mod_videoai'), get_string('channels_desc', 'mod_videoai'), 1, [
            1 => get_string('channelsmono', 'mod_videoai'),
            2 => get_string('channelsstereo', 'mod_videoai'),
        ]));

    $settings->add(new admin_setting_configcheckbox('mod_videoai/extractvideo',
        get_string('extractvideo', 'mod_videoai'), get_string('extractvideo_desc', 'mod_videoai'), 0));

    $settings->add(new admin_setting_configduration('mod_videoai/timeout',
        get_string('timeout', 'mod_videoai'), get_string('timeout_desc', 'mod_videoai'), HOURSECS));
    $settings->add(new admin_setting_heading('mod_videoai/transcriptionheading',
        get_string('transcription', 'mod_videoai'), get_string('transcription_desc', 'mod_videoai')));

    $settings->add(new admin_setting_configcheckbox('mod_videoai/transcribe',
        get_string('transcribe', 'mod_videoai'), get_string('transcribe_desc', 'mod_videoai'), 0));

    $settings->add(new admin_setting_configselect('mod_videoai/engine',
        get_string('engine', 'mod_videoai'), get_string('engine_desc', 'mod_videoai'), 'ffmpeg', [
            'ffmpeg' => get_string('engineffmpeg', 'mod_videoai'),
            'service' => get_string('engineservice', 'mod_videoai'),
        ]));

    $settings->add(new admin_setting_configfile('mod_videoai/whispermodel',
        get_string('whispermodel', 'mod_videoai'), get_string('whispermodel_desc', 'mod_videoai'),
        '/opt/whisper/ggml-large-v3-turbo-q5_0.bin'));

    $settings->add(new admin_setting_configfile('mod_videoai/whispervadmodel',
        get_string('whispervadmodel', 'mod_videoai'), get_string('whispervadmodel_desc', 'mod_videoai'),
        '/opt/whisper/ggml-silero-v5.1.2.bin'));

    $settings->add(new admin_setting_configtext('mod_videoai/whisperqueue',
        get_string('whisperqueue', 'mod_videoai'), get_string('whisperqueue_desc', 'mod_videoai'), 25, PARAM_INT, 5));

    $settings->add(new admin_setting_configtext('mod_videoai/whispervadsilence',
        get_string('whispervadsilence', 'mod_videoai'), get_string('whispervadsilence_desc', 'mod_videoai'), 2, PARAM_FLOAT, 5));

    $settings->add(new admin_setting_configtext('mod_videoai/whisperthreads',
        get_string('whisperthreads', 'mod_videoai'), get_string('whisperthreads_desc', 'mod_videoai'), 0, PARAM_INT, 5));

    $settings->add(new admin_setting_configtext('mod_videoai/transcriberurl',
        get_string('transcriberurl', 'mod_videoai'), get_string('transcriberurl_desc', 'mod_videoai'),
        'http://transcriber:8000', PARAM_URL));

    $settings->add(new admin_setting_configpasswordunmask('mod_videoai/transcriberkey',
        get_string('transcriberkey', 'mod_videoai'), get_string('transcriberkey_desc', 'mod_videoai'), ''));

    $settings->add(new admin_setting_configtext('mod_videoai/language',
        get_string('language', 'mod_videoai'), get_string('language_desc', 'mod_videoai'), 'es', PARAM_ALPHANUMEXT, 5));

    $settings->add(new admin_setting_configduration('mod_videoai/transcribertimeout',
        get_string('transcribertimeout', 'mod_videoai'), get_string('transcribertimeout_desc', 'mod_videoai'),
        2 * HOURSECS));
}
