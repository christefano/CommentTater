<?php

namespace Kanboard\Plugin\CommentTater\Model;

use Kanboard\Core\Base;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Security\Role;
use Kanboard\Model\CommentModel;
use Kanboard\Model\TaskModel;

/**
 * Turns a posted comment form into a list of task changes, applies them, and writes them up for the comment.
 */
class ChangeModel extends Base
{
    const ARROW = '→';

    /**
     * Task events this save causes whose per-user notifications are skipped, because the comment's email already
     * carries the change list. "taskId:eventName" => list of destinations still to skip (null: any). A record exists
     * only while the step that causes it runs.
     */
    private $quiet = array();

    /**
     * Work out what the form changes. Nothing is written.
     *
     * A field counts as edited only when its posted value differs from the value the form showed when it opened
     * (posted back as ct_orig[name]), so a field the user left alone never overwrites a change someone else made
     * in the meantime. The old value in the list is the value stored now.
     *
     * @param  array $task    The task as stored now
     * @param  array $values  The posted form values
     * @return array          updates (tasks columns), move (array(swimlane_id, column_id) or null), lines (Markdown), errors (field => messages)
     * @throws AccessForbiddenException  An edited field the user may not change or the admin has turned off
     */
    public function plan(array $task, array $values)
    {
        $orig = (isset($values['ct_orig']) && is_array($values['ct_orig'])) ? $values['ct_orig'] : array();
        $offered = $this->commentTaterFields->offered($task);
        $current = $this->commentTaterFields->currentValues($task);

        $plan = array('updates' => array(), 'move' => null, 'move_line' => null, 'lines' => array(), 'errors' => array());
        $check = array('id' => $task['id'], 'project_id' => $task['project_id'], 'title' => $task['title']);
        $edited = array();

        foreach (FieldModel::definitions() as $name => $definition) {
            if (! array_key_exists($name, $values)) {
                continue;
            }

            $new = $this->clean($values[$name]);

            if (isset($orig[$name]) && $this->clean($orig[$name]) === $new) {
                continue;
            }

            if (! in_array($name, $offered, true)) {
                throw new AccessForbiddenException(t('You are not allowed to change this field.'));
            }

            $edited[$name] = $new;

            if ($name !== 'column') {
                $check[$name] = $new;
            }
        }

        if (empty($edited)) {
            return $plan;
        }

        list(, $errors) = $this->taskValidator->validateModification($check);
        $plan['errors'] = $errors;

        foreach ($edited as $name => $new) {
            if (isset($plan['errors'][$name])) {
                continue;
            }

            $kind = FieldModel::definitions()[$name][1];
            $old = $current[$name];

            if ($kind === 'select') {
                $options = $this->commentTaterFields->options($task, $name);

                // The stored value always counts, even when it is not an option now (an assignee who left the project).
                if ((string) $old === (string) $new) {
                    continue;
                }

                if (! array_key_exists($new, $options)) {
                    $plan['errors'][$name] = array(t('This value is not allowed.'));
                    continue;
                }

                if ($name === 'column') {
                    list($swimlaneId, $columnId) = array_map('intval', explode(':', $new));
                    $plan['move'] = array($swimlaneId, $columnId);
                } else {
                    $plan['updates'][$name] = $name === 'color_id' ? (string) $new : (int) $new;
                }
            } elseif ($kind === 'number') {
                if ($name === 'priority' && (int) $old !== (int) $new && ! array_key_exists($new, $this->commentTaterFields->options($task, 'priority'))) {
                    $plan['errors'][$name] = array(t('This value is not allowed.'));
                    continue;
                }

                // Core clears a blank Complexity to 0 on the task form, so a blank here does too.
                if ($name === 'score' && $new === '') {
                    $new = '0';
                }

                if ($new === '' || (int) $old === (int) $new) {
                    continue;
                }

                $plan['updates'][$name] = (int) $new;
            } elseif ($kind === 'hours') {
                $new = $new === '' ? 0.0 : (float) $new;

                if ($new < 0) {
                    $plan['errors'][$name] = array(t('This value must not be negative.'));
                    continue;
                }

                if (abs((float) $old - $new) < 0.000001) {
                    continue;
                }

                $plan['updates'][$name] = $new;
            } elseif ($kind === 'date') {
                $typed = $new;
                $new = $new === '' ? 0 : $this->dateParser->getTimestamp($new);

                // A date typed with no time means midnight. Core would add the time of day it was typed.
                if ($new > 0 && strpos($typed, ':') === false) {
                    $new = strtotime(date('Y-m-d', $new));
                }

                if ((int) $old === $new) {
                    continue;
                }

                $plan['updates'][$name] = $new;
            } else {
                if ((string) $old === $new) {
                    continue;
                }

                $plan['updates'][$name] = $new;
            }
        }

        $start = array_key_exists('date_started', $plan['updates']) ? $plan['updates']['date_started'] : (int) $task['date_started'];
        $due = array_key_exists('date_due', $plan['updates']) ? $plan['updates']['date_due'] : (int) $task['date_due'];

        if (empty($plan['errors']) && $start > 0 && $due > 0 && $start > $due) {
            $plan['errors']['date_started'] = array(t('The start date is greater than the end date'));
        }

        if (! empty($plan['errors'])) {
            return $plan;
        }

        foreach (FieldModel::definitions() as $name => $definition) {
            if ($name === 'column') {
                if ($plan['move'] !== null) {
                    $plan['move_line'] = $this->line($name, $current[$name], $plan['move'][0].':'.$plan['move'][1], $task);
                    $plan['lines'][] = $plan['move_line'];
                }
            } elseif (array_key_exists($name, $plan['updates'])) {
                $plan['lines'][] = $this->line($name, $current[$name], $plan['updates'][$name], $task);
            }
        }

        return $plan;
    }

