<?php
/**
 * CommentingPlugin class
 *
 * @copyright Copyright 2011-2013 Roy Rosenzweig Center for History and New Media
 * @license http://www.gnu.org/licenses/gpl-3.0.txt GNU GPLv3
 * @package Commenting
 */

/**
 * Commenting plugin.
 */
class CommentingPlugin extends Omeka_Plugin_AbstractPlugin
{
    /**
     * @var array Hooks for the plugin.
     */
    protected $_hooks = array(
        'initialize',
        'install',
        'upgrade',
        'uninstall',
        'config_form',
        'config',
        'public_head',
        'admin_head',
        'public_items_show',
        'public_collections_show',
        'commenting_comments',
        'after_delete_record',
        'define_acl',
    );

    /**
     * @var array Filters for the plugin.
     */
    protected $_filters = array(
        'admin_navigation_main',
        'search_record_types',
        'api_resources',
        'api_extend_items',
        'api_extend_collections',
    );

    /**
     * @var array Options and their default values.
     */
    protected $_options = array(
        // serialize(array()) = 'a:0:{}'.
        'commenting_pages' => 'a:2:{i:0;s:16:"collections/show";i:1;s:10:"items/show";}',
        'commenting_comment_roles' => 'a:0:{}',
        'commenting_moderate_roles' => 'a:0:{}',
        'commenting_reqapp_comment_roles' => 'a:0:{}',
        'commenting_view_roles' => 'a:0:{}',
        'commenting_comments_label' => 'Comments',
        'commenting_flag_email' => '',
        'commenting_threaded' => false,
        'commenting_legal_text' => '',
        'commenting_allow_public' => true,
        'commenting_require_public_moderation' => true,
        'commenting_allow_public_view' => true,
        'commenting_wpapi_key' => '',
        'commenting_antispam' => true,
        'commenting_honeypot' => true,
        'new_comment_notification_recipients' => ''
    );

    /**
     * Add the translations.
     */
    public function hookInitialize()
    {
        add_translation_source(dirname(__FILE__) . '/languages');
    }

    public function setUp()
    {

        if (plugin_is_active('SimplePages')) {
            $this->_filters[] = 'api_extend_simple_pages';
        }
        if (plugin_is_active('ExhibitBuilder')) {
            $this->_filters[] = 'api_extend_exhibit_pages';
        }
        parent::setUp();
    }

