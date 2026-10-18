<?php

namespace Kanboard\Plugin\CommentTater;

use Kanboard\Core\Plugin\Base;
use Kanboard\Core\Security\Role;
use Kanboard\Core\Translator;
use Kanboard\Plugin\CommentTater\Helper\CommentTaterHelper;
use Kanboard\Plugin\CommentTater\Job\CommentTaterNotificationJob;
use Kanboard\Plugin\CommentTater\Model\ChangeModel;
use Kanboard\Plugin\CommentTater\Model\FieldModel;
use Kanboard\Plugin\CommentTater\Model\SettingsModel;

class Plugin extends Base
{
    public function initialize()
    {
        $this->container['commentTaterSettings'] = function ($c) {
            return new SettingsModel($c);
        };
        $this->container['commentTaterFields'] = function ($c) {
            return new FieldModel($c);
        };
        $this->container['commentTaterChange'] = function ($c) {
            return new ChangeModel($c);
        };

        // Core registers the job as a factory, so a plugin may replace it here. Only the per-user notifications for the
        // task events a CommentTater save causes are skipped; see the class.
        $this->container['notificationJob'] = $this->container->factory(function ($c) {
            return new CommentTaterNotificationJob($c);
        });

        $this->helper->register('commentTater', CommentTaterHelper::class);

        $this->projectAccessMap->add('CommentTaterController', 'save', Role::PROJECT_MEMBER);
        $this->applicationAccessMap->add('CommentTaterSettingsController', '*', Role::APP_ADMIN);

        $this->hook->on('template:layout:css', array('template' => 'plugins/CommentTater/Assets/css/commenttater.css'));

        $this->route->addRoute('/settings/commenttater', 'CommentTaterSettingsController', 'index', 'CommentTater');
    }

    /**
     * Runs after every plugin's initialize(). The comment form has no hook inside it, so the plugin ships a copy of
     * core's form (Kanboard 1.2.54) with the field actions added. Disable the plugin and core's form comes back.
     */
    public function onStartup()
    {
        Translator::load($this->languageModel->getCurrentLanguage(), __DIR__ . '/Locale');

        $this->template->setTemplateOverride('comment/create', 'CommentTater:comment/create');
        $this->template->setTemplateOverride('comment/show', 'CommentTater:comment/show');
        $this->template->setTemplateOverride('task_comments/create', 'CommentTater:task_comments/create');

        // Attached here and not in initialize(), so the menu item lands after the other plugins' items (BusyBee, SortaFolders).
        $this->template->hook->attach('template:config:sidebar', 'CommentTater:hook/config_sidebar');
    }

    public function getPluginName()
    {
        return 'CommentTater';
    }

    public function getPluginDescription()
    {
        return t('Change a task\'s fields from the comment form and let the comment record each change');
    }

    public function getPluginAuthor()
    {
        return 'Christefano Reyes';
    }

    public function getPluginVersion()
    {
        return '1.0.0';
    }

    public function getPluginHomepage()
    {
        return 'https://github.com/christefano/CommentTater';
    }

    public function getCompatibleVersion()
    {
        return '>=1.2.54';
    }
}