    /**
     * The comment text: the user's words and the change list, in the order the admin chose.
     */
    public function commentText($text, array $lines)
    {
        $text = trim((string) $text);

        if (empty($lines)) {
            return $text;
        }

        $list = implode("\n", $lines);

        if ($text === '') {
            return $list;
        }

        return $this->commentTaterSettings->changesOnTop() ? $list."\n\n".$text : $text."\n\n".$list;
    }

    /**
     * Apply the plan and save the comment. Returns the new comment id, or false with nothing left changed.
     *
     * The field updates and the comment row share one transaction, and nothing else runs inside it: no event, no
     * email. Core's own move code commits by itself, so a move cannot sit inside the transaction either. After the
     * commit the task events and the move go out, and the comment event goes last. If the move fails, the saved
     * comment loses its Column line (a direct row change that raises no event), $error is set, and the id still comes
     * back (the comment and the other changes are saved). A comment left with no words is removed and sends no email.
     *
     * Emails: the comment's email already lists every change, so the per-user notifications for the task events this
     * save causes are skipped (see CommentTaterNotificationJob). The activity stream, webhooks and automatic actions
     * for those events still run, and the stream lists the comment after the task entries.
     *
     * @param  string $error  Set to a non-empty value when the move failed
     */
    public function apply(array $task, array $plan, array $comment, &$error = '')
    {
        $error = '';
        $commentId = false;
        $this->db->startTransaction();

        try {
            $ok = true;

            if (! empty($plan['updates'])) {
                $ok = $this->taskModificationModel->update($plan['updates'] + array('id' => $task['id']), false) !== false;
            }

            if ($ok) {
                // The same insert as core's CommentModel::create() (Kanboard 1.2.54), without its event push.
                // Check it again after a Kanboard upgrade.
                $values = $this->clampVisibility($comment);
                $values['date_creation'] = time();
                $values['date_modification'] = time();
                $commentId = $this->db->table(CommentModel::TABLE)->persist($values);
                $ok = $commentId !== false;
            }
        } catch (\Throwable $e) {
            $this->logger->error('CommentTater: '.$e->getMessage());
            $ok = false;
        }

        if (! $ok) {
            $this->db->cancelTransaction();

            return false;
        }

        $this->db->closeTransaction();
        $this->quiet = array();

        // The comment and the field changes are saved. A listener that fails now must not undo them or stop the rest.
        // The task events and the move go first and the comment event last, so the comment's email always matches the
        // comment as stored, even when the move fails and its Column line has to go.
        $this->guarded(function () use ($task, $plan) {
            $this->expectQuietEvents($task, $this->updateEvents($task, $plan['updates']));
            $this->queueUpdateEvents($task, $plan['updates']);
        });
        $this->quiet = array();

        if ($plan['move'] !== null) {
            $moved = false;
            $this->guarded(function () use ($task, $plan, &$moved) {
                // The save can take seconds. Move the card only if the task is still open and still in this project.
                $now = $this->taskFinderModel->getById($task['id']);

                if (! empty($now) && (int) $now['project_id'] === (int) $task['project_id'] && (int) $now['is_active'] === 1) {
                    $this->expectQuietMove($task, $plan['move']);
                    $moved = $this->taskPositionModel->moveBottom($task['project_id'], $task['id'], $plan['move'][0], $plan['move'][1]);
                }
            });
            $this->quiet = array();

            if (! $moved) {
                // The caller only tests whether $error is empty; it shows its own translated message.
                $error = 'move_failed';
                $this->guarded(function () use ($commentId, $comment, $plan) {
                    $this->dropMoveLine($commentId, $comment['comment'], $plan);
                });
            }
        }

        if ($this->commentModel->getById($commentId)) {
            $this->guarded(function () use ($commentId) {
                $this->queueManager->push($this->commentEventJob->withParams($commentId, CommentModel::EVENT_CREATE));
            });
        }

        return $commentId;
    }

