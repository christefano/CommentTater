<?php
use Kanboard\Plugin\CommentTater\Model\FieldModel;
use Kanboard\Plugin\CommentTater\Model\SettingsModel;
?>
<div class="page-header">
    <h2><?= $this->commentTater->iconFor($this->commentTater->settings()->iconKey()) ?> CommentTater</h2>
</div>

<form class="commenttater-settings" method="post" action="<?= $this->url->href('CommentTaterSettingsController', 'save', array('plugin' => 'CommentTater')) ?>" autocomplete="off">
    <?= $this->form->csrf() ?>

    <fieldset>
        <legend><?= t('Comment form options') ?></legend>
        <label title="<?= t('Show or hide the icon.') ?>">
            <input type="checkbox" name="show_icon" value="1" <?= $this->commentTater->settings()->iconsShown() ? 'checked' : '' ?>>
            <?= t('Show the icon') ?>
        </label>
        <p class="form-help"><?= t('Shows the CommentTater icon next to Update task on comment forms.') ?></p>
        <div class="commenttater-choices">
            <?php foreach (SettingsModel::icons() as $key => $icon): ?>
                <label title="<?= t('Use this icon.') ?>">
                    <input type="radio" name="icon" value="<?= $key ?>" <?= $this->commentTater->settings()->iconKey() === $key ? 'checked' : '' ?>>
                    <?= $this->commentTater->iconFor($key) ?>
                    <?= t($icon[1]) ?>
                </label>
            <?php endforeach ?>
        </div>
        <fieldset>
            <legend><?= t('Where the list of changes goes') ?></legend>
            <label title="<?= t('Put the list above the text.') ?>">
                <input type="radio" name="position" value="top" <?= $this->commentTater->settings()->changesOnTop() ? 'checked' : '' ?>>
                <?= t('Above the text') ?>
            </label>
            <label title="<?= t('Put the list below the text.') ?>">
                <input type="radio" name="position" value="bottom" <?= $this->commentTater->settings()->changesOnTop() ? '' : 'checked' ?>>
                <?= t('Below the text') ?>
            </label>
            <p class="form-help"><?= t('The list is saved into the comment itself. An old comment keeps showing the list where it was when the comment was saved.') ?></p>
        </fieldset>
        <label title="<?= t('Save a comment that holds only the changes.') ?>">
            <input type="checkbox" name="allow_empty" value="1" <?= $this->commentTater->settings()->allowEmpty() ? 'checked' : '' ?>>
            <?= t('Allow a comment with changes and no text') ?>
        </label>
        <label title="<?= t('Show the field list when the form opens.') ?>">
            <input type="checkbox" name="expanded" value="1" <?= $this->commentTater->settings()->expanded() ? 'checked' : '' ?>>
            <?= t('Open the field list by default') ?>
        </label>
    </fieldset>

    <fieldset>
        <legend><?= t('Comment form fields') ?></legend>
        <p class="form-help"><?= t('Enabled fields appear only for users who already have permission to change the same field on the task itself. Fields disabled below are disabled for everyone.') ?></p>
        <?php foreach (FieldModel::definitions() as $name => $definition): ?>
            <label title="<?= t('Offer this field.') ?>">
                <input type="checkbox" name="field_<?= $name ?>" value="1" <?= $this->commentTater->settings()->fieldEnabled($name) ? 'checked' : '' ?>>
                <?= t($definition[0]) ?>
            </label>
        <?php endforeach ?>
    </fieldset>

    <button type="submit" class="btn btn-blue" title="<?= t('Save these settings.') ?>"><?= t('Save') ?></button>
</form>
