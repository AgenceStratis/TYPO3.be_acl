<?php
namespace KayStrobach\BeAcl\Xclass;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Tree\View\PageTreeView;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\View\BackendTemplateView;
use TYPO3\CMS\Core\Database\DatabaseConnection;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Mvc\View\ViewInterface;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Backend module page permissions
 */
class CustomPermissionController extends \TYPO3\CMS\Beuser\Controller\PermissionController
{
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
        $this->view->assign('currentBeGroup', $this->pageInfo['perms_groupid']);
        $this->view->assign('beGroupData', $beGroupDataArray);
        $this->view->assign('pageInfo', $this->pageInfo);
        $this->view->assign('returnId', $this->returnId);
        $this->view->assign('recursiveSelectOptions', $this->getRecursiveSelectOptions());
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
}
