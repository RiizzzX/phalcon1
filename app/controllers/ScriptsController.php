<?php

namespace App\Controllers;

use App\Library\OdooClient;

class ScriptsController extends ControllerBase
{
    /**
     * Endpoint: /scripts/test_odoo.php and /scripts/test-odoo
     * Returns JSON with connection diagnostics
     */
    public function testOdooAction()
    {
        $this->view->disable();

        try {
            $client = new OdooClient();
            $result = $client->testConnection();
            return $this->response->setJsonContent($result);
        } catch (\Exception $e) {
            return $this->response->setJsonContent(['success' => false, 'error' => $e->getMessage()]);
        }
    }
}
