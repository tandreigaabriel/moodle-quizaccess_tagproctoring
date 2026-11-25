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
 * Request boundary regressions.
 *
 * @package    quizaccess_tagproctoring
 * @copyright  2026 TAG Digital Solutions Ltd <https://tandreig.com>
 * @author     Andrei Toma
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_tagproctoring;

use quizaccess_tagproctoring\local\request_guard;

/**
 * Regression coverage for request and CSV guards.
 *
 * @covers \quizaccess_tagproctoring\local\request_guard
 */
final class request_guard_test extends \advanced_testcase {
    /**
     * Guard against every formula prefix, including whitespace.
     */
    public function test_csv_formula_prefixes(): void {
        foreach (['=1+1', '+1', '-1', '@SUM(A1)', "\t=1", '  +1', "\r@x"] as $cell) {
            $this->assertSame("'" . $cell, request_guard::csv_cell($cell));
        }
        foreach (['plain', 'a-b', 'étudiant', '', '1'] as $cell) {
            $this->assertSame($cell, request_guard::csv_cell($cell));
        }
    }

    /**
     * A valid token or POST values must never make a GET mutate state.
     */
    public function test_get_is_rejected(): void {
        $original = $_SERVER['REQUEST_METHOD'] ?? null;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        try {
            $this->expectException(\moodle_exception::class);
            request_guard::require_post();
        } finally {
            if ($original === null) {
                unset($_SERVER['REQUEST_METHOD']);
            } else {
                $_SERVER['REQUEST_METHOD'] = $original;
            }
        }
    }

    /**
     * POST must not use GET's parameter fallback.
     */
    public function test_post_does_not_read_query_parameters(): void {
        $original = $_SERVER['REQUEST_METHOD'] ?? null;
        $post = $_POST;
        $get = $_GET;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [];
        $_GET['attemptid'] = 42;
        try {
            $this->expectException(\moodle_exception::class);
            request_guard::post_param('attemptid', PARAM_INT);
        } finally {
            $_POST = $post;
            $_GET = $get;
            if ($original === null) {
                unset($_SERVER['REQUEST_METHOD']);
            } else {
                $_SERVER['REQUEST_METHOD'] = $original;
            }
        }
    }

    /**
     * Resolve actual module and reject a different quiz or course.
     */
    public function test_quiz_scope(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $a = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $b = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $this->assertEquals($a->cmid, request_guard::quiz_module($a->id, $a->cmid, $course->id)->id);
        foreach ([[$b->id, $a->cmid, $course->id], [$a->id, $a->cmid, $course->id + 1]] as $ids) {
            try {
                request_guard::quiz_module(...$ids);
                $this->fail('Mismatched routing scope was accepted.');
            } catch (\moodle_exception $e) {
                $this->assertSame('invalidparameter', $e->errorcode);
            }
        }
    }
}
