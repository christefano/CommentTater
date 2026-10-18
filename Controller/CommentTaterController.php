<?php

namespace Kanboard\Plugin\CommentTater\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Security\Role;

/**
 * Saves a comment together with the task changes made on the comment form. Replaces core's
 * CommentController::save() while the plugin is on (the overridden form posts here).
 */
class CommentTaterController extends BaseController
{
    public function save()
    {
        $task = $this->getTask();
        $values = $this->request->getValues();

        $values['task_id'] = $task['id'];
        $values['user_id'] = $this->userSession->getId();
        $values['visibility'] = isset($values['visibility']) && is_scalar($values['visibility']) && $values['visibility'] !== '' ? $values['visibility'] : Role::APP_USER;

        // An edited field the user may not change throws here, before anything is written.
        $plan = $this->commentTaterChange->plan($task, $values);
        $hasChanges = ! empty($plan['lines']);
        $text = isset($values['comment']) && is_scalar($values['comment']) ? trim((string) $values['comment']) : '';

        if (! empty($plan['errors'])) {
            $this->show($task, $values, $plan['errors']);
            return;
        }

        if ($text === '' && $hasChanges && ! $this->commentTaterSettings->allowEmpty()) {
            $this->show($task, $values, array('comment' => array(t('Comment is required'))));
            return;
        }

        $comment = array(
            'task_id' => $task['id'],
            'user_id' => $values['user_id'],
            'visibility' => $values['visibility'],
            'comment' => $this->commentTaterChange->commentText($text, $plan['lines']),
        );

        list($valid, $errors) = $this->commentValidator->validateCreation($comment);

        if (! $valid) {
            $this->show($task, $values, $errors);
            return;
        }

        $moveError = '';
        $commentId = $this->commentTaterChange->apply($task, $plan, $comment, $moveError);

        if ($commentId === false) {
            $this->show($task, $values, array('comment' => array(t('Unable to create your comment.'))));
            return;
        }

        // The comment and the other changes are saved, so go to the task. The comment modal shows a flash message only on the next page.
        if ($moveError !== '') {
            $this->flash->failure(t('Comment saved. The task could not be moved.'));
        } else {
            $this->flash->success(t('Comment added successfully.'));
        }

        $this->response->redirect($this->helper->url->to('TaskViewController', 'show', array('task_id' => $task['id']), 'comments'), true);
    }

    /**
     * The comment form again, with what the user typed and the errors.
     */
    private function show(array $task, array $values, array $errors)
    {
        $values['project_id'] = $task['project_id'];

        $this->response->html($this->helper->layout->task('comment/create', array(
            'values' => $values,
            'errors' => $errors,
            'task' => $this->taskFinderModel->getDetails($task['id']),
            // Core's task layout needs the project. Without it a failed post that is not ajax ends in a blank page.
            'project' => $this->projectModel->getById($task['project_id']),
        )));
    }
}
