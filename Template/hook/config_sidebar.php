<li <?= $this->app->checkMenuSelection('CommentTaterSettingsController', '', 'CommentTater') ?>>
    <?= $this->url->link('CommentTater', 'CommentTaterSettingsController', 'index', array('plugin' => 'CommentTater')) ?>
</li>