    /**
     * Install the plugin.
     */
    public function hookInstall()
    {
        $db = $this->_db;

        // we think the 3rd party Comment plugin uses the same name,
        // and it doesn't delete its table on uninstall, causing some
        // collision problems. So, clobber it if it's here
        $sql = "
            DROP TABLE IF EXISTS `$db->Comment`;
        ";
        $db->query($sql);

        $sql = "
            CREATE TABLE IF NOT EXISTS `$db->Comment` (
              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `record_id` int(10) unsigned NOT NULL,
              `record_type` tinytext COLLATE utf8_unicode_ci NOT NULL,
              `path` tinytext COLLATE utf8_unicode_ci NOT NULL,
              `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              `body` text COLLATE utf8_unicode_ci NOT NULL,
              `author_email` tinytext COLLATE utf8_unicode_ci,
              `author_url` tinytext COLLATE utf8_unicode_ci,
              `author_name` tinytext COLLATE utf8_unicode_ci,
              `ip` tinytext COLLATE utf8_unicode_ci,
              `user_agent` tinytext COLLATE utf8_unicode_ci,
              `user_id` int(11) DEFAULT NULL,
              `parent_comment_id` int(11) DEFAULT NULL,
              `approved` tinyint(1) NOT NULL DEFAULT '0',
              `flagged` tinyint(1) NOT NULL DEFAULT '0',
              `is_spam` tinyint(1) NOT NULL DEFAULT '0',
              PRIMARY KEY (`id`),
              KEY `record_id` (`record_id`,`user_id`,`parent_comment_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
        ";
        $db->query($sql);

        $html = '<p>';
        $html .= __('I agree with %s terms of use %s and I accept to free my contribution under the licence %s CC BY-SA %s.',
            '<a rel="licence" href="#" target="_blank">', '</a>',
            '<a rel="licence" href="https://creativecommons.org/licenses/by-sa/3.0/" target="_blank">', '</a>'
        );
        $html .= '</p>';
        $this->_options['commenting_legal_text'] = $html;

        $this->_installOptions();
    }

    /**
     * Upgrade the plugin.
     */
    public function hookUpgrade($args)
    {
        $db = $this->_db;
        $old = $args['old_version'];
        $new = $args['new_version'];

        if (version_compare($old, '1.0', '<')) {
            if (!get_option('commenting_comment_roles')) {
                $commentRoles = array('super');
                set_option('commenting_comment_roles', serialize($commentRoles));
            }

            if (!get_option('commenting_moderate_roles')) {
                $moderateRoles = array('super');
                set_option('commenting_moderate_roles', serialize($moderateRoles));
            }

            if (!get_option('commenting_noapp_comment_roles')) {
                set_option('commenting_noapp_comment_roles', serialize(array()));
            }

            if (!get_option('commenting_view_roles')) {
                set_option('commenting_view_roles', serialize(array()));
            }
        }

        if (version_compare($old, '2.0', '<')) {
            $sql = "ALTER TABLE `$db->Comment` ADD `flagged` BOOLEAN NOT NULL DEFAULT '0' AFTER `approved` ";
            $db->query($sql);
        }

        if (version_compare($old, '2.1', '<')) {
            delete_option('commenting_noapp_comment_roles');
            set_option('commenting_reqapp_comment_roles', serialize(array()));
            set_option('commenting_pages', $this->_options['commenting_pages']);
            $sql = "ALTER TABLE `$db->Comment` CHANGE `flagged` `flagged` TINYINT( 1 ) NOT NULL DEFAULT '0'";
            $db->query($sql);
        }
    }

    /**
     * Uninstall the plugin.
     */
    public function hookUninstall()
    {
        $db = $this->_db;
        $sql = "DROP TABLE IF EXISTS `$db->Comment`";
        $db->query($sql);

        $this->_uninstallOptions();
    }

    public function hookPublicHead($args)
    {
        if ($this->_isCommentingEnabled()) {
            queue_css_file('commenting');
            queue_js_file('commenting');
            queue_js_file('tinymce.min', 'javascripts/vendor/tinymce/');
            queue_js_string("Commenting.pluginRoot = '" . WEB_ROOT . "/commenting/comment/'");
        }
    }

    public function hookAdminHead($args)
    {
        if ($this->_isCommentingEnabled()) {
            queue_css_file('commenting');
        }
    }

    /**
     * Helper to determine if comments are enabled on current page or not.
     */
    private function _isCommentingEnabled()
    {
        static $isEnabled = null;
        if (is_null($isEnabled)) {
            $request = Zend_Controller_Front::getInstance()->getRequest();
            $controller = $request->getControllerName();
            $action = $request->getActionName();
            $pages = get_option('commenting_pages');
            $pages = empty($pages) ? array() : unserialize($pages);
            $isEnabled = in_array($controller . '/' . $action, $pages);
        }
        return $isEnabled;
    }

    public function hookAfterDeleteRecord($args)
    {
        $record = $args['record'];
        $type = get_class($record);
        $comments = get_db()->getTable('Comment')->findBy(array('record_type' => $type, 'record_id' => $record->id));
        foreach ($comments as $comment) {
            $comment->delete();
        }
    }

    /**
     * Helper to append comments and comment form to a page.
     */
    protected function _showComments($args = array())
    {
        $view = get_view();
        // This option allows to display comments and comment form separately.
        $display = isset($args['display']) ? array($args['display']) : array('comments', 'comment_form');
        $record = isset($args['record']) ? $args['record'] : null;

        $html = '<div id="comments-container">';

        if (in_array('comments', $display)) {
            if ((get_option('commenting_allow_public') == 1)
                    || (get_option('commenting_allow_public_view') == 1)
                    || is_allowed('Commenting_Comment', 'show')
                ) {
                $options = array(
                    'record' => $record,
                    'threaded' => get_option('commenting_threaded'),
                    'approved' => true,
                );
                $comments = isset($args['comments']) ? $args['comments'] : $view->comments()->get($options);
                $html .= $view->partial('common/comments.php', array(
                    'comments' => $comments,
                    'threaded' => $options['threaded'],
                ));
             }
        }

        if (in_array('comment_form', $display)) {
            if ((get_option('commenting_allow_public') == 1)
                    || is_allowed('Commenting_Comment', 'add')
                ) {
                $html .= '<div id="comment-main-container">';
                $html .= $view->getCommentForm($record);
                $html .= '</div>';
            }
        }

        $html .= '</div>';
        echo $html;
    }

    public function hookPublicItemsShow($args)
    {
        $this->_showComments($args);
    }

    public function hookPublicCollectionsShow($args)
    {
        $this->_showComments($args);
    }

    /**
     * This hook can be used in place of view helpers Comments() and
     * GetCommentForm().
     */
    public function hookCommentingComments($args)
    {
        $this->_showComments($args);
    }

    /**
     * Shows plugin configuration page.
     */
    public function hookConfigForm($args)
    {
        $view = get_view();
        echo $view->partial(
            'plugins/commenting-config-form.php'
        );
    }

    /**
     * Saves plugin configuration page.
     *
     * @param array Options set in the config form.
     */
    public function hookConfig($args)
    {
        $post = $args['post'];
        foreach ($this->_options as $optionKey => $optionValue) {
            if (in_array($optionKey, array(
                    'commenting_pages',
                    'commenting_comment_roles',
                    'commenting_moderate_roles',
                    'commenting_view_roles',
                    'commenting_reqapp_comment_roles',
                ))) {
                $post[$optionKey] = empty($post[$optionKey]) ? serialize(array()) : serialize($post[$optionKey]);
            } elseif ($optionKey == 'new_comment_notification_recipients') {
                // sanitize list of emails
                $emails = array_map('trim', (array) explode("\n", $optionValue));
                $emails = array_filter($emails, function($val) {
                    return filter_var($val, FILTER_VALIDATE_EMAIL);
                });
                $optionValue = implode("\n", $emails);
            }
            if (isset($post[$optionKey])) {
                set_option($optionKey, $post[$optionKey]);
            }
        }
    }

    public function hookDefineAcl($args)
    {
        $acl = $args['acl'];
        $acl->addResource('Commenting_Comment');
        $commentRoles = unserialize(get_option('commenting_comment_roles')) ?: [];
        $moderateRoles = unserialize(get_option('commenting_moderate_roles')) ?: [];
        $viewRoles = unserialize(get_option('commenting_view_roles')) ?: [];
        $acl->allow(null, 'Commenting_Comment', array('flag'));
        if ($viewRoles !== false) {
            foreach ($viewRoles as $role) {
                //check that all the roles exist, in case a plugin-added role has been removed (e.g. GuestUser)
                if ($acl->hasRole($role)) {
                    $acl->allow($role, 'Commenting_Comment', 'show');
                }
            }

            foreach ($commentRoles as $role) {
                if ($acl->hasRole($role)) {
                    $acl->allow($role, 'Commenting_Comment', 'add');
                }
            }

            foreach ($moderateRoles as $role) {
                if ($acl->hasRole($role)) {
                    $acl->allow($role, 'Commenting_Comment', array(
                        'update-approved',
                        'update-spam',
                        'update-flagged',
                        'batch-delete',
                        'browse',
                        'delete',
                    ));
                }
            }

            if (get_option('commenting_allow_public')) {
                $acl->allow(null, 'Commenting_Comment', array('show', 'add'));
            }
        }
    }

    public function filterAdminNavigationMain($tabs)
    {
        if (is_allowed('Commenting_Comment', 'update-approved')) {
            $tabs[] = array('uri' => url('commenting/comment/browse'), 'label' => __('Comments'));
        }

        return $tabs;
    }

    public function filterSearchRecordTypes($types)
    {
        $types['Comment'] = __('Comments');
        return $types;
    }

    public function filterApiResources($apiResources)
    {
        $apiResources['comments'] = array(
            'record_type' => 'Comment',
            'actions' => array('get', 'index'),
            'index_params' => array('record_type', 'record_id'),
        );
        return $apiResources;
    }

    public function filterApiExtendItems($extend, $args)
    {
        return $this->_filterApiExtendRecords($extend, $args);
    }

    public function filterApiExtendCollections($extend, $args)
    {
        return $this->_filterApiExtendRecords($extend, $args);
    }

    public function filterApiExtendSimplePages($extend, $args)
    {
        return $this->_filterApiExtendRecords($extend, $args);
    }

    public function filterApiExtendExhibitPages($extend, $args)
    {
        return $this->_filterApiExtendRecords($extend, $args);
    }

    private function _filterApiExtendRecords($extend, $args)
    {
        $record = $args['record'];
        $recordClass = get_class($record);
        $extend['comments'] = array(
            'count' => $this->_countComments($record),
            'resource' => 'comments',
            'url' => Omeka_Record_Api_AbstractRecordAdapter::getResourceUrl("/comments?record_type=$recordClass&record_id={$record->id}"),
        );

        return $extend;
    }

    private function _countComments($record)
    {
        $params = array(
            'record_type' => get_class($record),
            'record_id' => $record->id,
        );
        return get_db()->getTable('Comment')->count($params);
    }
}
