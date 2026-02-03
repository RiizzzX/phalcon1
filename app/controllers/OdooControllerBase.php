<?php

namespace App\Controllers;

use Phalcon\Mvc\Controller;
use App\Library\OdooClient;

class OdooControllerBase extends ControllerBase
{
    protected $odoo;
    
    public function initialize()
    {
        // Log environment for debugging
        error_log("OdooControllerBase initialize - ODOO_URL: " . getenv('ODOO_URL'));
        error_log("OdooControllerBase initialize - ODOO_DB: " . getenv('ODOO_DB'));
        error_log("OdooControllerBase initialize - ODOO_USERNAME: " . getenv('ODOO_USERNAME'));
        
        try {
            // Initialize Odoo client for all Odoo controllers
            $this->odoo = new OdooClient();

            // Try a quick authentication to verify connection (non-fatal)
            try {
                $result = $this->odoo->authenticate();
                error_log("Odoo auth successful, UID: " . json_encode($result));
                $this->view->setVar('odooError', null);
            } catch (\Exception $e) {
                error_log("Odoo auth failed: " . $e->getMessage() . "\n" . $e->getTraceAsString());
                $this->view->setVar('odooError', 'Odoo connection failed: ' . $e->getMessage());
                // keep $this->odoo instance (may still be usable for admin actions after Odoo is ready)
            }
        } catch (\Exception $e) {
            // Log and fallback
            error_log("Odoo Client Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            $this->odoo = null;
            $this->view->setVar('odooError', 'Odoo client init failed: ' . $e->getMessage());
        }
        
        // Set views directory untuk semua Odoo controllers
        // Controller OdooInventory -> views/odoo/inventory/
        // Controller OdooPurchase -> views/odoo/purchase/
        $controllerName = $this->dispatcher->getControllerName();
        
        // Normalize controller name and derive view folder. Handle cases:
        // - "odooDashboard" -> "dashboard"
        // - "odoo-dashboard" -> "dashboard"
        // - "odoo_dashboard" -> "dashboard"
        if (strpos(strtolower($controllerName), 'odoo') === 0) {
            // Remove prefix 'odoo' and optional separators
            $viewFolder = preg_replace('/^odoo[-_]?/i', '', $controllerName);
            // If camelCase, convert to kebab and then to lowercase folder name
            // e.g., 'Dashboard' or 'dashBoard' -> 'dashboard'
            $viewFolder = strtolower(preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $viewFolder));
            $viewFolder = str_replace('-', '_', $viewFolder);
            $viewFolder = trim($viewFolder, '_');
            $this->view->setViewsDir($this->view->getViewsDir() . 'odoo/' . $viewFolder . '/');
            
            // Disable layout template - render only action view
            $this->view->setTemplateAfter([]);
            $this->view->setTemplateBefore([]);
        }
    }
}
