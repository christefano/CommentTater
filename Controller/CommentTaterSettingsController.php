<?php

namespace Kanboard\Plugin\CommentTater\Controller;

use Kanboard\Controller\BaseController;

/**
 * The CommentTater settings page. Admins only (see Plugin.php).
 */
class CommentTaterSettingsController extends BaseController
{
    public function index()
    {
        $this->response->html($this->helper->layout->config('CommentTater:settings/index', array(
            'title' => t('Settings').' &gt; CommentTater',
        )));
    }

    public function save()
    {
        $this->checkCSRFForm();

        if ($this->commentTaterSettings->save($this->request->getValues())) {
            $this->flash->success(t('Settings saved successfully.'));
        } else {
            $this->flash->failure(t('Unable to save your settings.'));
        }

        $this->response->redirect($this->helper->url->to('CommentTaterSettingsController', 'index', array('plugin' => 'CommentTater')));
    }
}
