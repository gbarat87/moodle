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

namespace qtype_ordering\task;

/**
 * Adhoc task that migrates salted ordering_item tokens in question attempt data.
 * https://moodle.atlassian.net/browse/MDL-88268
 * This is a one-time task run during the upgrade process.
 *
 * @package    qtype_ordering
 * @copyright  2026 Guillaume BARAT (guillaummebarat@catalyst-au.net)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fix_ordering_tokens extends \core\task\adhoc_task {
    /** @var int Number of rows read per chunk. */
    private const CHUNKSIZE = 1000;

    /**
     * Check whether at least one row still contains salted ordering_item tokens.
     *
     * @param string $salt
     * @return bool
     */
    public static function has_salted_tokens(string $salt): bool {
        if ($salt === '') {
            return false;
        }

        $replacementsbyname = self::get_replacements_by_name($salt);
        if (empty($replacementsbyname)) {
            return false;
        }

        $lastid = 0;
        do {
            $rows = self::get_candidate_rows($lastid, self::CHUNKSIZE);
            foreach ($rows as $row) {
                $lastid = (int)$row->id;
                if (empty($replacementsbyname[$row->name])) {
                    continue;
                }
                foreach ($replacementsbyname[$row->name] as $saltedtoken => $unsaltedtoken) {
                    if ($saltedtoken !== $unsaltedtoken && strpos($row->value, $saltedtoken) !== false) {
                        return true;
                    }
                }
            }
        } while (!empty($rows));

        return false;
    }

    /**
     * Build salted->unsalted replacement maps keyed by question_attempt_step_data.name.
     *
     * @param string $salt
     * @return array<string, array<string, string>>
     */
    private static function get_replacements_by_name(string $salt): array {
        global $DB;

        $sql = "SELECT qa.id, qa.question, qa.answer
                  FROM {question_answers} qa
            INNER JOIN {question} q ON q.id = qa.question
                 WHERE q.qtype = :qtype";
        $recordset = $DB->get_recordset_sql($sql, ['qtype' => 'ordering']);
        try {
            $replacementsbyname = [];

            foreach ($recordset as $record) {
                $name = 'response_' . $record->question;
                $saltedtoken = 'ordering_item_' . md5($salt . $record->answer);
                $unsaltedtoken = 'ordering_item_' . md5($record->answer);

                if ($saltedtoken === $unsaltedtoken) {
                    continue;
                }

                $replacementsbyname[$name][$saltedtoken] = $unsaltedtoken;
            }
        } finally {
            $recordset->close();
        }

        return $replacementsbyname;
    }

    /**
     * Get a chunk of candidate rows that may contain ordering_item tokens.
     *
     * @param int $lastid
     * @param int $limit
     * @return array<int, \stdClass>
     */
    private static function get_candidate_rows(int $lastid, int $limit): array {
        global $DB;

        $namepattern = $DB->sql_like_escape('response_') . '%';
        $valuepattern = '%' . $DB->sql_like_escape('ordering_item_') . '%';

        $sql = "SELECT id, name, value
                  FROM {question_attempt_step_data}
                 WHERE id > :lastid
                   AND " . $DB->sql_like('name', ':namepattern', false) . "
                   AND " . $DB->sql_like('value', ':valuepattern', false) . "
              ORDER BY id ASC";

        return $DB->get_records_sql($sql, [
            'lastid' => $lastid,
            'namepattern' => $namepattern,
            'valuepattern' => $valuepattern,
        ], 0, $limit);
    }

    /**
     * Run qtype_ordering migration task.
     */
    public function execute() {
        global $DB, $CFG;
        $salt = !empty($CFG->passwordsaltmain) ? (string)$CFG->passwordsaltmain : '';
        if ($salt === '') {
            mtrace('qtype_ordering: skipping token migration because no salt is configured.');
            return;
        }

        $replacementsbyname = self::get_replacements_by_name($salt);
        if (empty($replacementsbyname)) {
            mtrace('qtype_ordering: no ordering answers found. Nothing to migrate.');
            return;
        }

        $lastid = 0;
        $scanned = 0;
        $updated = 0;
        do {
            $rows = self::get_candidate_rows($lastid, self::CHUNKSIZE);
            if (empty($rows)) {
                break;
            }

            $toupdate = [];
            foreach ($rows as $row) {
                $lastid = (int)$row->id;
                $scanned++;

                if (empty($replacementsbyname[$row->name])) {
                    continue;
                }

                // Replace all salted ordering tokens for this row in one pass.
                $newvalue = strtr($row->value, $replacementsbyname[$row->name]);
                if ($newvalue !== $row->value) {
                    $toupdate[$row->id] = $newvalue;
                }
            }

            if (!empty($toupdate)) {
                $transaction = $DB->start_delegated_transaction();
                foreach ($toupdate as $id => $value) {
                    $DB->set_field('question_attempt_step_data', 'value', $value, ['id' => $id]);
                    $updated++;
                }
                $transaction->allow_commit();
            }

            mtrace('qtype_ordering: scanned ' . $scanned . ' candidate rows, updated ' . $updated . '.');
        } while (true);

        if ($updated === 0) {
            mtrace('qtype_ordering: no salted ordering tokens found. Nothing to migrate.');
            return;
        }

        mtrace('qtype_ordering: salted ordering tokens migration completed. Updated rows: ' . $updated . '.');
    }
}
