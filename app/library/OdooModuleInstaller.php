<?php

namespace App\Library;

/**
 * Odoo Module Installer
 * Helper untuk install dan manage Odoo modules
 */
class OdooModuleInstaller
{
    private $client;
    
    public function __construct()
    {
        $this->client = new OdooClient();
    }
    
    /**
     * Install module by name
     */
    public function installModule($moduleName)
    {
        try {
            // Authenticate first
            $this->client->authenticate();
            
            // Search for module
            $moduleId = $this->client->executePublic('ir.module.module', 'search', [
                [['name', '=', $moduleName]]
            ]);
            
            if (empty($moduleId)) {
                return ['success' => false, 'message' => "Module $moduleName not found"];
            }
            
            // Install module
            $result = $this->client->executePublic('ir.module.module', 'button_immediate_install', [
                $moduleId
            ]);
            
            return ['success' => true, 'message' => "Module $moduleName installed successfully"];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Get installed modules
     */
    public function getInstalledModules()
    {
        try {
            $modules = $this->client->executePublic('ir.module.module', 'search_read', [
                [['state', '=', 'installed']],
                ['name', 'summary', 'installed_version']
            ]);
            
            return $modules;
        } catch (\Exception $e) {
            return [];
        }
    }
    
    /**
     * Install required modules for this project
     */
    public function installRequiredModules()
    {
        $requiredModules = [
            'purchase' => 'Purchase Management',
            'stock' => 'Inventory Management',
            'sale_management' => 'Sales Management',
            'account' => 'Invoicing & Accounting'
        ];
        
        $results = [];
        
        foreach ($requiredModules as $module => $description) {
            $results[$module] = $this->installModule($module);
        }
        
        return $results;
    }
}
