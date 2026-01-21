<?php

use App\Library\OdooClient;

class OdooTestController extends ControllerBase
{
    public function indexAction()
    {
        $results = [];
        
        try {
            $odoo = new OdooClient();
            $results['connection'] = 'OK - OdooClient created';
            
            // Test authenticate
            try {
                $uid = $odoo->authenticate();
                $results['authenticate'] = 'OK - UID: ' . print_r($uid, true);
            } catch (\Exception $e) {
                $results['authenticate'] = 'ERROR: ' . $e->getMessage();
            }
            
            // Test get equipments
            try {
                // Test with simple search first
                $simpleSearch = $odoo->executePublic('equipment.rental', 'search', [[]]);
                $results['simple_search_ids'] = 'IDs: ' . print_r($simpleSearch, true);
                
                $equipments = $odoo->getAvailableEquipments();
                $results['available_equipments'] = 'Count: ' . count($equipments ?: []) . ' | Data: ' . print_r($equipments, true);
                
                // Try get ALL equipments (no filter)
                $allEquipments = $odoo->getEquipments([]);
                $results['all_equipments'] = 'Count: ' . count($allEquipments ?: []) . ' | Data: ' . print_r($allEquipments, true);
            } catch (\Exception $e) {
                $results['equipments'] = 'ERROR: ' . $e->getMessage();
            }
            
        } catch (\Exception $e) {
            $results['error'] = 'FATAL: ' . $e->getMessage();
        }
        
        $this->view->results = $results;
    }
}
