<?php

use App\Library\OdooModuleInstaller;

class OdooDashboardController extends OdooControllerBase
{
    public function indexAction()
    {
        // Remove debug echos, set title and try to fetch a few equipments to show connection status
        $this->view->title = "Odoo Integration Dashboard";

        if ($this->odoo) {
            try {
                $equipments = $this->odoo->getEquipments([], 0);
                $this->view->setVar('odooEquipments', $equipments);
            } catch (\Exception $e) {
                // non-fatal: show message on view
                $this->view->setVar('odooError', 'Odoo fetch failed: ' . $e->getMessage());
                $this->view->setVar('odooEquipments', []);
            }
        } else {
            $this->view->setVar('odooEquipments', []);
        }
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
