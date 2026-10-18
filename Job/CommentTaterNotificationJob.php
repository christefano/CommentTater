<?php

namespace Kanboard\Plugin\CommentTater\Job;

use Kanboard\Job\NotificationJob;

/**
 * Core's notification job, except that it skips the per-user notifications for the task events a CommentTater save
 * causes (the update, assignee and move events). The comment's own email already lists every change, so one save
 * would otherwise send up to four emails to each person. The project notifications (activity stream, webhooks,
 * project integrations) still go out. With a queue worker the record is gone by the time the job runs, so every
 * notification is sent, as without the plugin.
 */
class CommentTaterNotificationJob extends NotificationJob
{
    public function execute(array $eventData, $eventName)
    {
        if (empty($eventData['mention']) && isset($eventData['task']['id']) && $this->commentTaterChange->isQuiet($eventData['task']['id'], $eventName, $eventData)) {
            $this->projectNotificationModel->sendNotifications($eventData['task']['project_id'], $eventName, $eventData);

            return;
        }

        parent::execute($eventData, $eventName);
    }
}