    private function guarded(callable $step)
    {
        try {
            $step();
        } catch (\Throwable $e) {
            $this->logger->error('CommentTater: '.$e->getMessage());
        }
    }

    /**
     * Whether the notification for this task event is one this save caused (and so is skipped). Each recorded event
     * is skipped once. A move event counts only when it goes where this save sends the card, so a move made by
     * someone else's listener is not skipped.
     *
     * @param  array $event  The event data (core's move events carry dst_column_id and swimlane_id in it)
     */
    public function isQuiet($taskId, $eventName, array $event = array())
    {
        $key = $taskId.':'.$eventName;

        if (empty($this->quiet[$key])) {
            return false;
        }

        foreach ($this->quiet[$key] as $i => $destination) {
            if ($destination === null || (isset($event[$destination[0]]) && (int) $event[$destination[0]] === $destination[1])) {
                unset($this->quiet[$key][$i]);

                return true;
            }
        }

        return false;
    }

    /**
     * Record the update or assignee events this save is about to cause.
     */
    private function expectQuietEvents(array $task, array $events)
    {
        foreach (array_intersect($events, array(TaskModel::EVENT_UPDATE, TaskModel::EVENT_ASSIGNEE_CHANGE)) as $event) {
            $this->quiet[$task['id'].':'.$event][] = null;
        }
    }

    /**
     * Record the move events this save is about to cause, with the place the card is sent to.
     */
    private function expectQuietMove(array $task, array $move)
    {
        if ($task['swimlane_id'] != $move[0]) {
            $this->quiet[$task['id'].':'.TaskModel::EVENT_MOVE_SWIMLANE][] = array('swimlane_id', (int) $move[0]);
        }

        if ($task['column_id'] != $move[1]) {
            $this->quiet[$task['id'].':'.TaskModel::EVENT_MOVE_COLUMN][] = array('dst_column_id', (int) $move[1]);
        }
    }

    /**
     * Core's visibility rule for a new comment (CommentModel::clampVisibility(), protected there).
     */
    private function clampVisibility(array $values)
    {
        if (! $this->userSession->isLogged()) {
            return $values;
        }

        $visibility = isset($values['visibility']) ? $values['visibility'] : Role::APP_USER;
        $userRole = $this->userSession->getRole();

        if ($userRole === Role::APP_MANAGER && $visibility === Role::APP_ADMIN) {
            $values['visibility'] = Role::APP_MANAGER;
        }

        if ($userRole === Role::APP_USER && $visibility !== Role::APP_USER) {
            $values['visibility'] = Role::APP_USER;
        }

        return $values;
    }

    /**
     * Take the Column line out of a saved comment whose move failed, so the comment does not claim a move that did
     * not happen. A comment left with no words at all is removed. Both are direct row changes, so no comment event
     * and no email: the comment event has not gone out yet.
     */
    private function dropMoveLine($commentId, $text, array $plan)
    {
        $line = $plan['move_line'];
        $text = trim(str_replace(array("\n".$line, $line."\n", $line), '', $text));
        $query = $this->db->table(CommentModel::TABLE)->eq('id', $commentId);

        if ($text === '') {
            $query->remove();
        } else {
            $query->update(array('comment' => $text, 'date_modification' => time()));
        }
    }

