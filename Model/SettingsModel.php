<?php

namespace Kanboard\Plugin\CommentTater\Model;

use Kanboard\Core\Base;

/**
 * The CommentTater settings, stored in Kanboard's own settings table under commenttater_* keys.
 */
class SettingsModel extends Base
{
    const SHOW_ICON = 'commenttater_show_icon';
    const ICON = 'commenttater_icon';
    const FIELD_PREFIX = 'commenttater_field_';
    const POSITION = 'commenttater_position';
    const ALLOW_EMPTY = 'commenttater_allow_empty';
    const EXPANDED = 'commenttater_expanded';

    const DEFAULT_ICON = 'commenting';

    /**
     * The only icons the admin can pick: key => array(Font Awesome 4.7 class, label). Kanboard 1.2.54 ships
     * Font Awesome 4.7. The stored value is always one of these keys, never a class name from a request.
     */
    public static function icons()
    {
        return array(
            'commenting'  => array('fa-commenting', 'Commenting'),
            'comment'     => array('fa-comment', 'Comment'),
            'comments'    => array('fa-comments', 'Comments'),
            'reply'       => array('fa-reply', 'Reply'),
            'reply-all'   => array('fa-reply-all', 'Reply all'),
            'quote-left'  => array('fa-quote-left', 'Quote left'),
            'quote-right' => array('fa-quote-right', 'Quote right'),
            'pencil'          => array('fa-pencil', 'Pencil'),
            'pencil-square'   => array('fa-pencil-square', 'Pencil square'),
            'pencil-square-o' => array('fa-pencil-square-o', 'Pencil square outline'),
            'sticky-note'     => array('fa-sticky-note', 'Sticky note'),
            'sticky-note-o'   => array('fa-sticky-note-o', 'Sticky note outline'),
        );
    }

    public function iconsShown()
    {
        return $this->flag(self::SHOW_ICON, true);
    }

    /**
     * The chosen icon's key, or the default when the stored value is not on the list.
     */
    public function iconKey()
    {
        $stored = (string) $this->configModel->get(self::ICON, self::DEFAULT_ICON);

        return array_key_exists($stored, self::icons()) ? $stored : self::DEFAULT_ICON;
    }

    public function iconClass()
    {
        $icons = self::icons();

        return $icons[$this->iconKey()][0];
    }

    public function fieldEnabled($name)
    {
        return $this->flag(self::FIELD_PREFIX.$name, true);
    }

    public function changesOnTop()
    {
        return $this->configModel->get(self::POSITION, 'top') !== 'bottom';
    }

    public function allowEmpty()
    {
        return $this->flag(self::ALLOW_EMPTY, true);
    }

    public function expanded()
    {
        return $this->flag(self::EXPANDED, false);
    }

    /**
     * Save the settings page. Every value is rebuilt from the allowlists, so nothing posted is stored as is.
     */
    public function save(array $values)
    {
        $settings = array(
            self::SHOW_ICON   => empty($values['show_icon']) ? '0' : '1',
            self::ALLOW_EMPTY => empty($values['allow_empty']) ? '0' : '1',
            self::EXPANDED    => empty($values['expanded']) ? '0' : '1',
            self::POSITION    => (isset($values['position']) && $values['position'] === 'top') ? 'top' : 'bottom',
        );

        $asked = isset($values['icon']) ? (string) $values['icon'] : '';
        $settings[self::ICON] = array_key_exists($asked, self::icons()) ? $asked : self::DEFAULT_ICON;

        foreach (array_keys(FieldModel::definitions()) as $name) {
            $settings[self::FIELD_PREFIX.$name] = empty($values['field_'.$name]) ? '0' : '1';
        }

        return $this->configModel->save($settings);
    }

    private function flag($key, $default)
    {
        return $this->configModel->get($key, $default ? '1' : '0') === '1';
    }
}
