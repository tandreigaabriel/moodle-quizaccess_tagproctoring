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
 * Risk score regressions.
 *
 * @package    quizaccess_tagproctoring
 * @copyright  2026 TAG Digital Solutions Ltd <https://tandreig.com>
 * @author     Andrei Toma
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_tagproctoring;

/**
 * Tests for quizaccess_tagproctoring_calculate_attempt_risk().
 *
 * @package    quizaccess_tagproctoring
 * @copyright  2026 TAG Digital Solutions Ltd <https://tandreig.com>
 * @author     Andrei Toma
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers ::quizaccess_tagproctoring_calculate_attempt_risk
 */
final class risk_test extends \advanced_testcase {
    /**
     * A head turned away counts 10 points per event, capped at three events; periodic
     * evidence captures are not violations and add nothing.
     */
    public function test_faceturn_is_scored_and_periodic_is_not(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/accessrule/tagproctoring/lib.php');
        $this->resetAfterTest();

        $one = quizaccess_tagproctoring_calculate_attempt_risk(['faceturn' => 1], false, null);
        $this->assertSame(10, $one['score']);
        $this->assertSame('low', $one['risk']);

        $many = quizaccess_tagproctoring_calculate_attempt_risk(['faceturn' => 5], false, null);
        $this->assertSame(30, $many['score']);
        $this->assertSame('medium', $many['risk']);

        $everything = quizaccess_tagproctoring_calculate_attempt_risk([
            'screenshare' => 1, 'multiplescreen' => 1, 'multiplefaces' => 1, 'noface' => 1, 'faceaway' => 1,
            'fullscreenexit' => 3, 'tabswitch' => 3, 'windowblur' => 3, 'copypaste' => 3,
        ], true, 0);
        $this->assertSame(100, $everything['score'], 'The score is capped at 100.');
        $this->assertSame('high', $everything['risk']);

        $periodic = quizaccess_tagproctoring_calculate_attempt_risk(['periodic' => 12, 'sessionstart' => 2], false, null);
        $this->assertSame(0, $periodic['score']);
        $this->assertSame('low', $periodic['risk']);
    }
}
