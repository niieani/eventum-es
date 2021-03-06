<?php
/* vim: set expandtab tabstop=4 shiftwidth=4 encoding=utf-8: */
// +----------------------------------------------------------------------+
// | Eventum - Issue Tracking System                                      |
// +----------------------------------------------------------------------+
// | Copyright (c) 2003 - 2008 MySQL AB                                   |
// | Copyright (c) 2008 - 2010 Sun Microsystem Inc.                       |
// | Copyright (c) 2011 - 2013 Eventum Team.                              |
// |                                                                      |
// | This program is free software; you can redistribute it and/or modify |
// | it under the terms of the GNU General Public License as published by |
// | the Free Software Foundation; either version 2 of the License, or    |
// | (at your option) any later version.                                  |
// |                                                                      |
// | This program is distributed in the hope that it will be useful,      |
// | but WITHOUT ANY WARRANTY; without even the implied warranty of       |
// | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the        |
// | GNU General Public License for more details.                         |
// |                                                                      |
// | You should have received a copy of the GNU General Public License    |
// | along with this program; if not, write to:                           |
// |                                                                      |
// | Free Software Foundation, Inc.                                       |
// | 59 Temple Place - Suite 330                                          |
// | Boston, MA 02111-1307, USA.                                          |
// +----------------------------------------------------------------------+
// | Authors: João Prado Maia <jpm@mysql.com>                             |
// +----------------------------------------------------------------------+

/**
 * Class used to abstract the backend template system used by the site. This
 * is especially useful to be able to change template backends in the future
 * without having to rewrite all PHP based scripts.
 *
 * @version 1.0
 * @author João Prado Maia <jpm@mysql.com>
 */

class Template_Helper
{
    var $smarty;
    var $tpl_name = "";

    /**
     * Constructor of the class
     *
     * @access public
     */
    function __construct()
    {
        $this->smarty = new Smarty();
        $this->smarty->setTemplateDir(array(APP_LOCAL_PATH, APP_TPL_PATH));
        $this->smarty->setCompileDir(APP_TPL_COMPILE_PATH);
        $this->smarty->setPluginsDir(array(APP_INC_PATH . '/smarty', APP_SMARTY_PATH . '/plugins'));
        $this->smarty->registerPlugin("modifier", "activateLinks", array('Link_Filter', 'activateLinks'));
        $this->smarty->registerPlugin("modifier", "activateAttachmentLinks", array('Link_Filter', 'activateAttachmentLinks'));
        $this->smarty->registerPlugin("modifier", "formatCustomValue", array('Custom_Field', 'formatValue'));
        $this->smarty->registerPlugin("modifier", "bool", array('Misc', 'getBooleanDisplayValue'));
        $this->smarty->registerPlugin("modifier", "format_date", array('Date_Helper', 'getFormattedDate'));
    }


    /**
     * Sets the internal template filename for the current PHP script
     *
     * @param  string $tpl_name The filename of the template
     */
    public function setTemplate($tpl_name)
    {
        $this->tpl_name = $tpl_name;
    }


    /**
     * Assigns variables to specific placeholders on the target template
     *
     * @param  string $var_name Placeholder on the template
     * @param  string $value Value to be assigned to this placeholder
     */
    public function assign($var_name, $value = "")
    {
        if (!is_array($var_name)) {
            $this->smarty->assign($var_name, $value);
        } else {
            $this->smarty->assign($var_name);
        }
    }


    /**
     * Assigns variables to specific placeholders on the target template
     *
     * @access public
     * @param  array $array Array with the PLACEHOLDER=>VALUE pairs to be assigned
     */
    public function bulkAssign($array)
    {
        while (list($key, $value) = each($array)) {
            $this->smarty->assign($key, $value);
        }
    }


    /**
     * Prints the actual parsed template.
     *
     * @access public
     */
    public function displayTemplate()
    {
        $this->processTemplate();
        // finally display the parsed template
        $this->smarty->display($this->tpl_name);
    }


