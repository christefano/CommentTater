<?php

namespace Kanboard\Plugin\CommentTater\Helper;

use Kanboard\Core\Base;
use Kanboard\Plugin\CommentTater\Model\SettingsModel;
use Kanboard\Plugin\CommentTater\Model\ChangeModel;
use Kanboard\Plugin\CommentTater\Model\FieldModel;

/**
 * Template helper: the icon and the field block on the comment form. Fields reuse core's own task form
 * renderers (TaskHelper), so the inputs behave as they do on the task edit form.
 */
class CommentTaterHelper extends Base
{
    /**
     * The settings model, for templates (they reach helpers, not the container).
     */
    public function settings()
    {
        return $this->commentTaterSettings;
    }

    /**
     * Whether the browser should insist on comment text. Not when the user can change fields and the admin allows
     * a comment that holds only the change list. The server still refuses an empty comment with no changes.
     */
    public function textRequired(array $task)
    {
        return ! ($this->commentTaterSettings->allowEmpty() && count($this->commentTaterFields->offered($task)) > 0);
    }

    /**
     * The chosen icon, or an empty string when the admin has hidden it.
     */
    public function icon()
    {
        if (! $this->commentTaterSettings->iconsShown()) {
            return '';
        }

        return '<i class="fa '.$this->commentTaterSettings->iconClass().' commenttater-icon" aria-hidden="true"></i> ';
    }

    /**
     * The icon with an explicit key (the settings page shows all twelve).
     */
    public function iconFor($key)
    {
        $icons = SettingsModel::icons();

        return isset($icons[$key]) ? '<i class="fa '.$icons[$key][0].' commenttater-icon" aria-hidden="true"></i>' : '';
    }

    /**
     * The "Update task" block. When the user may change none, the block still shows, open, with a note, so the form
     * shows the plugin is installed.
     *
     * @param array $task    Task as stored now
     * @param array $values  Posted values when the form is shown again after an error, otherwise empty
     * @param array $errors  Errors by field name
     */
    public function fields(array $task, array $values = array(), array $errors = array())
    {
        $offered = $this->commentTaterFields->offered($task);

        if (empty($offered)) {
            return '<details class="commenttater commenttater-none" open><summary title="'.$this->helper->text->e(t('Change task fields with this comment.')).'">'
                .$this->icon().$this->helper->text->e(t('Update task')).' <i class="fa fa-caret-down commenttater-caret" aria-hidden="true"></i></summary>'
                .'<p class="form-help">'.$this->helper->text->e(t('No fields to change on this task.')).'</p></details>';
        }

        $shown = $this->commentTaterFields->currentValues($task);
        $orig = (isset($values['ct_orig']) && is_array($values['ct_orig'])) ? $values['ct_orig'] : $shown;
        $form = array();

        foreach ($shown as $name => $value) {
            $form[$name] = array_key_exists($name, $values) ? $values[$name] : $value;
        }

        $form['project_id'] = $task['project_id'];
        $project = $this->projectModel->getById($task['project_id']);

        $open = $this->commentTaterSettings->expanded() || ! empty($errors) && array_intersect_key($errors, array_flip($offered));
        $html = '<details class="commenttater" '.($open ? 'open' : '').'>';
        $html .= '<summary title="'.$this->helper->text->e(t('Change task fields with this comment.')).'">'.$this->icon().$this->helper->text->e(t('Update task')).' <i class="fa fa-caret-down commenttater-caret" aria-hidden="true"></i></summary>';
        $failed = array_keys(array_intersect_key($errors, array_flip($offered)));

        if (! empty($failed)) {
            $labels = array();

            foreach ($failed as $name) {
                $labels[] = t(FieldModel::definitions()[$name][0]);
            }

            $html .= '<p class="alert alert-error" role="alert">'.$this->helper->text->e(t('Check these fields: %s', implode(', ', $labels))).'</p>';
        }

        // Core's task form layout: two secondary columns, the first for who and what, the second for dates and numbers.
        $columns = array(
            array('color_id', 'owner_id', 'category_id', 'column', 'priority'),
            array('date_due', 'date_started', 'time_estimated', 'time_spent', 'score', 'reference'),
        );
        $html .= '<div class="task-form-container commenttater-fields">';

        foreach ($columns as $column) {
            $names = array_values(array_intersect($column, $offered));

            if (empty($names)) {
                continue;
            }

            $html .= '<div class="task-form-secondary-column commenttater-column">';

            foreach ($names as $name) {
                // Core's field renderers hardcode tabindex 4 to 15, which would send Tab past the rest of the form. Without them the Tab order follows the page.
                $html .= preg_replace('/\stabindex="\d+"/', '', $this->field($name, $task, $project, $form, $errors));
                $html .= '<input type="hidden" name="ct_orig['.$name.']" value="'.$this->helper->text->e($this->origValue($name, $orig)).'">';
            }

            $html .= '</div>';
        }

        $html .= '</div></details>';

        return $html;
    }

