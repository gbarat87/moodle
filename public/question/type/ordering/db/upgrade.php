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

/**
 * Ordering question type db upgrade script
 *
 * @package    qtype_ordering
 * @copyright  2013 Gordon Bateson (gordon.bateson@gmail.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade code for the ordering question type.
 *
 * @param int $oldversion the version we are upgrading from.
 */
function xmldb_qtype_ordering_upgrade($oldversion) {
    global $CFG;
    // Automatically generated Moodle v4.4.0 release upgrade line.
    // Put any upgrade step following this.

    // Automatically generated Moodle v4.5.0 release upgrade line.
    // Put any upgrade step following this.

    // Automatically generated Moodle v5.0.0 release upgrade line.
    // Put any upgrade step following this.

    // Automatically generated Moodle v5.1.0 release upgrade line.
    // Put any upgrade step following this.

    // Automatically generated Moodle v5.2.0 release upgrade line.
    // Put any upgrade step following this.

    if ($oldversion < 2026042001) {
        // Before MDL-88268, answers for qtype_ordering were stored encrypted with the salted token passwordsaltmain.
        // Updating this token would break the grading for qtype_ordering as the answers won't match anymore.
        // This upgrade step creates an adhoc task that will check the passwordsaltmain and convert the existing answers
        // to a non-salted one.
        $salt = !empty($CFG->passwordsaltmain) ? (string)$CFG->passwordsaltmain : '';
        if ($salt !== '' && \qtype_ordering\task\fix_ordering_tokens::has_salted_tokens($salt)) {
            $task = new \qtype_ordering\task\fix_ordering_tokens();
            \core\task\manager::queue_adhoc_task($task, true);
            mtrace('qtype_ordering: salted ordering tokens found. Queued migration adhoc task.');
        }

        upgrade_plugin_savepoint(true, 2026042001, 'qtype', 'ordering');
    }

    return true;
}
