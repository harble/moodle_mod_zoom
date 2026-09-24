<?php
// This file is part of the Zoom plugin for Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Upload a manual recording file for a Zoom meeting.
 *
 * @package    mod_zoom
 * @copyright  2024 UC Regents
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/locallib.php');
require_once($CFG->libdir . '/formslib.php');
require_once($CFG->libdir . '/filelib.php');

[$course, $cm, $zoom] = zoom_get_instance_setup();

require_login($course, true, $cm);

if (!get_config('zoom', 'viewrecordings')) {
    throw new moodle_exception('recordingnotvisible', 'mod_zoom');
}

$context = context_module::instance($cm->id);
require_capability('mod/zoom:addinstance', $context);

$recordingid = optional_param('recordingid', 0, PARAM_INT);

// Set up the page.
$params = ['id' => $cm->id];
if ($recordingid) {
    $params['recordingid'] = $recordingid;
}
$url = new moodle_url('/mod/zoom/uploadrecording.php', $params);
$PAGE->set_url($url);

$strname = $zoom->name;
$PAGE->set_title("$course->shortname: $strname");
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');

// Load existing recording if retransmitting.
$existingrecording = null;
if ($recordingid) {
    $existingrecording = $DB->get_record('zoom_meeting_recordings', [
        'id' => $recordingid,
        'zoomid' => $zoom->id,
        'ismanual' => 1,
    ]);
    if (!$existingrecording) {
        throw new moodle_exception('recordingnotfound', 'mod_zoom');
    }
}

/**
 * Form for uploading a manual recording.
 */
class mod_zoom_upload_recording_form extends moodleform {
    /**
     * Define the form.
     */
    protected function definition() {
        global $CFG;

        $mform = $this->_form;
        $recording = $this->_customdata['recording'] ?? null;

        // Recording name.
        $mform->addElement('text', 'name', get_string('recordingname', 'mod_zoom'), 'maxlength="300" size="50"');
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        if ($recording) {
            $mform->setDefault('name', $recording->name);
        }

        // Recording date.
        $mform->addElement('date_time_selector', 'recordingstart', get_string('recordingdate', 'mod_zoom'));
        $mform->addRule('recordingstart', null, 'required', null, 'client');
        $defaulttime = time();
        if ($recording) {
            $defaulttime = $recording->recordingstart;
        }
        $mform->setDefault('recordingstart', $defaulttime);

        // File picker.
        $maxbytes = $CFG->maxbytes;
        $maxfiles = 1;
        $mform->addElement('filepicker', 'recordingfile', get_string('recordingfile', 'mod_zoom'), null,
            ['maxbytes' => $maxbytes, 'maxfiles' => $maxfiles, 'accepted_types' => '*']);
        $mform->addRule('recordingfile', null, 'required', null, 'client');

        // Passcode (optional).
        $mform->addElement('text', 'passcode', get_string('recordingpasscode', 'mod_zoom'), 'maxlength="30" size="20"');
        $mform->setType('passcode', PARAM_TEXT);
        $mform->addHelpButton('passcode', 'recordingpasscode', 'mod_zoom');
        if ($recording) {
            $mform->setDefault('passcode', $recording->passcode);
        }

        // Visible by default.
        $mform->addElement('selectyesno', 'showrecording', get_string('recordingvisibility', 'mod_zoom'));
        $mform->addHelpButton('showrecording', 'recordingvisibility', 'mod_zoom');
        if ($recording) {
            $mform->setDefault('showrecording', $recording->showrecording);
        } else {
            $mform->setDefault('showrecording', 1);
        }

        // Hidden fields.
        $mform->addElement('hidden', 'id', $this->_customdata['cmid']);
        $mform->setType('id', PARAM_INT);

        if ($recording) {
            $mform->addElement('hidden', 'recordingid', $recording->id);
            $mform->setType('recordingid', PARAM_INT);
        }

        $this->add_action_buttons(true, get_string('save'));
    }
}

$mform = new mod_zoom_upload_recording_form(null, [
    'cmid' => $cm->id,
    'recording' => $existingrecording,
]);

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/mod/zoom/recordings.php', ['id' => $cm->id]));
} else if ($data = $mform->get_data()) {
    $now = time();

    // Save/update the recording record.
    $record = new stdClass();
    $record->zoomid = $zoom->id;
    $record->name = $data->name;
    $record->recordingstart = $data->recordingstart;
    $record->passcode = !empty($data->passcode) ? $data->passcode : '';
    $record->showrecording = $data->showrecording;
    $record->ismanual = 1;
    $record->recordingtype = 'manual';
    $record->meetinguuid = '';
    $record->zoomrecordingid = '';
    $record->externalurl = '';
    $record->timemodified = $now;

    if ($existingrecording) {
        $record->id = $existingrecording->id;
        $record->timecreated = $existingrecording->timecreated;
        $DB->update_record('zoom_meeting_recordings', $record);
        $newrecordingid = $existingrecording->id;
    } else {
        $record->timecreated = $now;
        $newrecordingid = $DB->insert_record('zoom_meeting_recordings', $record);
    }

    // Save the uploaded file.
    $record = $DB->get_record('zoom_meeting_recordings', ['id' => $newrecordingid]);
    file_save_draft_area_files(
        $data->recordingfile,
        $context->id,
        'mod_zoom',
        'recording',
        $newrecordingid,
        ['subdirs' => 0, 'maxbytes' => $CFG->maxbytes, 'maxfiles' => 1]
    );

    redirect(
        new moodle_url('/mod/zoom/recordings.php', ['id' => $cm->id]),
        get_string('recordinguploadsuccess', 'mod_zoom'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading($strname);

if ($existingrecording) {
    echo $OUTPUT->heading(get_string('retransmitrecording', 'mod_zoom'), 4);
} else {
    echo $OUTPUT->heading(get_string('uploadrecording', 'mod_zoom'), 4);
}

$mform->display();

echo $OUTPUT->footer();