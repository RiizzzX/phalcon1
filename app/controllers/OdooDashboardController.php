<?php

use App\Library\OdooModuleInstaller;

class OdooDashboardController extends OdooControllerBase
{
    public function indexAction()
    {
        $this->view->title = "Odoo Integration Dashboard";
    }
    
    /**
     * Install required modules
     */
    public function installModulesAction()
    {
        $installer = new OdooModuleInstaller();
        $results = $installer->installRequiredModules();
        
        $this->view->results = $results;
        $this->view->title = "Module Installation Results";
    }
}
