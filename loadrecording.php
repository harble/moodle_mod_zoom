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
 * Load zoom meeting recording and add a record of the view.
 *
 * @package    mod_zoom
 * @copyright  2020 Nick Stefanski <nmstefanski@gmail.com>
 * @author     2021 Jwalit Shah <jwalitshah@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/moodlelib.php');
require_once(__DIR__ . '/locallib.php');

$recordingid = required_param('recordingid', PARAM_INT);

if (!get_config('zoom', 'viewrecordings')) {
    throw new moodle_exception('recordingnotvisible', 'mod_zoom');
}

[$course, $cm, $zoom] = zoom_get_instance_setup();
require_login($course, true, $cm);

$context = context_module::instance($cm->id);
$PAGE->set_context($context);

require_capability('mod/zoom:view', $context);

// Find the recording record.
$params = [
    'id' => $recordingid,
    'zoomid' => $zoom->id,
];
$rec = $DB->get_record('zoom_meeting_recordings', $params);
if (empty($rec)) {
    throw new moodle_exception('recordingnotfound', 'mod_zoom');
}

// Check visibility: for cloud recordings, respect showrecording flag.
// For manual recordings, also respect showrecording flag.
if (!has_capability('mod/zoom:addinstance', $context) && intval($rec->showrecording) !== 1) {
    throw new moodle_exception('recordingnotvisible', 'mod_zoom');
}

$params = ['recordingsid' => $rec->id, 'userid' => $USER->id];
$now = time();

// Keep track of whether someone has viewed the recording or not.
$view = $DB->get_record('zoom_meeting_recordings_view', $params);
if (!empty($view)) {
    if (empty($view->viewed)) {
        $view->viewed = 1;
        $view->timemodified = $now;
        $DB->update_record('zoom_meeting_recordings_view', $view);
    }
} else {
    $view = new stdClass();
    $view->recordingsid = $rec->id;
    $view->userid = $USER->id;
    $view->viewed = 1;
    $view->timemodified = $now;
    $view->id = $DB->insert_record('zoom_meeting_recordings_view', $view);
}

// Render central-storage videos with the media player; serve other manual files normally.
if ($rec->ismanual) {
    $fs = get_file_storage();
    $files = $fs->get_area_files($context->id, 'mod_zoom', 'recording', $rec->id, '', false);

    if (empty($files)) {
        throw new moodle_exception('recordingfilemissing', 'mod_zoom');
    }

    $file = reset($files);
    require_once($CFG->dirroot . '/repository/centralstorage/locallib.php');
    $source = (string)$file->get_source();
    $asset = repository_centralstorage_find_asset_from_file_source($source);
    $metadata = repository_centralstorage_decode_file_source($source);
    $iscentralvideo = ($metadata['storage'] ?? '') === 'centralstorage_cdn'
        && (str_starts_with((string)($metadata['contenttype'] ?? ''), 'video/')
            || repository_centralstorage_bunny_video_identifiers((string)($metadata['source'] ?? '')) !== null);

    if (($asset && $asset->provider === 'bunnystream') || $iscentralvideo) {
        if (!$asset || $asset->provider !== 'bunnystream' || $asset->status !== 'ready'
                || repository_centralstorage_bunny_video_identifiers((string)$asset->url) === null) {
            throw new moodle_exception('recordingassetunavailable', 'mod_zoom');
        }

        // Access is established by the recording checks above and its stored file reference.
        // The asset's primary course is an organisational field, not this recording's access rule.
        $signedurl = repository_centralstorage_bunny_signed_player_url((string)$asset->url);
        $signedparams = [];
        parse_str((string)parse_url($signedurl ?? '', PHP_URL_QUERY), $signedparams);
        if (!$signedurl || !is_string($signedparams['token'] ?? null)
                || !preg_match('/^[a-f0-9]{64}$/i', $signedparams['token'])
                || !is_scalar($signedparams['expires'] ?? null)
                || !ctype_digit((string)$signedparams['expires'])
                || (int)$signedparams['expires'] <= time()) {
            throw new moodle_exception('recordingsigningfailed', 'mod_zoom');
        }

        $PAGE->set_url('/mod/zoom/loadrecording.php', ['id' => $cm->id, 'recordingid' => $rec->id]);
        $PAGE->set_title(format_string($rec->name));
        $PAGE->set_heading($course->fullname);
        $PAGE->set_pagelayout('incourse');

        // Use the shared Bunny player with the URL signed for this authorised recording view.
        // Do not re-resolve it through the course-owned-asset signing endpoint.
        $player = repository_centralstorage_bunny_embed_html($signedurl, $rec->name);
        repository_centralstorage_require_bunny_player();

        echo $OUTPUT->header();
        echo $OUTPUT->heading(format_string($rec->name));
        echo html_writer::div($player, '', ['style' => 'max-width:960px;']);
        echo $OUTPUT->footer();
        exit;
    }

    $pluginfileurl = moodle_url::make_pluginfile_url(
        $context->id,
        'mod_zoom',
        'recording',
        $rec->id,
        '/',
        $file->get_filename()
    );
    $nexturl = $pluginfileurl;
} else {
    $nexturl = new moodle_url($rec->externalurl);
}

redirect($nexturl);
