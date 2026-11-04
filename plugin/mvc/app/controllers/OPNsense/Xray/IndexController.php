<?php

namespace OPNsense\Xray;

/**
 * Renders the main GUI page.
 * Passes both forms to the volt template.
 * Route: /ui/xray/  (matches Menu.xml url)
 */
class IndexController extends \OPNsense\Base\IndexController
{
    public function indexAction()
    {
        $this->view->generalForm  = $this->getForm('general');
        $this->view->instanceForm = $this->getForm('instance');
        $this->view->groupForm    = $this->getForm('group');
        $this->view->serverForm   = $this->getForm('server');
        $this->view->ruleForm     = $this->getForm('rule');
        $this->view->pick('OPNsense/Xray/general');
    }
}
