<?php
namespace KayStrobach\BeAcl\Xclass;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2016 Tomita Militaru (tomitamilitaru@stratis.fr)
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use TYPO3\CMS\Backend\Tree\View\PageTreeView;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\Utility\IconUtility;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\View\ViewInterface;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Backend ACL - Replacement for "web->Access"
 *
 * @author  Sebastian Kurfuerst <sebastian@garbage-group.de>
 * @author  Tomita Militaru <tomitamilitaru@stratis.fr>
 */
class PermissionController extends \TYPO3\CMS\Beuser\Controller\PermissionController
{
    /**
     * @var \KayStrobach\BeAcl\View\BackendTemplateView
     */
    protected $view;
    /**
     * @var \KayStrobach\BeAcl\View\BackendTemplateView
     */
    protected $defaultViewObjectName = \KayStrobach\BeAcl\View\BackendTemplateView::class;

    /**
     * Initialize view with custom template (workaround for override templates problem)
     * @param ViewInterface $view
     */
    public function initializeView(ViewInterface $view)
    {
        parent::initializeView($view);

        if ($this->view->getTemplateView() !== null) {
            $this->view->getTemplateView()->setTemplateRootPaths(array(0 => 'EXT:be_acl/Resources/Private/Templates/'));
            $this->view->getTemplateView()->setLayoutRootPaths(array(0 => 'EXT:be_acl/Resources/Private/Layouts/'));
            $this->view->getTemplateView()->setPartialRootPaths(array(0 => 'EXT:be_acl/Resources/Private/Partials/'));
        }
    }

    /**
     * Index action
     *
     * @return void
     */
    public function indexAction()
    {
        // Get ACL configuration
        $beAclConfig = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['be_acl']);
        $disableOldPermissionSystem = 0;
        if ($beAclConfig['disableOldPermissionSystem']) {
            $disableOldPermissionSystem = 1;
        }
        $GLOBALS['LANG']->includeLLFile('EXT:be_acl/res/locallang_perm.xml');

        // Get usernames and groupnames: The arrays we get in return contains only 1) users which are members of the groups of the current user, 2) groups that the current user is member of
		$beGroupKeys = $GLOBALS['BE_USER']->userGroupsUID;
		$beUserArray = BackendUtility::getUserNames();

		if (!$GLOBALS['BE_USER']->isAdmin()) {
            $beUserArray = BackendUtility::blindUserNames($beUserArray, $beGroupKeys, 0);
        }
		$beGroupArray = BackendUtility::getGroupNames();

		if (!$GLOBALS['BE_USER']->isAdmin()) {
            $beGroupArray = BackendUtility::blindGroupNames($beGroupArray, $beGroupKeys, 0);
        }
        // Get list of ACL users and groups, and initialize ACLs
        $aclUsers = $this->acl_objectSelector(0, $beAclConfig);
        $aclGroups = $this->acl_objectSelector(1, $beAclConfig);

        $this->buildACLtree($aclUsers, $aclGroups);

        if (!$this->id) {
            $this->pageInfo = array('title' => '[root-level]', 'uid' => 0, 'pid' => 0);
        }

        if ($this->getBackendUser()->workspace != 0) {
            // Adding section with the permission setting matrix:
            $this->addFlashMessage(
                LocalizationUtility::translate('LLL:EXT:beuser/Resources/Private/Language/locallang_mod_permission.xlf:WorkspaceWarningText', 'beuser'),
                LocalizationUtility::translate('LLL:EXT:beuser/Resources/Private/Language/locallang_mod_permission.xlf:WorkspaceWarning', 'beuser'),
                FlashMessage::WARNING
            );
        }

        // depth options
        $depthOptions = array();
        $url = $this->uriBuilder->reset()->setArguments(array(
            'action' => 'index',
            'depth' => '__DEPTH__',
            'tx_beacl_objsel' => '__GROUPS__',
            'id' => $this->id
        ))->buildBackendUri();
        foreach (array(1, 2, 3, 4, 10) as $depthLevel) {
            $depthOptions[$depthLevel] = $depthLevel . ' ' . LocalizationUtility::translate('LLL:EXT:beuser/Resources/Private/Language/locallang_mod_permission.xlf:levels', 'beuser');
        }
        $this->view->assign('depthBaseUrl', $url);
        $this->view->assign('depth', $this->depth);
        $this->view->assign('depthOptions', $depthOptions);

