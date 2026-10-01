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

namespace local_videotranscriber\local;

use context_course;
use stdClass;
use stored_file;

/**
 * Finds the videos stored in courses through Moodle's file API.
 *
 * Every file Moodle keeps is a row of {files}; identical content shares one contenthash, so a video used in
 * several courses is registered (and transcribed) once. Only areas where teachers put course material are
 * searched: never assignment submissions, forum posts or other places where students upload their own files.
 *
 * @package    local_videotranscriber
 * @copyright  2026 Awakelab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class discovery {

    /** Course material areas, as [component, filearea]. Activity descriptions ("intro") are added for every mod_*. */
    public const TEACHER_AREAS = [
        ['course', 'section'],
        ['course', 'summary'],
        ['mod_resource', 'content'],
        ['mod_folder', 'content'],
        ['mod_page', 'content'],
        ['mod_book', 'chapter'],
        ['mod_lesson', 'page_contents'],
        ['core_h5p', 'content'],
        ['contentbank', 'public'],
    ];

    /**
     * Register the videos found in the configured scope and queue up to the configured number of them.
     *
     * @return array{found: int, registered: int, queued: int, removed: int}
     */
    public static function run(): array {
        $config = get_config('local_videotranscriber');
        $stats = ['found' => 0, 'registered' => 0, 'queued' => 0, 'removed' => 0];
        // Cleanup runs even when disabled, so deleted videos never keep their transcripts.
        $stats['removed'] = self::remove_orphans();
        if (empty($config->enabled)) {
            return $stats;
        }
        $hashes = self::find_video_hashes();
        $stats['found'] = count($hashes);
        $stats['registered'] = self::register($hashes);
        $stats['queued'] = self::queue_new((int) ($config->maxpertask ?? 10) ?: 10);
        return $stats;
    }

    /**
     * Register and queue the videos of one course right away (used when Pulse is enabled in a course and when
     * a course in scope changes), instead of waiting for the nightly run.
     *
     * @param int $courseid
     * @return array{found: int, registered: int, queued: int}
     */
    public static function discover_course(int $courseid): array {
        global $DB;
        $stats = ['found' => 0, 'registered' => 0, 'queued' => 0];
        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course || !self::course_in_scope($course)) {
            return $stats;
        }
        $hashes = array_keys(self::locations_in_course($courseid));
        $stats['found'] = count($hashes);
        $stats['registered'] = self::register($hashes);
        if ($hashes) {
            [$in, $params] = $DB->get_in_or_equal($hashes, SQL_PARAMS_NAMED);
            $limit = (int) (get_config('local_videotranscriber', 'maxpertask') ?: 10);
            $ids = array_keys($DB->get_records_sql("SELECT id FROM {local_videotranscriber_video}
                                                     WHERE status = :new AND contenthash $in ORDER BY id",
                $params + ['new' => videos::STATUS_NEW], 0, $limit));
            foreach ($ids as $id) {
                videos::queue((int) $id);
            }
            $stats['queued'] = count($ids);
        }
        return $stats;
    }

    /**
     * Queue discover_course() for a course in scope, e.g. after a teacher adds or edits an activity.
     *
     * @param int $courseid
     */
    public static function queue_course_discovery(int $courseid): void {
        global $DB;
        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course || !self::course_in_scope($course)) {
            return;
        }
        $task = new \local_videotranscriber\task\discover_course();
        $task->set_custom_data(['courseid' => $courseid]);
        // One pending discovery per course is enough, however many activities are edited before it runs.
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * The configured scope: "pulse" (default), "categories" or "all".
     *
     * @return string
     */
    public static function scope(): string {
        $scope = (string) get_config('local_videotranscriber', 'scope');
        return in_array($scope, ['pulse', 'categories', 'all'], true) ? $scope : 'pulse';
    }

    /**
     * Content hashes of the videos in course material areas within the configured scope.
     *
     * @return string[]
     */
    public static function find_video_hashes(): array {
        global $DB;
        [$where, $params] = self::video_where();
        [$scope, $scopeparams] = self::scope_where();
        if ($scope === null) {
            return [];
        }
        $sql = "SELECT DISTINCT f.contenthash
                  FROM {files} f
                  JOIN {context} ctx ON ctx.id = f.contextid
                 WHERE $where AND $scope";
        return $DB->get_fieldset_sql($sql, $params + $scopeparams);
    }

    /**
     * Add the hashes that are not registered yet.
     *
     * @param string[] $hashes
     * @return int Number of new videos.
     */
    public static function register(array $hashes): int {
        global $DB;
        $known = array_flip($DB->get_fieldset_select('local_videotranscriber_video', 'contenthash', '1 = 1'));
        $now = time();
        $new = [];
        foreach ($hashes as $hash) {
            if (!isset($known[$hash])) {
                $new[] = ['contenthash' => $hash, 'status' => videos::STATUS_NEW, 'timecreated' => $now, 'timemodified' => $now];
            }
        }
        if ($new) {
            $DB->insert_records('local_videotranscriber_video', $new);
        }
        return count($new);
    }

    /**
     * Queue a transcription task for up to $limit new videos, oldest first.
     *
     * @param int $limit
     * @return int Number queued.
     */
    public static function queue_new(int $limit): int {
        global $DB;
        // get_fieldset_sql() takes no limit (extra arguments are silently ignored), so use get_records_sql().
        $ids = array_keys($DB->get_records_sql("SELECT id FROM {local_videotranscriber_video} WHERE status = ? ORDER BY id",
            [videos::STATUS_NEW], 0, $limit));
        foreach ($ids as $id) {
            videos::queue((int) $id);
        }
        return count($ids);
    }

    /**
     * Delete the transcripts of videos that no longer exist in any course material area.
     *
     * Narrowing the scope does not delete anything: only videos removed from Moodle are forgotten.
     *
     * @return int Number removed.
     */
    public static function remove_orphans(): int {
        global $DB;
        [$where, $params] = self::video_where();
        $ids = $DB->get_fieldset_sql("SELECT v.id
                                        FROM {local_videotranscriber_video} v
                                       WHERE NOT EXISTS (SELECT 1 FROM {files} f
                                                          WHERE f.contenthash = v.contenthash AND $where)", $params);
        foreach ($ids as $id) {
            videos::delete((int) $id);
        }
        return count($ids);
    }

    /**
     * One file holding the given content in a course material area, to read the video from.
     *
     * @param string $contenthash
     * @return stored_file|null
     */
    public static function get_file(string $contenthash): ?stored_file {
        global $DB;
        [$where, $params] = self::video_where();
        $records = $DB->get_records_sql("SELECT f.* FROM {files} f WHERE f.contenthash = :hash AND $where ORDER BY f.id",
            $params + ['hash' => $contenthash], 0, 1);
        return $records ? get_file_storage()->get_file_instance(reset($records)) : null;
    }

    /**
     * Where each video of a course is: activity (or course section), file name and area.
     *
     * @param int $courseid
     * @return array<string, array> Locations keyed by content hash.
     */
    public static function locations_in_course(int $courseid): array {
        global $DB;
        $coursecontext = context_course::instance($courseid);
        [$where, $params] = self::video_where();
        $params += ['path' => $coursecontext->path, 'pathlike' => $coursecontext->path . '/%'];
        $sql = "SELECT f.id, f.contenthash, f.filename, f.component, f.filearea, ctx.contextlevel, ctx.instanceid
                  FROM {files} f
                  JOIN {context} ctx ON ctx.id = f.contextid
                 WHERE $where AND (ctx.path = :path OR " . $DB->sql_like('ctx.path', ':pathlike') . ")
              ORDER BY f.id";
        $modinfo = get_fast_modinfo($courseid);
        $locations = [];
        foreach ($DB->get_records_sql($sql, $params) as $f) {
            $cm = null;
            if ((int) $f->contextlevel === CONTEXT_MODULE) {
                $cm = $modinfo->get_cms()[$f->instanceid] ?? null;
                if (!$cm) {
                    continue; // Module being deleted.
                }
            }
            $locations[$f->contenthash][] = (object) [
                'fileid' => (int) $f->id,
                'filename' => $f->filename,
                'component' => $f->component,
                'filearea' => $f->filearea,
                'cmid' => $cm ? (int) $cm->id : 0,
                'modname' => $cm ? $cm->modname : '',
                'activityname' => $cm ? $cm->get_formatted_name() : get_string('coursematerial', 'local_videotranscriber'),
                'visible' => $cm ? (bool) $cm->visible : true,
                // Labels have no page of their own: link to the course.
                'url' => ($cm && $cm->url ? $cm->url : new \moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
            ];
        }
        return $locations;
    }

    /**
     * Whether a course is inside the configured scope.
     *
     * @param stdClass $course
     * @return bool
     */
    public static function course_in_scope(stdClass $course): bool {
        $config = get_config('local_videotranscriber');
        if (empty($config->enabled) || (int) $course->id === SITEID) {
            return false;
        }
        $scope = self::scope();
        if ($scope === 'all') {
            return true;
        }
        if ($scope === 'pulse') {
            return pulse_courses::is_enabled((int) $course->id);
        }
        $path = context_course::instance($course->id)->path;
        foreach (self::selected_category_paths() as $catpath) {
            if (str_starts_with($path, $catpath . '/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * SQL condition (on alias f) matching video files in course material areas.
     *
     * @return array{0: string, 1: array}
     */
    public static function video_where(): array {
        global $DB;
        $params = ['videomime' => 'video/%', 'modlike' => $DB->sql_like_escape('mod_') . '%', 'intro' => 'intro'];
        $areas = [];
        foreach (self::TEACHER_AREAS as $i => [$component, $filearea]) {
            $areas[] = "(f.component = :c$i AND f.filearea = :a$i)";
            $params["c$i"] = $component;
            $params["a$i"] = $filearea;
        }
        $areas[] = '(' . $DB->sql_like('f.component', ':modlike') . ' AND f.filearea = :intro)';
        $where = "f.filename <> '.' AND f.filesize > 0 AND " . $DB->sql_like('f.mimetype', ':videomime')
            . ' AND (' . implode(' OR ', $areas) . ')';
        return [$where, $params];
    }

    /**
     * SQL condition (on alias ctx) for the configured scope; null when nothing is in scope.
     *
     * @return array{0: ?string, 1: array}
     */
    protected static function scope_where(): array {
        global $DB;
        $sitepath = context_course::instance(SITEID)->path;
        $params = ['sitepath' => $sitepath, 'sitelike' => $sitepath . '/%'];
        // Courses and their activities, but not the front page.
        $base = 'ctx.contextlevel IN (' . CONTEXT_COURSE . ', ' . CONTEXT_MODULE . ')'
            . ' AND ctx.path <> :sitepath AND ' . $DB->sql_like('ctx.path', ':sitelike', true, true, true);
        $scope = self::scope();
        if ($scope === 'all') {
            return [$base, $params];
        }
        if ($scope === 'pulse') {
            // The course context itself or anything below it, for every course where Pulse is active.
            // Plain LIKE between two columns (sql_like() only takes bound parameters); paths are digits and "/".
            $within = 'ctx.path LIKE ' . $DB->sql_concat('cctx.path', "'/%'");
            return [$base . " AND EXISTS (SELECT 1
                                            FROM {local_videotranscriber_crs} vc
                                            JOIN {context} cctx ON cctx.instanceid = vc.courseid
                                                               AND cctx.contextlevel = " . CONTEXT_COURSE . "
                                           WHERE vc.enabled = 1 AND (ctx.id = cctx.id OR $within))", $params];
        }
        $paths = self::selected_category_paths();
        if (!$paths) {
            return [null, []];
        }
        $or = [];
        foreach (array_values($paths) as $i => $path) {
            $or[] = $DB->sql_like('ctx.path', ":cat$i");
            $params["cat$i"] = $path . '/%';
        }
        return [$base . ' AND (' . implode(' OR ', $or) . ')', $params];
    }

    /**
     * Context paths of the selected categories (their subcategories are included through the path prefix).
     *
     * @return string[]
     */
    protected static function selected_category_paths(): array {
        global $DB;
        $ids = array_filter(array_map('intval', explode(',', (string) get_config('local_videotranscriber', 'categories'))));
        if (!$ids) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        return $DB->get_fieldset_select('context', 'path', "contextlevel = :level AND instanceid $in",
            $params + ['level' => CONTEXT_COURSECAT]);
    }
}
