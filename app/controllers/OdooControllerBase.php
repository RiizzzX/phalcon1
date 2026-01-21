<?php

use Phalcon\Mvc\Controller;
use App\Library\OdooClient;

class OdooControllerBase extends Controller
{
    protected $odoo;
    
    public function initialize()
    {
        // Initialize Odoo client untuk semua child controllers
        $this->odoo = new OdooClient();
        
        // Set views directory untuk semua Odoo controllers
        // Controller OdooInventory -> views/odoo/inventory/
        // Controller OdooPurchase -> views/odoo/purchase/
        $controllerName = $this->dispatcher->getControllerName();
        
        // Convert dari 'odooPurchase' -> 'purchase', 'odooSales' -> 'sales'
        if (strpos($controllerName, 'odoo') === 0) {
            $viewFolder = strtolower(substr($controllerName, 4)); // Remove 'odoo' prefix
            $this->view->setViewsDir($this->view->getViewsDir() . 'odoo/' . $viewFolder . '/');
        }
    }
}