    /**
     * Returns the contents of the parsed template
     *
     * @access public
     * @return string The contents of the parsed template
     */
    public function getTemplateContents($process=True)
    {
        if ($process) {
            $this->processTemplate();
        }
        return $this->smarty->fetch($this->tpl_name);
    }


    /**
     * Processes the template and assigns common variables automatically.
     *
     * @access    private
     */
    private function processTemplate()
    {
        $core = array(
            'rel_url'   =>  APP_RELATIVE_URL,
            'base_url'  =>  APP_BASE_URL,
            'app_title' =>  APP_NAME,
            'app_version'   =>  APP_VERSION,
            'app_setup' =>  Setup::load(),
            'messages'  =>  Misc::getMessages(),
            'roles'     =>  User::getAssocRoleIDs(),
            'auth_backend'  =>  APP_AUTH_BACKEND,
            'current_url'   =>  $_SERVER['PHP_SELF'],
        );

        // If version is something like "Eventum 2.3.3-148-g78b3368", link ref to github
        if (preg_match('/^[\d.-]+-g(?P<hash>[0-9a-f]+)$/', APP_VERSION, $m)) {
            $link = "https://github.com/eventum/eventum/commit/{$m['hash']}";
            $core['application_version_link'] = $link;
        }

        $usr_id = Auth::getUserID();
        if ($usr_id != '') {
            $core['user'] = User::getDetails($usr_id);
            $prj_id = Auth::getCurrentProject();
            $setup = Setup::load();
            if (!empty($prj_id)) {
                $role_id = User::getRoleByUser($usr_id, $prj_id);
                $has_crm = CRM::hasCustomerIntegration($prj_id);
                $core = $core + array(
                    'project_id'    =>  $prj_id,
                    'project_name'  =>  Auth::getCurrentProjectName(),
                    'has_crm'       =>  $has_crm,
                    'current_role'              =>  $role_id,
                    'current_role_name'         =>  User::getRole($role_id),
                    'feature_access'            =>  Access::getFeatureAccessArray($usr_id)
                );
                if ($has_crm) {
                    $crm = CRM::getInstance($prj_id);
                    $core['crm_template_path'] = $crm->getTemplatePath();
                    if ($role_id == User::getRoleID('Customer')) {
                        try {
                            $contact = $crm->getContact($core['user']['usr_customer_contact_id']);
                            $core['allowed_customers'] = $contact->getCustomers();
                            $core['current_customer'] = $crm->getCustomer(Auth::getCurrentCustomerID(false));
                        } catch (CRMException $e) {}
                    }
                }
            }
            $info = User::getDetails($usr_id);
            $raw_projects = Project::getAssocList(Auth::getUserID(), false, true);
            $active_projects = array();
            foreach ($raw_projects as $prj_id => $prj_info) {
                if ($prj_info['status'] == 'archived') {
                    $prj_info['prj_title'] .= ' ' . ev_gettext('(archived)');
                }
                $active_projects[$prj_id] = $prj_info['prj_title'];
            }
            $core = $core + array(
                'active_projects'   =>  $active_projects,
                'current_full_name' =>  $info['usr_full_name'],
                'current_email'     =>  $info['usr_email'],
                'current_user_id'   =>  $usr_id,
                'is_current_user_clocked_in'    =>  User::isCLockedIn($usr_id),
                'is_anon_user'  =>  Auth::isAnonUser(),
                'is_current_user_partner'   =>  !empty($info['usr_par_code']),
                'roles' =>  User::getAssocRoleIDs(),

            );
            $this->assign("current_full_name", $core['user']["usr_full_name"]);
            $this->assign("current_email", $core['user']["usr_email"]);
            $this->assign("current_user_id", $usr_id);
            $this->assign("handle_clock_in", $setup['handle_clock_in'] == 'enabled');
            $this->assign("is_current_user_clocked_in", User::isClockedIn($usr_id));
            $this->assign("roles", User::getAssocRoleIDs());
        }
        $this->assign('core', $core);
    }
}
