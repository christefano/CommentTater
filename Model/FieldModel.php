<?php

namespace Kanboard\Plugin\CommentTater\Model;

use Kanboard\Core\Base;

/**
 * The eleven fields, which of them the current user may change on a task, and the choices for each select.
 *
 * Rule: if a user can see a field and change its value on a task, the user can change it in the comment form,
 * and nobody else can. Each check below mirrors the check core makes where the user edits the task
 * (TaskModificationController) or moves its card (BoardAjaxController, TaskMovePositionController).
 */
class FieldModel extends Base
{
    private $offeredCache = array();
    private $optionsCache = array();
    private $lists = array();

    /**
     * name => array(label, kind). The name is the form field and the tasks column. "column" is not a tasks
     * column: it carries "swimlane_id:column_id" because core stores the two separately.
     * Kinds: select, number, hours, date, text.
     */
    public static function definitions()
    {
        return array(
            'color_id'       => array('Color', 'select'),
            'owner_id'       => array('Assignee', 'select'),
            'category_id'    => array('Category', 'select'),
            'column'         => array('Column', 'select'),
            'priority'       => array('Priority', 'number'),
            'date_due'       => array('Due Date', 'date'),
            'date_started'   => array('Start Date', 'date'),
            'time_estimated' => array('Original estimate', 'hours'),
            'time_spent'     => array('Time spent', 'hours'),
            'score'          => array('Complexity', 'number'),
            'reference'      => array('Reference', 'text'),
        );
    }

    /**
     * The names this user may change on this task and the admin has left on, in form order.
     */
    public function offered(array $task)
    {
        $key = $this->cacheKey($task);

        if (! isset($this->offeredCache[$key])) {
            $this->offeredCache[$key] = $this->findOffered($task);
        }

        return $this->offeredCache[$key];
    }

    /**
     * One form asks for the same answers two or three times (the form block, the textRequired() check, the plan),
     * so offered() and options() keep what they found for the rest of the request. The key holds everything the
     * answers depend on in a task.
     */
    private function cacheKey(array $task)
    {
        return implode('|', array($task['id'], $task['project_id'], $task['swimlane_id'], $task['column_id'], $task['owner_id'], $task['is_active']));
    }

    private function findOffered(array $task)
    {
        $offered = array();

        if (! $this->helper->projectRole->canUpdateTask($task)) {
            return $offered;
        }

        foreach (array_keys(self::definitions()) as $name) {
            if (! $this->commentTaterSettings->fieldEnabled($name)) {
                continue;
            }

            if ($name === 'owner_id' && ! $this->helper->projectRole->canChangeAssignee($task)) {
                continue;
            }

            if ($name === 'category_id' && count($this->options($task, 'category_id')) < 2) {
                continue;
            }

            // The board lets a user drag a card only where isSortableColumn() says so (a custom role's move restrictions).
            if ($name === 'column' && ($task['is_active'] != 1
                || ! $this->helper->projectRole->isSortableColumn($task['project_id'], $task['column_id'], $task['owner_id'])
                || count($this->options($task, 'column')) < 2)) {
                continue;
            }

            $offered[] = $name;
        }

        return $offered;
    }

    /**
     * id => label for a select field, limited to what the user may choose.
     */
    public function options(array $task, $name)
    {
        $key = $this->cacheKey($task).'#'.$name;

        if (! isset($this->optionsCache[$key])) {
            $this->optionsCache[$key] = $this->findOptions($task, $name);
        }

        return $this->optionsCache[$key];
    }

    /**
     * The choices the form shows for Assignee, Color and Priority. When the task's stored value is not one of the
     * choices (an assignee who is a viewer or has left the project, a retired color, a priority outside the
     * project's range), it is listed first so the form shows it and posts it back unchanged. Without it the browser
     * would post the first choice and a plain comment would change the task. The stored value is only kept, never
     * offered for anyone else.
     */
    public function formOptions(array $task, $name)
    {
        $options = $this->options($task, $name);
        $stored = $task[$name];

        if (! in_array($name, array('owner_id', 'color_id', 'priority'), true) || array_key_exists($stored, $options)) {
            return $options;
        }

        if ($name === 'owner_id') {
            $user = $this->userModel->getById($stored);
            $label = t('%s (can\'t be assigned)', empty($user) ? '#'.$stored : $this->helper->user->getFullname($user));
        } else {
            $label = t('%s (not available)', $stored);
        }

        return array($stored => $label) + $options;
    }

    private function findOptions(array $task, $name)
    {
        switch ($name) {
            case 'color_id':
                return $this->colorModel->getList();

            case 'owner_id':
                return $this->projectUserRoleModel->getAssignableUsersList($task['project_id']);

            case 'category_id':
                return $this->categoryModel->getList($task['project_id']);

            case 'priority':
                $project = $this->projectModel->getById($task['project_id']);
                $range = range((int) $project['priority_start'], (int) $project['priority_end']);

                return array_combine($range, $range);

            case 'column':
                return $this->columnOptions($task);
        }

        return array();
    }

    /**
     * "swimlane_id:column_id" => "Swimlane › Column". The swimlane is left out of the label when the project
     * has one. A destination the user may not move the task to (core's column move restrictions) is left out.
     */
    private function columnOptions(array $task)
    {
        $lists = $this->lists($task['project_id']);
        $swimlanes = $lists['active'];
        $columns = $lists['columns'];
        $options = array();

        foreach ($swimlanes as $swimlaneId => $swimlaneName) {
            foreach ($columns as $columnId => $columnTitle) {
                if (! $this->helper->projectRole->canMoveTask($task['project_id'], $task['column_id'], $columnId)) {
                    continue;
                }

                $options[$swimlaneId.':'.$columnId] = count($swimlanes) > 1 ? $swimlaneName.' › '.$columnTitle : $columnTitle;
            }
        }

        // The task's own place is always listed so the select can show it, even when swimlanes are inactive.
        $current = $task['swimlane_id'].':'.$task['column_id'];

        if (! isset($options[$current])) {
            $options = array($current => $this->columnLabel($task['project_id'], $task['swimlane_id'], $task['column_id'])) + $options;
        }

        return $options;
    }

    /**
     * The swimlane and column lists of a project, read once per request.
     */
    private function lists($projectId)
    {
        if (! isset($this->lists[$projectId])) {
            $this->lists[$projectId] = array(
                'all' => $this->swimlaneModel->getList($projectId),
                'active' => $this->swimlaneModel->getList($projectId, false, true),
                'columns' => $this->columnModel->getList($projectId),
            );
        }

        return $this->lists[$projectId];
    }

    /**
     * The label of a place on the board, for the change list.
     */
    public function columnLabel($projectId, $swimlaneId, $columnId)
    {
        $lists = $this->lists($projectId);
        $swimlanes = $lists['all'];
        $columns = $lists['columns'];
        $column = isset($columns[$columnId]) ? $columns[$columnId] : '#'.$columnId;

        if (count($lists['active']) > 1 && isset($swimlanes[$swimlaneId])) {
            return $swimlanes[$swimlaneId].' › '.$column;
        }

        return $column;
    }

    /**
     * What the form shows for the task right now, by field name.
     */
    public function currentValues(array $task)
    {
        $values = array();

        foreach (array_keys(self::definitions()) as $name) {
            $values[$name] = $name === 'column' ? $task['swimlane_id'].':'.$task['column_id'] : $task[$name];
        }

        return $values;
    }
}
