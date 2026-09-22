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
 * Delete a manually uploaded recording.
 *
 * @package    mod_zoom
 * @copyright  2024 UC Regents
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/locallib.php');
require_once($CFG->libdir . '/filelib.php');

[$course, $cm, $zoom] = zoom_get_instance_setup();

require_login($course, true, $cm);

if (!get_config('zoom', 'viewrecordings')) {
    throw new moodle_exception('recordingnotvisible', 'mod_zoom');
}

$context = context_module::instance($cm->id);
require_capability('mod/zoom:addinstance', $context);

$recordingid = required_param('recordingid', PARAM_INT);

$url = new moodle_url('/mod/zoom/recordings.php', ['id' => $cm->id]);

if (!confirm_sesskey()) {
    redirect($url, get_string('sesskeyinvalid', 'mod_zoom'));
}

// Find the manual recording.
$recording = $DB->get_record('zoom_meeting_recordings', [
    'id' => $recordingid,
    'zoomid' => $zoom->id,
    'ismanual' => 1,
]);

if (empty($recording)) {
    throw new moodle_exception('recordingnotfound', 'mod_zoom');
}

// Delete the recording record.
$DB->delete_records('zoom_meeting_recordings', ['id' => $recording->id]);

// Delete the associated file from the file system.
$fs = get_file_storage();
$fs->delete_area_files($context->id, 'mod_zoom', 'recording', $recording->id);

// Also delete any view tracking records.
$DB->delete_records('zoom_meeting_recordings_view', ['recordingsid' => $recording->id]);

redirect(
    $url,
    get_string('recordingdeleted', 'mod_zoom'),
    null,
    \core\output\notification::NOTIFY_SUCCESS
);