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
 * Adding, updating, and deleting zoom meeting recordings.
 *
 * @package    mod_zoom
 * @copyright  2020 UC Regents
 * @author     2021 Jwalit Shah <jwalitshah@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/locallib.php');

[$course, $cm, $zoom] = zoom_get_instance_setup();

require_login($course, true, $cm);

if (!get_config('zoom', 'viewrecordings')) {
    throw new moodle_exception('recordingnotvisible', 'mod_zoom');
}

$context = context_module::instance($cm->id);
// Set up the page.
$params = ['id' => $cm->id];
$url = new moodle_url('/mod/zoom/recordings.php', $params);
$PAGE->set_url($url);

$strname = $zoom->name;
$PAGE->set_title("$course->shortname: $strname");
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');

echo $OUTPUT->header();
echo $OUTPUT->heading($strname);

$iszoommanager = has_capability('mod/zoom:addinstance', $context);

// Show upload button for managers.
if ($iszoommanager) {
    $uploadurl = new moodle_url('/mod/zoom/uploadrecording.php', ['id' => $cm->id]);
    echo html_writer::div(
        html_writer::link($uploadurl, get_string('uploadrecording', 'mod_zoom'), ['class' => 'btn btn-primary mb-3']),
        'mb-3'
    );
}

// Set up html table.
$table = new html_table();
$table->attributes['class'] = 'generaltable mod_view';
if ($iszoommanager) {
    $table->align = ['left', 'left', 'left', 'left', 'left'];
    $table->head = [
        get_string('recordingmethod', 'mod_zoom'),
        get_string('recordingdate', 'mod_zoom'),
        get_string('recordinglink', 'mod_zoom'),
        get_string('recordingpasscode', 'mod_zoom'),
        get_string('actions', 'mod_zoom'),
    ];
} else {
    $table->align = ['left', 'left', 'left', 'left'];
    $table->head = [
        get_string('recordingmethod', 'mod_zoom'),
        get_string('recordingdate', 'mod_zoom'),
        get_string('recordinglink', 'mod_zoom'),
        get_string('recordingpasscode', 'mod_zoom'),
    ];
}

// Find all entries for this meeting in the database.
$recordings = zoom_get_meeting_recordings_grouped($zoom->id);
if (empty($recordings)) {
    $cell = new html_table_cell();
    $cell->colspan = count($table->head);
    $cell->text = get_string('norecordings', 'mod_zoom');
    $cell->style = 'text-align: center';
    $row = new html_table_row([$cell]);
    $table->data = [$row];
} else {
    foreach ($recordings as $grouping) {
        // Output the related recordings into the same row.
        $recordingdate = '';
        $recordinghtml = '';
        $recordingpasscode = '';
        $recordingmethodhtml = '';
        $actionhtml = '';
        foreach ($grouping as $recording) {
            // If zoom admin -> show all recordings.
            // Or if visible to students.
            if ($iszoommanager || intval($recording->showrecording) === 1) {
                if (empty($recordingdate)) {
                    $recordingdate = date('Y/m/d H:i', $recording->recordingstart);
                }

                if (empty($recordingpasscode)) {
                    $recordingpasscode = $recording->passcode;
                }

                // Recording method.
                if (empty($recordingmethodhtml)) {
                    if ($recording->ismanual) {
                        $recordingmethodhtml = get_string('recordingmethod_manual', 'mod_zoom');
                    } else {
                        $recordingmethodhtml = get_string('recordingmethod_cloud', 'mod_zoom');
                    }
                }

                // Build action column for managers.
                if ($iszoommanager && empty($actionhtml)) {
                    if ($recording->ismanual) {
                        // Manual recording: show retransmit and delete buttons.
                        $retransmiturl = new moodle_url('/mod/zoom/uploadrecording.php', [
                            'id' => $cm->id,
                            'recordingid' => $recording->id,
                        ]);
                        $retransmitbtn = html_writer::link($retransmiturl,
                            get_string('retransmitrecording', 'mod_zoom'),
                            ['class' => 'btn btn-secondary btn-sm mr-1']);

                        $deleteurl = new moodle_url('/mod/zoom/deleterecording.php', [
                            'id' => $cm->id,
                            'recordingid' => $recording->id,
                            'sesskey' => sesskey(),
                        ]);
                        $deletebtn = html_writer::link($deleteurl,
                            get_string('deleterecording', 'mod_zoom'),
                            ['class' => 'btn btn-danger btn-sm']);

                        $actionhtml = $retransmitbtn . ' ' . $deletebtn;
                    } else {
                        // Cloud recording: keep show/hide toggle.
                        $isrecordinghidden = intval($recording->showrecording) === 0;
                        $urlparams = [
                            'id' => $cm->id,
                            'meetinguuid' => $recording->meetinguuid,
                            'recordingstart' => $recording->recordingstart,
                            'showrecording' => ($isrecordinghidden) ? 1 : 0,
                            'sesskey' => sesskey(),
                        ];
                        $recordingshowurl = new moodle_url('/mod/zoom/showrecording.php', $urlparams);
                        $recordingshowtext = get_string('recordinghide', 'mod_zoom');
                        if ($isrecordinghidden) {
                            $recordingshowtext = get_string('recordingshow', 'mod_zoom');
                        }

                        $btnclass = 'btn btn-';
                        $btnclass .= $isrecordinghidden ? 'dark' : 'primary';
                        $recordingshowbutton = html_writer::div($recordingshowtext, $btnclass);
                        $actionhtml = html_writer::link($recordingshowurl, $recordingshowbutton);
                    }
                }

                if ($recording->ismanual) {
                    $recordingname = trim($recording->name);
                } else {
                    $recordingname = trim($recording->name) . ' (' . zoom_get_recording_type_string($recording->recordingtype) . ')';
                }
                $params = ['id' => $cm->id, 'recordingid' => $recording->id];
                $recordingurl = new moodle_url('/mod/zoom/loadrecording.php', $params);
                $recordinglink = html_writer::link($recordingurl, $recordingname);
                $recordinglinkhtml = html_writer::span($recordinglink, 'recording-link', ['style' => 'margin-right:1rem']);
                $recordinghtml .= html_writer::div($recordinglinkhtml, 'recording', ['style' => 'margin-bottom:.5rem']);
            }
        }

        if ($iszoommanager) {
            $table->data[] = [$recordingmethodhtml, $recordingdate, $recordinghtml, htmlspecialchars($recordingpasscode), $actionhtml];
        } else {
            $table->data[] = [$recordingmethodhtml, $recordingdate, $recordinghtml, htmlspecialchars($recordingpasscode)];
        }
    }
}

echo html_writer::table($table);

echo $OUTPUT->footer();