<?php
// This file is part of Moodle - http://moodle.org/
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

namespace mod_zoom\output\courseformat;

use core_courseformat\output\activitybadge as base_activitybadge;

/**
 * Activity badge for Zoom meetings, showing the current meeting status.
 *
 * Displays a badge next to the activity name on the course page:
 *   - inprogress  → "进行中" / "In progress"  (green)
 *   - abouttostart → "即将开始" / "Starting soon" (yellow)
 *   - notstarted   → "尚未开始" / "Not started" (blue)
 *   - finished     → "已结束" / "Finished" (gray)
 *   - ready        → "已就绪" / "Ready" (teal) for recurring no-fixed-time meetings
 *
 * @package    mod_zoom
 * @copyright  2024 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activitybadge extends base_activitybadge {

    /**
     * Update the badge content and style based on the current meeting status.
     */
    protected function update_content(): void {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/mod/zoom/locallib.php');

        $moduleinstance = $DB->get_record('zoom', ['id' => $this->cminfo->instance], '*', MUST_EXIST);
        $config = get_config('zoom');
        $now = time();
        $status = '';
        $tooltiplines = [];

        // Recurring meeting without fixed time → "已就绪".
        if (!empty($moduleinstance->recurring) && (int)$moduleinstance->recurrence_type === ZOOM_RECURRINGTYPE_NOTIME) {
            $status = 'ready';
            $tooltiplines[] = get_string('meetingstatustooltip_recurringnotime', 'mod_zoom');
        } else {
            // Get the relevant start time.
            if (!empty($moduleinstance->recurring)) {
                $starttime = zoom_get_next_occurrence($moduleinstance);
            } else {
                $starttime = (int)$moduleinstance->start_time;
            }

            if ($starttime == 0) {
                $status = 'finished';
                $tooltiplines[] = get_string('meetingstatus_finished', 'mod_zoom');
            } else {
                $duration = (int)$moduleinstance->duration;
                $firstabletojoin = $moduleinstance->firstabletojoin;
                if ($firstabletojoin === null || $firstabletojoin < 0) {
                    $firstabletojoin = $config->firstabletojoin ?? 0;
                }
                $joinable = $starttime - ($firstabletojoin * 60);
                $endtime = $starttime + $duration;

                if ($now >= $starttime && $now <= $endtime) {
                    $status = 'inprogress';
                } else if ($now >= $joinable && $now < $starttime) {
                    $status = 'abouttostart';
                } else if ($now < $joinable) {
                    $status = 'notstarted';
                } else {
                    $status = 'finished';
                }

                $dateformat = '%Y/%m/%d %H:%M';
                $tooltiplines[] = get_string('meetingstatustooltip_start', 'mod_zoom', userdate($starttime, $dateformat));
                $durationmins = (int)ceil($duration / 60);
                $tooltiplines[] = get_string('meetingstatustooltip_duration', 'mod_zoom', $durationmins);
            }
        }

        if (!empty($status)) {
            $this->content = get_string('meetingstatus_' . $status, 'mod_zoom');

            // Map status to badge styles.
            $stylemap = [
                'inprogress' => 'bg-success text-white',
                'abouttostart' => 'badge-abouttostart text-white',
                'notstarted' => 'bg-primary text-white',
                'finished' => 'badge-finished text-white',
                'ready' => 'bg-info text-white',
            ];
            $this->style = $stylemap[$status] ?? 'badge-none';

            // Tooltip via extra attributes.
            $this->extraattributes = [
                ['name' => 'title', 'value' => implode("\n", $tooltiplines)],
            ];
        }
    }
}