    /**
     * A comment as Markdown HTML. The change list CommentTater wrote (the "- Label: old → new" lines) gets its own
     * wrapper, so the stylesheet can stripe it and shrink it. Any other text renders as core renders it.
     */
    public function markdown($text, $isPublic = false)
    {
        $lines = explode("\n", str_replace("\r\n", "\n", (string) $text));
        $fenced = array();
        $fence = '';
        $length = 0;

        // A line inside a code fence is never part of the change list. Like Parsedown, a fence closes only on a line of
        // the same character, at least as long, with nothing else on it.
        foreach ($lines as $n => $line) {
            $fenced[$n] = $fence !== '';

            if ($fence === '') {
                if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $m)) {
                    $fence = $m[1][0];
                    $length = strlen($m[1]);
                    $fenced[$n] = true;
                }
            } elseif (preg_match('/^ {0,3}('.preg_quote($fence, '/').'{'.$length.',})[ \t]*$/', $line)) {
                $fence = '';
            }
        }

        $isChange = function ($n) use ($lines, $fenced) {
            return ! $fenced[$n] && strpos($lines[$n], '- ') === 0 && strpos($lines[$n], ' '.ChangeModel::ARROW.' ') !== false;
        };
        $segments = array();
        $plain = array();
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            if (! $isChange($i)) {
                $plain[] = $lines[$i];
                continue;
            }

            $block = array();

            while ($i < $count && $isChange($i)) {
                $block[] = $lines[$i++];
            }

            $i--;

            if (trim(implode("\n", $plain)) !== '') {
                $segments[] = $this->helper->text->markdown(implode("\n", $plain), $isPublic);
            }

            $plain = array();
            $segments[] = '<div class="commenttater-changes">'.$this->helper->text->markdown(implode("\n", $block), $isPublic).'</div>';
        }

        if (empty($segments)) {
            return $this->helper->text->markdown($text, $isPublic);
        }

        if (trim(implode("\n", $plain)) !== '') {
            $segments[] = $this->helper->text->markdown(implode("\n", $plain), $isPublic);
        }

        return implode("\n", $segments);
    }

    private function origValue($name, array $orig)
    {
        $value = isset($orig[$name]) && is_scalar($orig[$name]) ? (string) $orig[$name] : '';

        // Dates arrive as timestamps from the task and as the user's text after a failed post.
        if (in_array($name, array('date_due', 'date_started'), true) && ctype_digit($value) && (int) $value > 0) {
            $value = date($this->dateParser->getUserDateTimeFormat(), (int) $value);
        }

        return $value;
    }

    private function field($name, array $task, array $project, array $form, array $errors)
    {
        $task_helper = $this->helper->task;

        switch ($name) {
            case 'color_id':
                if ($this->commentTaterFields->formOptions($task, 'color_id') !== $this->commentTaterFields->options($task, 'color_id')) {
                    return $this->helper->form->label(t('Color'), 'color_id')
                        .$this->helper->form->select('color_id', $this->commentTaterFields->formOptions($task, 'color_id'), $form, array(), array(), 'color-picker');
                }

                return $task_helper->renderColorField($form);

            case 'owner_id':
                $html = $task_helper->renderAssigneeField($this->commentTaterFields->formOptions($task, 'owner_id'), $form, $errors);

                // Core's "Me" handler only reaches the popup's form, so on the task-page form the link would do nothing.
                if (! $this->request->isAjax()) {
                    $html = preg_replace('~&nbsp;<small><a [^>]*class="assign-me".*?</a></small>~s', '', $html);
                }

                return $html;

            case 'category_id':
                return $task_helper->renderCategoryField($this->commentTaterFields->options($task, 'category_id'), $form, $errors);

            case 'column':
                return $this->helper->form->label(t('Column'), 'column').$this->helper->form->select('column', $this->commentTaterFields->options($task, 'column'), $form, $errors);

            case 'priority':
                if ($this->commentTaterFields->formOptions($task, 'priority') !== $this->commentTaterFields->options($task, 'priority')) {
                    return $this->helper->form->label(t('Priority'), 'priority')
                        .$this->helper->form->select('priority', $this->commentTaterFields->formOptions($task, 'priority'), $form);
                }

                return $task_helper->renderPriorityField($project, $form);

            case 'date_due':
                return $task_helper->renderDueDateField($form, $errors);

            case 'date_started':
                return $task_helper->renderStartDateField($form, $errors);

            case 'time_estimated':
                return $task_helper->renderTimeEstimatedField($form, $errors);

            case 'time_spent':
                return $task_helper->renderTimeSpentField($form, $errors);

            case 'score':
                return $task_helper->renderScoreField($form, $errors);

            case 'reference':
                return $task_helper->renderReferenceField($form, $errors);
        }

        return '';
    }
}