        $beUserArray = BackendUtility::getUserNames();
        $this->view->assign('beUsers', $beUserArray);
        $beGroupArray = BackendUtility::getGroupNames();
        $this->view->assign('beGroups', $beGroupArray);

        /** @var $tree PageTreeView */
        $tree = GeneralUtility::makeInstance(PageTreeView::class);
        $tree->init();
        $tree->addField('perms_user', true);
        $tree->addField('perms_group', true);
        $tree->addField('perms_everybody', true);
        $tree->addField('perms_userid', true);
        $tree->addField('perms_groupid', true);
        $tree->addField('hidden');
        $tree->addField('fe_group');
        $tree->addField('starttime');
        $tree->addField('endtime');
        $tree->addField('editlock');

        // Create the tree from $this->id
        if ($this->id) {
            $tree->tree[] = array('row' => $this->pageInfo, 'HTML' => $tree->getIcon($this->id));
        } else {
            $tree->tree[] = array('row' => $this->pageInfo, 'HTML' => $tree->getRootIcon($this->pageInfo));
        }
        $tree->getTree($this->id, $this->depth);
        $this->view->assign('viewTree', $tree->tree);

        // CSH for permissions setting
        $this->view->assign('cshItem', BackendUtility::cshItem('xMOD_csh_corebe', 'perm_module'));
    }

    /**
     * Edit action
     *
     * @return void
     */
    public function editAction()
    {
        // Get ACL configuration
        $disableOldPermissionSystem = 0;
        $beAclConfig = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['be_acl']);
        if ($beAclConfig['disableOldPermissionSystem']) {
            $disableOldPermissionSystem = 1;
        }

        $this->view->assign('id', $this->id);
        $this->view->assign('depth', $this->depth);

        if (!$this->id) {
            $this->pageInfo = array('title' => '[root-level]', 'uid' => 0, 'pid' => 0);
        }
        if ($this->getBackendUser()->workspace != 0) {
            // Adding FlashMessage with the permission setting matrix:
            $this->addFlashMessage(
                LocalizationUtility::translate('LLL:EXT:beuser/Resources/Private/Language/locallang_mod_permission.xlf:WorkspaceWarningText', 'beuser'),
                LocalizationUtility::translate('LLL:EXT:beuser/Resources/Private/Language/locallang_mod_permission.xlf:WorkspaceWarning', 'beuser'),
                FlashMessage::WARNING
            );
        }
        // Get usernames and groupnames
        $beGroupArray = BackendUtility::getListGroupNames('title,uid');
        $beUserArray  = BackendUtility::getUserNames();

        // Owner selector
        $beUserDataArray = array(0 => LocalizationUtility::translate('LLL:EXT:beuser/Resources/Private/Language/locallang_mod_permission.xlf:selectNone', 'beuser'));
        foreach ($beUserArray as $uid => &$row) {
            $beUserDataArray[$uid] = $row['username'];
        }
        $beUserDataArray[-1] = LocalizationUtility::translate('LLL:EXT:beuser/Resources/Private/Language/locallang_mod_permission.xlf:selectUnchanged', 'beuser');
        $this->view->assign('currentBeUser', $this->pageInfo['perms_userid']);
        $this->view->assign('beUserData', $beUserDataArray);

        // Group selector
        $beGroupDataArray = array(0 => LocalizationUtility::translate('LLL:EXT:beuser/Resources/Private/Language/locallang_mod_permission.xlf:selectNone', 'beuser'));
        foreach ($beGroupArray as $uid => $row) {
            $beGroupDataArray[$uid] = $row['title'];
        }
        $beGroupDataArray[-1] = LocalizationUtility::translate('LLL:EXT:beuser/Resources/Private/Language/locallang_mod_permission.xlf:selectUnchanged', 'beuser');

        $res = $GLOBALS['TYPO3_DB']->exec_SELECTquery('*', 'tx_beacl_acl', 'pid=' . (int) $this->id);
        while ($result = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {
            $permAcls[] = $result;
        }
        $this->view->assign('perms_acls', $permAcls);
        $this->view->assign('currentBeGroup', $this->pageInfo['perms_groupid']);
        $this->view->assign('beGroupData', $beGroupDataArray);
        $this->view->assign('pageInfo', $this->pageInfo);
        $this->view->assign('returnId', $this->returnId);
        $this->view->assign('recursiveSelectOptions', $this->getRecursiveSelectOptions());
        $this->view->assign('disableOldPermissionSystem', $disableOldPermissionSystem);
        $this->view->assign('extRelPath', \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::extRelPath('be_acl'));
    }

    /**
     * Update action
     *
     * @param array $data
     * @param array $mirror
     * @return void
     */
    protected function updateAction(array $data, array $mirror)
    {
        if (!empty($data['pages'])) {
            foreach ($data['pages'] as $pageUid => $properties) {
                // if the owner and group field shouldn't be touched, unset the option
                if ((int)$properties['perms_userid'] === -1) {
                    unset($properties['perms_userid']);
                }
                if ((int)$properties['perms_groupid'] === -1) {
                    unset($properties['perms_groupid']);
                }
                $this->getDatabaseConnection()->exec_UPDATEquery(
                    'pages',
                    'uid = ' . (int)$pageUid,
                    $properties
                );
                if (!empty($mirror['pages'][$pageUid])) {
                    $mirrorPages = GeneralUtility::trimExplode(',', $mirror['pages'][$pageUid]);
                    foreach ($mirrorPages as $mirrorPageUid) {
                        $this->getDatabaseConnection()->exec_UPDATEquery(
                            'pages',
                            'uid = ' . (int)$mirrorPageUid,
                            $properties
                        );
                    }
                }
            }
        }
        $this->redirect('index', null, null, array('id' => $this->returnId, 'depth' => $this->depth));
    }

    /**
     * outputs a selector for users / groups, returns current ACLs
     *
     * @param    integer        type of ACL. 0 -> user, 1 -> group
     * @param    array        configuration of ACLs
     * @return    array        list of groups/users where the ACLs will be shown
     */
    function acl_objectSelector($type, $conf) {
        global $BE_USER;
        $aclObjects = array();
        $res = $GLOBALS['TYPO3_DB']->exec_SELECTquery(
            'tx_beacl_acl.object_id AS object_id, tx_beacl_acl.type AS type',
            'tx_beacl_acl, be_groups, be_users',
            'tx_beacl_acl.type=' . intval($type) . ' AND ((tx_beacl_acl.object_id=be_groups.uid AND tx_beacl_acl.type=1) OR (tx_beacl_acl.object_id=be_users.uid AND tx_beacl_acl.type=0))',
            '',
            'be_groups.title ASC, be_users.realname ASC'
        );
        while ($result = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {
            $aclObjects[] = $result['object_id'];
        }
        $aclObjects = array_unique($aclObjects);
        // advanced selector disabled
        if (!$conf['enableFilterSelector']) {
            return $aclObjects;
        }

        if (!empty($aclObjects)) {

            // Get usernames and groupnames: The arrays we get in return contains only 1) users which are members of the groups of the current user, 2) groups that the current user is member of
            $groupArray = $BE_USER->userGroupsUID;
            $be_user_Array = BackendUtility::getUserNames();
            if (!$GLOBALS['BE_USER']->isAdmin()) {
                $be_user_Array = BackendUtility::blindUserNames($be_user_Array, $groupArray, 0);
            }
            $be_group_Array = BackendUtility::getGroupNames();
            if (!$GLOBALS['BE_USER']->isAdmin()) {
                $be_group_Array = BackendUtility::blindGroupNames($be_group_Array, $groupArray, 0);
            }

            // get current selection from UC, merge data, write it back to UC
            $currentSelection = is_array($BE_USER->uc['moduleData']['txbeacl_aclSelector'][$type]) ? $BE_USER->uc['moduleData']['txbeacl_aclSelector'][$type] : array();

            $currentSelection = GeneralUtility::_GP('tx_beacl_objsel') !== null ? GeneralUtility::_GP('tx_beacl_objsel') : $currentSelection;

            $BE_USER->uc['moduleData']['txbeacl_aclSelector'][$type] = $currentSelection;
            $BE_USER->writeUC($BE_USER->uc);
            return $currentSelection;
        }

        return NULL;
    }

    /**
     * returns a datastructure: pageid - userId / groupId - permissions
     *
     * @param    array        user ID list
     * @param    array        group ID list
     */
    function buildACLtree($users, $groups) {

        // get permissions in the starting point for users and groups
        $rootLine = BackendUtility::BEgetRootLine($this->id);

        $userStartPermissions = array();
        $groupStartPermissions = array();

        array_shift($rootLine); // needed as a starting point

        foreach ($rootLine as $level => $values) {
            $recursive = ' AND recursive=1';

            $res = $GLOBALS['TYPO3_DB']->exec_SELECTquery('type, object_id, permissions', 'tx_beacl_acl', 'pid=' . intval($values['uid']) . $recursive);

            while ($result = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {
                if ($result['type'] == 0
                    && in_array($result['object_id'], $users)
                    && !array_key_exists($result['object_id'], $userStartPermissions)
                ) {
                    $userStartPermissions[$result['object_id']] = $result['permissions'];
                } elseif ($result['type'] == 1
                    && in_array($result['object_id'], $groups)
                    && !array_key_exists($result['object_id'], $groupStartPermissions)
                ) {
                    $groupStartPermissions[$result['object_id']] = $result['permissions'];
                }
            }
        }
        foreach ($userStartPermissions as $oid => $perm) {
            $startPerms[0][$oid]['permissions'] = $perm;
            $startPerms[0][$oid]['recursive'] = 1;
        }
        foreach ($groupStartPermissions as $oid => $perm) {
            $startPerms[1][$oid]['permissions'] = $perm;
            $startPerms[1][$oid]['recursive'] = 1;
        }


        $this->traversePageTree_acl($startPerms, $rootLine[0]['uid']);

        // check if there are any ACLs on these pages
        // build a recursive function traversing through the pagetree
    }

    function countAcls($pageData) {
        $i = 0;
        if (!$pageData) {
            return '';
        }
        foreach ($pageData as $aclId => $values) {
            if ($values['newAcl']) {
                $i += $values['newAcl'];
            }
        }

        return ($i ? $i : '');
    }

    /**
     * build ACL tree
     */
    function traversePageTree_acl($parentACLs, $uid) {
        $res = $GLOBALS['TYPO3_DB']->exec_SELECTquery('type, object_id, permissions, recursive', 'tx_beacl_acl', 'pid=' . intval($uid));

        $hasNoRecursive = array();
        $this->aclList[$uid] = $parentACLs;
        while ($result = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {
            $permissions = array(
                'permissions' => $result['permissions'],
                'recursive' => $result['recursive'],
            );
            if ($result['recursive'] == 0) {
                if ($this->aclList[$uid][$result['type']][$result['object_id']]['newAcl']) {
                    $permissions['newAcl'] = $this->aclList[$uid][$result['type']][$result['object_id']]['newAcl'];
                }
                $this->aclList[$uid][$result['type']][$result['object_id']] = $permissions;
                $permissions['newAcl'] = 1;
                $hasNoRecursive[$uid][$result['type']][$result['object_id']] = $permissions;
            } else {
                $parentACLs[$result['type']][$result['object_id']] = $permissions;
                if (is_array($hasNoRecursive[$uid][$result['type']][$result['object_id']])) {
                    $this->aclList[$uid][$result['type']][$result['object_id']] = $hasNoRecursive[$uid][$result['type']][$result['object_id']];
                } else {
                    $this->aclList[$uid][$result['type']][$result['object_id']] = $permissions;
                }
            }
            $this->aclList[$uid][$result['type']][$result['object_id']]['newAcl'] += 1;
        }

        $res = $GLOBALS['TYPO3_DB']->exec_SELECTquery('uid', 'pages', 'pid=' . intval($uid) . ' AND deleted=0');
        while ($result = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {
            $this->traversePageTree_acl($parentACLs, $result['uid']);
        }
    }
}
