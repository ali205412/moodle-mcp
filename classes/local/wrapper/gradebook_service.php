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

declare(strict_types=1);

namespace webservice_mcp\local\wrapper;

use context_course;
use core_external\external_api;

/**
 * Wrapper implementations for gradebook parity gaps.
 *
 * @package     webservice_mcp
 * @author      MohammadReza PourMohammad <onbirdev@gmail.com>
 * @copyright   2025 MohammadReza PourMohammad
 * @link        https://onbir.dev
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gradebook_service {
    /**
     * Create a manual grade item.
     *
     * @param int $courseid Course id.
     * @param array $payload Grade-item payload.
     * @return array
     */
    public function create_manual_item(int $courseid, array $payload): array {
        return $this->save_manual_item($courseid, 0, $payload);
    }

    /**
     * Update a manual grade item.
     *
     * @param int $courseid Course id.
     * @param int $itemid Grade item id.
     * @param array $payload Grade-item payload.
     * @return array
     */
    public function update_manual_item(int $courseid, int $itemid, array $payload): array {
        return $this->save_manual_item($courseid, $itemid, $payload);
    }

    /**
     * Move a manual grade item.
     *
     * @param int $courseid Course id.
     * @param int $itemid Grade item id.
     * @param int|null $parentcategoryid Optional new parent category.
     * @param int|null $afteritemid Optional target item to place after.
     * @return array
     */
    public function move_item(int $courseid, int $itemid, ?int $parentcategoryid = null, ?int $afteritemid = null): array {
        $coursecontext = $this->course_context($courseid);
        external_api::validate_context($coursecontext);
        \require_capability('moodle/grade:manage', $coursecontext);

        $gradeitem = $this->manual_grade_item($courseid, $itemid);
        if ($parentcategoryid !== null) {
            $gradeitem->set_parent($parentcategoryid, false);
        }
        if ($afteritemid !== null) {
            $afteritem = $this->grade_item($courseid, $afteritemid);
            $gradeitem->move_after_sortorder((int)$afteritem->sortorder);
        }

        return $this->grade_item_result($gradeitem);
    }

    /**
     * Delete manual grade items.
     *
     * @param int $courseid Course id.
     * @param array $itemids Grade item ids.
     * @return array
     */
    public function delete_items(int $courseid, array $itemids): array {
        $coursecontext = $this->course_context($courseid);
        external_api::validate_context($coursecontext);
        \require_capability('moodle/grade:manage', $coursecontext);

        $itemids = $this->normalize_ids($itemids);
        foreach ($itemids as $itemid) {
            $gradeitem = $this->manual_grade_item($courseid, $itemid);
            $gradeitem->delete('gradebook');
        }

        return [
            'deleted' => true,
            'itemids' => $itemids,
        ];
    }

    /**
     * Update a grade category.
     *
     * @param int $courseid Course id.
     * @param int $categoryid Category id.
     * @param array $payload Category payload.
     * @return array
     */
    public function update_category(int $courseid, int $categoryid, array $payload): array {
        require_once($this->dirroot() . '/grade/edit/tree/lib.php');

        $coursecontext = $this->course_context($courseid);
        external_api::validate_context($coursecontext);
        \require_capability('moodle/grade:manage', $coursecontext);

        $gradecategory = $this->grade_category($courseid, $categoryid);
        $data = $this->build_grade_category_payload($payload);
        \grade_edit_tree::update_gradecategory($gradecategory, $data);

        return $this->grade_category_result($gradecategory);
    }

    /**
     * Move a grade category.
     *
     * @param int $courseid Course id.
     * @param int $categoryid Category id.
     * @param int|null $parentcategoryid Optional new parent category.
     * @param int|null $aftercategoryid Optional target category to place after.
     * @param int|null $afteritemid Optional target grade item to place after.
     * @return array
     */
    public function move_category(
        int $courseid,
        int $categoryid,
        ?int $parentcategoryid = null,
        ?int $aftercategoryid = null,
        ?int $afteritemid = null
    ): array {
        $coursecontext = $this->course_context($courseid);
        external_api::validate_context($coursecontext);
        \require_capability('moodle/grade:manage', $coursecontext);

        $gradecategory = $this->grade_category($courseid, $categoryid);
        if ($gradecategory->is_course_category()) {
            throw new \moodle_exception('cannothaveparentcate');
        }

        if ($parentcategoryid !== null) {
            $gradecategory->set_parent($parentcategoryid, 'gradebook');
        }

        if ($aftercategoryid !== null) {
            $aftercategory = $this->grade_category($courseid, $aftercategoryid);
            $gradecategory->move_after_sortorder((int)$aftercategory->get_sortorder());
        } else if ($afteritemid !== null) {
            $afteritem = $this->grade_item($courseid, $afteritemid);
            $gradecategory->move_after_sortorder((int)$afteritem->sortorder);
        }

        return $this->grade_category_result($gradecategory);
    }

    /**
     * Delete grade categories.
     *
     * @param int $courseid Course id.
     * @param array $categoryids Category ids.
     * @return array
     */
    public function delete_categories(int $courseid, array $categoryids): array {
        $coursecontext = $this->course_context($courseid);
        external_api::validate_context($coursecontext);
        \require_capability('moodle/grade:manage', $coursecontext);

        $categoryids = $this->normalize_ids($categoryids);
        foreach ($categoryids as $categoryid) {
            $gradecategory = $this->grade_category($courseid, $categoryid);
            if ($gradecategory->is_course_category()) {
                throw new \moodle_exception('cannothaveparentcate');
            }
            $gradecategory->delete('gradebook');
        }

        return [
            'deleted' => true,
            'categoryids' => $categoryids,
        ];
    }

    /**
     * Create or update a manual grade item using Moodle's own gradebook path.
     *
     * @param int $courseid Course id.
     * @param int $itemid Grade item id, or 0 for create.
     * @param array $payload User payload.
     * @return array
     */
    private function save_manual_item(int $courseid, int $itemid, array $payload): array {
        $coursecontext = $this->course_context($courseid);
        external_api::validate_context($coursecontext);
        \require_capability('moodle/grade:manage', $coursecontext);

        $gradeitem = $itemid > 0
            ? $this->manual_grade_item($courseid, $itemid)
            : new \grade_item(['courseid' => $courseid, 'itemtype' => 'manual'], false);

        $parentcategory = $this->resolve_parent_category($courseid, $payload['parentcategoryid'] ?? null);
        $defaults = method_exists('\grade_category', 'get_default_aggregation_coefficient_values')
            ? \grade_category::get_default_aggregation_coefficient_values($parentcategory->aggregation)
            : ['aggregationcoef' => 0, 'aggregationcoef2' => 0, 'weightoverride' => 0];

        $data = new \stdClass();
        if (array_key_exists('itemname', $payload)) {
            $data->itemname = (string)$payload['itemname'];
        }
        if (array_key_exists('idnumber', $payload)) {
            $data->idnumber = $this->normalize_optional_string((string)$payload['idnumber']);
        }
        if (array_key_exists('gradetype', $payload)) {
            $data->gradetype = $this->grade_type_constant((string)$payload['gradetype']);
        }
        if (array_key_exists('scaleid', $payload)) {
            $data->scaleid = (int)$payload['scaleid'];
        }
        if (array_key_exists('grademax', $payload)) {
            $data->grademax = (float)$payload['grademax'];
        }
        if (array_key_exists('grademin', $payload)) {
            $data->grademin = (float)$payload['grademin'];
        }
        if (array_key_exists('gradepass', $payload)) {
            $data->gradepass = (float)$payload['gradepass'];
        }
        if (array_key_exists('weightoverride', $payload)) {
            $data->weightoverride = (int)!empty($payload['weightoverride']);
        } else {
            $data->weightoverride = $defaults['weightoverride'] ?? 0;
        }
        if (array_key_exists('aggregationcoef', $payload)) {
            $data->aggregationcoef = (float)$payload['aggregationcoef'];
        } else {
            $data->aggregationcoef = $defaults['aggregationcoef'] ?? 0;
        }
        if (array_key_exists('aggregationcoef2', $payload)) {
            $data->aggregationcoef2 = (float)$payload['aggregationcoef2'];
        } else {
            $data->aggregationcoef2 = $defaults['aggregationcoef2'] ?? 0;
        }

        $oldmin = $gradeitem->grademin;
        $oldmax = $gradeitem->grademax;
        \grade_item::set_properties($gradeitem, $data);
        $gradeitem->outcomeid = null;

        if (property_exists($data, 'decimals') && (int)$data->decimals < 0) {
            $gradeitem->decimals = null;
        }

        if (empty($gradeitem->id)) {
            $gradeitem->itemtype = 'manual';
            $gradeitem->insert();
            $gradeitem->set_parent($parentcategory->id, false);
        } else {
            $gradeitem->update();
            if (!empty($payload['rescalegrades'])) {
                $gradeitem->rescale_grades_keep_percentage(
                    $oldmin,
                    $oldmax,
                    $gradeitem->grademin,
                    $gradeitem->grademax,
                    'gradebook',
                );
            }
        }

        $hide = 0;
        if (array_key_exists('hiddenuntil', $payload) && !empty($payload['hiddenuntil'])) {
            $hide = (int)$payload['hiddenuntil'];
        } else if (array_key_exists('hidden', $payload)) {
            $hide = !empty($payload['hidden']) ? 1 : 0;
        }
        if ($gradeitem->can_control_visibility()) {
            $gradeitem->set_hidden($hide, true);
        }

        if (array_key_exists('locktime', $payload)) {
            $gradeitem->set_locktime((int)$payload['locktime']);
        }
        if (array_key_exists('locked', $payload)) {
            $gradeitem->set_locked(!empty($payload['locked']));
        }

        return $this->grade_item_result($gradeitem);
    }

    /**
     * Build the update payload expected by grade_edit_tree::update_gradecategory().
     *
     * @param array $payload Raw user payload.
     * @return \stdClass
     */
    private function build_grade_category_payload(array $payload): \stdClass {
        $data = new \stdClass();

        if (array_key_exists('fullname', $payload)) {
            $data->fullname = (string)$payload['fullname'];
        } else if (array_key_exists('name', $payload)) {
            $data->fullname = (string)$payload['name'];
        }
        if (array_key_exists('aggregation', $payload)) {
            $data->aggregation = (int)$payload['aggregation'];
        }
        if (array_key_exists('aggregateonlygraded', $payload)) {
            $data->aggregateonlygraded = !empty($payload['aggregateonlygraded']) ? 1 : 0;
        }
        if (array_key_exists('aggregateoutcomes', $payload)) {
            $data->aggregateoutcomes = !empty($payload['aggregateoutcomes']) ? 1 : 0;
        }
        if (array_key_exists('droplow', $payload)) {
            $data->droplow = (int)$payload['droplow'];
        }
        if (array_key_exists('parentcategoryid', $payload)) {
            $data->parentcategory = (int)$payload['parentcategoryid'];
        }

        $prefixmap = [
            'itemname' => 'grade_item_itemname',
            'iteminfo' => 'grade_item_iteminfo',
            'idnumber' => 'grade_item_idnumber',
            'gradetype' => 'grade_item_gradetype',
            'grademax' => 'grade_item_grademax',
            'grademin' => 'grade_item_grademin',
            'gradepass' => 'grade_item_gradepass',
            'display' => 'grade_item_display',
            'decimals' => 'grade_item_decimals',
            'hiddenuntil' => 'grade_item_hiddenuntil',
            'locktime' => 'grade_item_locktime',
            'weightoverride' => 'grade_item_weightoverride',
            'aggregationcoef2' => 'grade_item_aggregationcoef2',
        ];
        foreach ($prefixmap as $source => $target) {
            if (!array_key_exists($source, $payload)) {
                continue;
            }

            $value = $payload[$source];
            if ($source === 'gradetype') {
                $value = $this->grade_type_constant((string)$value);
            } else if ($source === 'idnumber') {
                $value = $this->normalize_optional_string((string)$value);
            }
            $data->{$target} = $value;
        }

        return $data;
    }

    /**
     * Resolve the effective parent category for an item mutation.
     *
     * @param int $courseid Course id.
     * @param mixed $parentcategoryid Optional parent id.
     * @return \grade_category
     */
    private function resolve_parent_category(int $courseid, mixed $parentcategoryid): \grade_category {
        if ($parentcategoryid === null || (int)$parentcategoryid <= 0) {
            return \grade_category::fetch_course_category($courseid);
        }

        return $this->grade_category($courseid, (int)$parentcategoryid);
    }

    /**
     * Fetch and validate a grade category.
     *
     * @param int $courseid Course id.
     * @param int $categoryid Category id.
     * @return \grade_category
     */
    private function grade_category(int $courseid, int $categoryid): \grade_category {
        $category = \grade_category::fetch(['id' => $categoryid, 'courseid' => $courseid]);
        if (!$category) {
            throw new \moodle_exception('invalidcourseid');
        }

        return $category;
    }

    /**
     * Fetch any grade item in the course.
     *
     * @param int $courseid Course id.
     * @param int $itemid Grade item id.
     * @return \grade_item
     */
    private function grade_item(int $courseid, int $itemid): \grade_item {
        $item = \grade_item::fetch(['id' => $itemid, 'courseid' => $courseid]);
        if (!$item) {
            throw new \moodle_exception('invalidcourseid');
        }

        return $item;
    }

    /**
     * Fetch a manual grade item in the course.
     *
     * @param int $courseid Course id.
     * @param int $itemid Grade item id.
     * @return \grade_item
     */
    private function manual_grade_item(int $courseid, int $itemid): \grade_item {
        $item = $this->grade_item($courseid, $itemid);
        if ($item->itemtype !== 'manual' || $item->is_course_item() || $item->is_category_item()) {
            throw new \moodle_exception('invalidparameter');
        }

        return $item;
    }

    /**
     * Build a course context.
     *
     * @param int $courseid Course id.
     * @return context_course
     */
    private function course_context(int $courseid): context_course {
        return context_course::instance($courseid, MUST_EXIST);
    }

    /**
     * Convert a human-oriented grade type label to a Moodle constant.
     *
     * @param string $type Requested type.
     * @return int
     */
    private function grade_type_constant(string $type): int {
        return match (strtolower(trim($type))) {
            'value' => GRADE_TYPE_VALUE,
            'scale' => GRADE_TYPE_SCALE,
            'text' => GRADE_TYPE_TEXT,
            default => throw new \moodle_exception('invalidparameter'),
        };
    }

    /**
     * Return structured grade-item metadata.
     *
     * @param \grade_item $gradeitem Grade item.
     * @return array
     */
    private function grade_item_result(\grade_item $gradeitem): array {
        $parentcategory = $gradeitem->get_parent_category();

        return [
            'itemid' => (int)$gradeitem->id,
            'courseid' => (int)$gradeitem->courseid,
            'itemtype' => (string)$gradeitem->itemtype,
            'itemname' => (string)$gradeitem->itemname,
            'parentcategoryid' => $parentcategory ? (int)$parentcategory->id : null,
            'sortorder' => (int)$gradeitem->sortorder,
        ];
    }

    /**
     * Return structured grade-category metadata.
     *
     * @param \grade_category $gradecategory Grade category.
     * @return array
     */
    private function grade_category_result(\grade_category $gradecategory): array {
        return [
            'categoryid' => (int)$gradecategory->id,
            'courseid' => (int)$gradecategory->courseid,
            'parentcategoryid' => $gradecategory->parent !== null ? (int)$gradecategory->parent : null,
            'name' => (string)$gradecategory->fullname,
            'sortorder' => (int)$gradecategory->get_sortorder(),
        ];
    }

    /**
     * Normalize a possibly empty string.
     *
     * @param string|null $value Raw string.
     * @return string|null
     */
    private function normalize_optional_string(?string $value): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        return $value === '' ? null : $value;
    }

    /**
     * Normalize an id list to unique positive integers.
     *
     * @param array $ids Raw ids.
     * @return array
     */
    private function normalize_ids(array $ids): array {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        return array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
    }

    /**
     * Return Moodle dirroot.
     *
     * @return string
     */
    private function dirroot(): string {
        global $CFG;
        return $CFG->dirroot;
    }
}
