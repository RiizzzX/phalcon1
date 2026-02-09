<?php

namespace App\Controllers;

use App\Library\OdooModuleInstaller;

class OdooDashboardController extends OdooControllerBase
{
    public function initialize()
    {
        parent::initialize();
    }

    public function indexAction()
    {
        // Dashboard for Odoo Integration
        $this->view->title = "Odoo Integration Dashboard";
        $this->view->setVar('odooError', null);
        $this->view->pick('index');
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
