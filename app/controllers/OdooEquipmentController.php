<?php

use App\Library\OdooClient;

class OdooEquipmentController extends OdooControllerBase
{
    /**
     * Tampilkan daftar equipment dari Odoo
     */
    public function indexAction()
    {
        try {
            $odoo = new OdooClient();
            
            // Ambil SEMUA equipment (tanpa filter)
            $equipments = $odoo->getEquipments();
            
            $this->view->equipments = $equipments ?: [];
            $this->view->title = "Equipment dari Odoo";
            $this->view->debug = "Total equipment: " . count($equipments ?: []) . " | Routes working!";
        } catch (\Exception $e) {
            $this->flash->error("Error connecting to Odoo: " . $e->getMessage());
            $this->view->equipments = [];
            $this->view->title = "Equipment dari Odoo";
            $this->view->debug = "Error: " . $e->getMessage();
        }
    }

    /**
     * Detail equipment
     */
    public function viewAction($id)
    {
        try {
            $odoo = new OdooClient();
            $equipment = $odoo->getEquipment($id);
            
            if (!$equipment) {
                $this->flash->error("Equipment tidak ditemukan");
                return $this->response->redirect('odoo-equipment');
            }
            
            $this->view->equipment = $equipment;
            $this->view->title = "Detail Equipment";
        } catch (Exception $e) {
            $this->flash->error("Error: " . $e->getMessage());
            return $this->response->redirect('odoo-equipment');
        }
    }

    /**
     * Create new equipment
     */
    public function createAction()
    {
        if ($this->request->isPost()) {
            try {
                $odoo = new OdooClient();
                
                $data = [
                    'name' => $this->request->getPost('name'),
                    'code' => $this->request->getPost('code'),
                    'category' => $this->request->getPost('category'),
                    'daily_rate' => (float)$this->request->getPost('daily_rate'),
                    'condition' => $this->request->getPost('condition'),
                    'status' => $this->request->getPost('status'),
                    'description' => $this->request->getPost('description')
                ];
                
                $equipmentId = $odoo->createEquipment($data);
                
                if ($equipmentId) {
                    $this->flash->success("Equipment berhasil dibuat dengan ID: $equipmentId");
                    return $this->response->redirect('odoo-equipment');
                } else {
                    $this->flash->error("Gagal membuat equipment");
                }
            } catch (\Exception $e) {
                $this->flash->error("Error: " . $e->getMessage());
            }
        }
        
        $this->view->title = "Create Equipment";
    }

    /**
     * Form rental equipment
     */
    public function rentAction($id = null)
    {
        // Debug mode
        $debug = [];
        
        try {
            $odoo = new OdooClient();
            
            // Get ID from parameter or dispatcher
            if (!$id) {
                $id = $this->dispatcher->getParam('id');
            }
            
            $debug[] = "ID received: " . ($id ?: 'NULL');
            
            if (!$id) {
                $debug[] = "ERROR: No ID found";
                $this->view->debug = implode(' | ', $debug);
                $this->flash->error("Equipment ID tidak ditemukan. Debug: " . implode(', ', $debug));
                return $this->response->redirect('odoo-equipment');
            }
            
            $debug[] = "Fetching equipment with ID: $id";
            $equipment = $odoo->getEquipment($id);
            
            $debug[] = "Equipment result: " . ($equipment ? 'Found' : 'Not Found');
            
            if (!$equipment) {
                $this->view->debug = implode(' | ', $debug);
                $this->flash->error("Equipment tidak ditemukan. Debug: " . implode(', ', $debug));
                return $this->response->redirect('odoo-equipment');
            }
            
            $debug[] = "Equipment loaded: " . $equipment['name'];
            
            if ($this->request->isPost()) {
                $debug[] = "Processing POST request";
                
                // Create rental transaction
                $rentalData = [
                    'customer_name' => $this->request->getPost('customer_name'),
                    'customer_phone' => $this->request->getPost('customer_phone'),
                    'customer_email' => $this->request->getPost('customer_email'),
                    'equipment_id' => $id,
                    'rental_date' => $this->request->getPost('rental_date'),
                    'return_date' => $this->request->getPost('return_date'),
                    'deposit' => floatval($this->request->getPost('deposit', 'float')),
                    'notes' => $this->request->getPost('notes')
                ];
                
                $rentalId = $odoo->createRental($rentalData);
                
                if ($rentalId) {
                    // Auto confirm
                    $odoo->confirmRental($rentalId);
                    
                    // Sync to MySQL database
                    try {
                        $rentalInfo = $odoo->getRentals([['id', '=', $rentalId]]);
                        if (!empty($rentalInfo)) {
                            RentalLog::syncFromOdoo($rentalInfo[0]);
                        }
                    } catch (\Exception $syncError) {
                        error_log("Failed to sync rental to MySQL: " . $syncError->getMessage());
                    }
                    
                    $this->flash->success("Rental berhasil dibuat! Rental ID: RNT/" . str_pad($rentalId, 5, '0', STR_PAD_LEFT));
                    return $this->response->redirect('odoo-equipment');
                } else {
                    $this->flash->error("Gagal membuat rental");
                }
            }
            
            $this->view->equipment = $equipment;
            $this->view->title = "Rental Equipment";
            $this->view->debug = implode(' | ', $debug);
        } catch (Exception $e) {
            $this->flash->error("Error: " . $e->getMessage());
            $this->view->debug = "Exception: " . $e->getMessage();
            return $this->response->redirect('odoo-equipment');
        }
    }

    /**
     * Daftar rental transactions
     */
    public function rentalsAction()
    {
        try {
            $odoo = new OdooClient();
            $rentals = $odoo->getRentals();
            
            // Sync semua rentals ke MySQL
            foreach ($rentals as $rental) {
                try {
                    RentalLog::syncFromOdoo($rental);
                } catch (\Exception $e) {
                    error_log("Sync error for rental {$rental['id']}: " . $e->getMessage());
                }
            }
            
            $this->view->rentals = $rentals;
            $this->view->title = "Rental Transactions";
        } catch (Exception $e) {
            $this->flash->error("Error: " . $e->getMessage());
            $this->view->rentals = [];
        }
    }
    
    /**
     * Rental logs dari MySQL database
     */
    public function logsAction()
    {
        $this->view->logs = RentalLog::getAllRentals();
        $this->view->title = "Rental Logs (MySQL)";
    }
}
