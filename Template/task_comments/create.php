<?php use Kanboard\Core\Security\Role; ?>
<?php /* A copy of core's task_comments/create (Kanboard 1.2.54), the comment form on the task page, with the CommentTater fields added and the form posting to the plugin. Compare it with core's after a Kanboard upgrade. */ ?>
<form method="post" action="<?= $this->url->href('CommentTaterController', 'save', array('plugin' => 'CommentTater', 'task_id' => $task['id'])) ?>" autocomplete="off">
    <?= $this->form->csrf() ?>
    <?= $this->form->hidden('task_id', $values) ?>
    <?= $this->form->hidden('user_id', $values) ?>

    <?= $this->form->textEditor('comment', $values, $errors, array('required' => $this->commentTater->textRequired($task), 'aria-label' => t('New comment'))) ?>

    <?= $this->commentTater->fields($task, $values, $errors) ?>

    <?php
    $formName = 'visibility';
    $visibilityOptions['app-user'] = t('Standard users');
    $attribute[] = ('hidden');
    ?>

    <?php if ($this->user->getRole() !== Role::APP_USER) {
        echo $this->form->label(t('Visibility:'), $formName);
        $attribute = [];
        $visibilityOptions['app-user'] = t('Standard users');
        $visibilityOptions['app-manager'] = t('Application managers or more');
    }
    ?>

    <?php if ($this->user->getRole() === Role::APP_ADMIN) {
        $visibilityOptions['app-admin'] = t('Administrators');
    }
    ?>

    <?= $this->form->select($formName, $visibilityOptions, array(), array(), $attribute) ?>
    <?= $this->modal->submitButtons() ?>
</form>