    /**
     * The events core's TaskModificationModel fires for these updates: a lone assignee change is an assignee event,
     * any other change an update.
     */
    private function updateEvents(array $task, array $updates)
    {
        if (empty($updates)) {
            return array();
        }

        $others = array_diff_assoc($updates, $task);
        $events = array();

        if (isset($updates['owner_id']) && $task['owner_id'] != $updates['owner_id'] && count($others) === 1) {
            $events[] = TaskModel::EVENT_ASSIGNEE_CHANGE;
        } elseif (! empty($others)) {
            $events[] = TaskModel::EVENT_CREATE_UPDATE;
            $events[] = TaskModel::EVENT_UPDATE;
        }

        return $events;
    }

    private function queueUpdateEvents(array $task, array $updates)
    {
        $events = $this->updateEvents($task, $updates);

        if (! empty($events)) {
            $this->queueManager->push(
                $this->taskEventJob->withParams($task['id'], $events, $updates + array('date_modification' => time()), array(), $task)
            );
        }
    }

    private function clean($value)
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * One Markdown list item: "- Label: old → new (difference)".
     */
    private function line($name, $old, $new, array $task)
    {
        list($label, $kind) = FieldModel::definitions()[$name];

        $text = '- '.t($label).': '.$this->show($name, $kind, $old, $task).' '.self::ARROW.' '.$this->show($name, $kind, $new, $task);
        $difference = $this->difference($kind, $old, $new);

        return $difference === '' ? $text : $text.' ('.$difference.')';
    }

    private function show($name, $kind, $value, array $task)
    {
        switch ($kind) {
            case 'hours':
                return $this->hours((float) $value).'h';

            case 'number':
                return (string) (int) $value;

            case 'date':
                $value = (int) $value;

                if ($value === 0) {
                    return t('none');
                }

                return date(date('H:i', $value) === '00:00' ? 'Y-m-d' : 'Y-m-d H:i', $value);

            case 'text':
                return (string) $value === '' ? t('none') : $this->escape($value);
        }

        if ($name === 'column') {
            list($swimlaneId, $columnId) = array_map('intval', explode(':', $value));

            return $this->escape($this->commentTaterFields->columnLabel($task['project_id'], $swimlaneId, $columnId));
        }

        if ($name === 'color_id') {
            $colors = $this->colorModel->getList();

            return $this->escape(isset($colors[$value]) ? $colors[$value] : $value);
        }

        if ($name === 'owner_id') {
            if ((int) $value === 0) {
                return t('Unassigned');
            }

            $options = $this->commentTaterFields->options($task, 'owner_id');

            if (isset($options[$value])) {
                return $this->escape($options[$value]);
            }

            $user = $this->userModel->getById($value);

            return $this->escape(empty($user) ? '#'.$value : $this->helper->user->getFullname($user));
        }

        // category_id
        if ((int) $value === 0) {
            return t('No category');
        }

        $category = $this->categoryModel->getNameById($value);

        return $this->escape($category === '' ? '#'.$value : $category);
    }

    private function difference($kind, $old, $new)
    {
        if ($kind === 'number') {
            return sprintf('%+d', (int) $new - (int) $old);
        }

        if ($kind === 'hours') {
            $delta = (float) $new - (float) $old;

            return ($delta < 0 ? '-' : '+').$this->hours(abs($delta)).'h';
        }

        if ($kind === 'date' && (int) $old > 0 && (int) $new > 0) {
            $days = (int) round((strtotime(date('Y-m-d', (int) $new)) - strtotime(date('Y-m-d', (int) $old))) / 86400);

            if ($days !== 0) {
                return sprintf('%+d', $days).' '.(abs($days) === 1 ? t('day') : t('days'));
            }
        }

        return '';
    }

    private function hours($value)
    {
        $text = rtrim(rtrim(sprintf('%.2f', $value), '0'), '.');

        return $text === '' ? '0' : $text;
    }

    /**
     * Make a name, category or reference safe to sit in a Markdown list item: no line breaks, nothing that starts
     * Markdown at that spot, and no @name or #number that would notify someone or link a task. HTML needs no
     * escaping here, because Kanboard's Markdown already escapes it. A value that is a whole web address is written
     * as <address>, so it stays a working link.
     */
    private function escape($text)
    {
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $text);

        if (preg_match('~^https?://[^\s<>@]+$~i', $text)) {
            return '<'.$text.'>';
        }

        $text = preg_replace('/([\\\\`*_\[\]()])/', '\\\\$1', $text);

        return str_replace(array('@', '#'), array("@\u{200B}", "#\u{200B}"), $text);
    }
}
