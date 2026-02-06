<?php

namespace App\Controllers;

use App\Library\OdooClient;

class OdooPurchaseController extends OdooControllerBase
{
    /**
     * List purchase orders
     */
    public function indexAction()
    {
        try {
            $orders = $this->odoo->executePublic('purchase.order', 'search_read',
                [[]], // Query all POs, no state filter
                ['fields' => ['name', 'partner_id', 'date_order', 'amount_total', 'state', 'order_line']] // kwargs
            );

            // Load local fallback actions
            $filePath = __DIR__ . '/../../public/files/po_actions.json';
            $processedLocal = file_exists($filePath) ? json_decode(file_get_contents($filePath), true) : [];
            $processedMap = [];
            if (is_array($processedLocal)) {
                foreach ($processedLocal as $p) {
                    if (!empty($p['po_id'])) $processedMap[(int)$p['po_id']] = $p;
                }
            }

            // Process orders to add product info and local_action, skip deleted ones
            $processedOrders = [];
            foreach ($orders as $order) {
                $local = $processedMap[(int)($order['id'] ?? 0)] ?? null;
                // Skip orders that were marked deleted locally
                if ($local && !empty($local['action']) && strtolower($local['action']) === 'deleted') {
                    continue;
                }
                $order['local_action'] = $local;
                $order['products'] = [];
                if (!empty($order['order_line'])) {
                    try {
                        $lines = $this->odoo->executePublic('purchase.order.line', 'read', [
                            $order['order_line'],
                            ['product_id', 'name']
                        ]);
                        $productNames = [];
                        foreach ($lines as $line) {
                            if (is_array($line['product_id'])) {
                                $productNames[] = $line['product_id'][1];
                            } else {
                                $productNames[] = $line['name'];
                            }
                        }
                        $order['products'] = array_unique($productNames);
                    } catch (\Exception $e) {
                        // Skip if error
                    }
                }
                $processedOrders[] = $order;
            }

            $this->view->orders = $processedOrders ?: [];
            $this->view->title = "Purchase Orders";
        } catch (\Exception $e) {
            $this->flash->error("Error: " . $e->getMessage());
            $this->view->orders = [];
        }
    }
    
    /**
     * View purchase order details
     */
    public function viewAction($id = null)
    {
        if (!$id) {
            return $this->response->redirect('odoo-purchase');
        }
        
        try {
            $order = $this->odoo->executePublic('purchase.order', 'read', [
                (int)$id,
                ['id', 'name', 'partner_id', 'date_order', 'amount_total', 'state', 'order_line']
            ]);
            
            if (empty($order)) {
                throw new \Exception("Purchase Order not found");
            }
            
            $this->view->order = $order[0];
            
            // Get order lines
            if (!empty($order[0]['order_line'])) {
                $lines = $this->odoo->executePublic('purchase.order.line', 'read', [
                    $order[0]['order_line'],
                    ['id', 'product_id', 'product_qty', 'price_unit', 'name', 'price_subtotal']
                ]);
                $this->view->lines = $lines ?: [];
            } else {
                $this->view->lines = [];
            }
            
            $this->view->title = "Detail PO: " . $order[0]['name'];
        } catch (\Exception $e) {
            $this->flash->error("Error: " . $e->getMessage());
            return $this->response->redirect('odoo-purchase');
        }
    }
    public function createAction()
    {
        if ($this->request->isPost()) {
            try {
                // Validate Odoo client
                if (!$this->odoo) {
                    throw new \Exception("Odoo client not initialized. Check connection settings.");
                }
                
                $supplierId = (int)$this->request->getPost('supplier_id');
                $productId = $this->request->getPost('product_id');
                $quantity = (float)$this->request->getPost('quantity') ?: 1;
                $price = (float)$this->request->getPost('price') ?: 0;
                
                error_log("Creating PO: supplierId=$supplierId, productId=$productId, qty=$quantity, price=$price");
                
                // Validate inputs
                if (!$supplierId) {
                    throw new \Exception("Please select a supplier (supplier_id required)");
                }
                if (!$productId) {
                    throw new \Exception("Please select a product (product_id required)");
                }
                
                // Get supplier from local database
                $user = \App\Models\User::findFirst($supplierId);
                if (!$user) {
                    throw new \Exception("Supplier not found in local database");
                }
                $supplierName = $user->name;
                
                // Find partner in Odoo by name
                $partners = $this->odoo->executePublic('res.partner', 'search_read',
                    [[['name', '=', $supplierName]]],
                    ['fields' => ['id', 'name']]
                );
                if (empty($partners)) {
                    // Create new partner
                    $partnerData = [
                        'name' => $supplierName,
                        'email' => $user->email,
                        'supplier_rank' => 1
                    ];
                    $partnerId = $this->odoo->executePublic('res.partner', 'create', [[$partnerData]]);
                    $partnerId = is_array($partnerId) ? $partnerId[0] : $partnerId;
                    error_log("Created new partner with ID: $partnerId");
                } else {
                    $partnerId = $partners[0]['id'];
                }
                
                // Get product from local inventory
                $inventoryItem = \App\Models\Inventory::findFirst($productId);
                if (!$inventoryItem) {
                    throw new \Exception("Product not found in local inventory");
                }
                $productName = $inventoryItem->name;
                $productPrice = ($inventoryItem->selling_price ?? 0) ?: $price;
                
                // Find product in Odoo by name
                $odooProducts = $this->odoo->executePublic('product.product', 'search_read',
                    [[['name', '=', $productName]]],
                    ['fields' => ['id', 'name']]
                );
                if (empty($odooProducts)) {
                    // Create new product
                    $productData = [
                        'name' => $productName,
                        'type' => 'product',
                        'standard_price' => $productPrice
                    ];
                    $odooProductId = $this->odoo->executePublic('product.product', 'create', [[$productData]]);
                    $odooProductId = is_array($odooProductId) ? $odooProductId[0] : $odooProductId;
                    error_log("Created new product with ID: $odooProductId");
                } else {
                    $odooProductId = $odooProducts[0]['id'];
                }
                
                // Create PO header
                $poData = [
                    'partner_id' => $partnerId,
                    'date_order' => date('Y-m-d H:i:s')
                ];
                
                error_log("Creating purchase order with data: " . json_encode($poData));
                $orderId = $this->odoo->executePublic('purchase.order', 'create', [[$poData]]);
                $orderId = is_array($orderId) ? $orderId[0] : $orderId;
                error_log("Purchase order created with ID: $orderId");
                
                if ($orderId) {
                    // Create PO Line
                    $lineData = [
                        'order_id' => $orderId,
                        'product_id' => (int)$odooProductId,
                        'product_qty' => $quantity,
                        'price_unit' => $productPrice,
                        'name' => $productName,
                        'date_planned' => date('Y-m-d H:i:s')
                    ];
                    
                    error_log("Creating PO line with data: " . json_encode($lineData));
                    try {
                        $this->odoo->executePublic('purchase.order.line', 'create', [[$lineData]]);
                        error_log("PO line created successfully");
                    } catch (\Exception $lineError) {
                        error_log("Warning: Could not create PO line: " . $lineError->getMessage());
                    }
                }
                
                if ($orderId) {
                    // If AJAX request, return JSON
                    if ($this->request->isAjax()) {
                        try {
                            $order = $this->odoo->executePublic('purchase.order', 'read', [
                                $orderId,
                                ['id', 'name', 'partner_id', 'date_order', 'amount_total', 'state']
                            ]);
                            return $this->response->setJsonContent(['success' => true, 'id' => $orderId, 'order' => $order[0] ?? null]);
                        } catch (\Exception $readError) {
                            error_log("Warning: Could not read created PO: " . $readError->getMessage());
                            return $this->response->setJsonContent(['success' => true, 'id' => $orderId, 'order' => null]);
                        }
                    }
                    $this->flash->success("✅ Purchase Order berhasil dibuat dengan ID: $orderId");
                    return $this->response->redirect('odoo-purchase');
                }
            } catch (\Exception $e) {
                error_log("Error creating PO: " . $e->getMessage() . "\n" . $e->getTraceAsString());
                if ($this->request->isAjax()) {
                    header('Content-Type: application/json');
                    return $this->response->setJsonContent(['success' => false, 'error' => $e->getMessage()]);
                }
                $this->flash->error("❌ Error: " . $e->getMessage());
            }
        }
        
        // Get suppliers from local users table (filter to suppliers only)
        try {
            $db = new \PDO(
                "mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']};charset=utf8",
                $_ENV['DB_USERNAME'],
                $_ENV['DB_PASSWORD']
            );
            $stmt = $db->query("SELECT id, name, email FROM users WHERE is_supplier = 1 ORDER BY id DESC");
            $suppliers = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $this->view->suppliers = $suppliers ?: [];
        } catch (\Exception $e) {
            $this->view->suppliers = [];
        }
        
        // Get products from local inventory table
        try {
            $db = new \PDO(
                "mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']};charset=utf8",
                $_ENV['DB_USERNAME'],
                $_ENV['DB_PASSWORD']
            );
            $stmt = $db->query("SELECT id, name, price FROM inventory");
            $products = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $this->view->products = $products ?: [];
        } catch (\Exception $e) {
            $this->view->products = [];
        }
        
        $this->view->title = "Buat Purchase Order";
    }
    
    /**
     * Create supplier/partner
     */
    public function createSupplierAction()
    {
        if ($this->request->isPost()) {
            try {
                $name = trim($this->request->getPost('name'));
                $email = trim($this->request->getPost('email'));
                $phone = trim($this->request->getPost('phone'));
                $street = trim($this->request->getPost('street'));
                $city = trim($this->request->getPost('city'));

                if (!$name) {
                    throw new \Exception('Nama supplier wajib diisi');
                }

                // Try create in Odoo first (best-effort)
                $supplierData = [
                    'name' => $name,
                    'supplier_rank' => 1,
                ];

                if ($email) $supplierData['email'] = $email;
                if ($phone) $supplierData['phone'] = $phone;
                if ($street) $supplierData['street'] = $street;
                if ($city) $supplierData['city'] = $city;

                $odooPartnerId = null;
                try {
                    $odooPartnerId = $this->odoo->executePublic('res.partner', 'create', [[$supplierData]]);
                    $odooPartnerId = is_array($odooPartnerId) ? $odooPartnerId[0] : $odooPartnerId;
                } catch (\Exception $e) {
                    // Log and continue — local DB still important
                    error_log("Warning: Could not create partner in Odoo: " . $e->getMessage());
                }

                // Insert supplier into local users table so it appears in supplier select
                try {
                    $db = new \PDO(
                        "mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']};charset=utf8",
                        $_ENV['DB_USERNAME'],
                        $_ENV['DB_PASSWORD']
                    );
                    $stmt = $db->prepare("INSERT INTO users (name, email) VALUES (:name, :email)");
                    $stmt->execute([':name' => $name, ':email' => $email]);
                    $localId = (int)$db->lastInsertId();
                } catch (\Exception $dbErr) {
                    throw new \Exception("Failed to save supplier locally: " . $dbErr->getMessage());
                }

                // If request is AJAX, return the local ID so the modal can select it
                if ($this->request->isAjax()) {
                    return $this->response->setJsonContent([
                        'success' => true,
                        'id' => $localId,
                        'name' => $name,
                        'odoo_partner_id' => $odooPartnerId
                    ]);
                }

                $this->flash->success("✅ Supplier berhasil dibuat (local id: $localId)");
                return $this->response->redirect('odoo-purchase/create');
            } catch (\Exception $e) {
                if ($this->request->isAjax()) {
                    return $this->response->setJsonContent(['success' => false, 'error' => $e->getMessage()]);
                }

                $this->flash->error("Error: " . $e->getMessage());
            }
        }

        $this->view->title = "Tambah Supplier";
    }
}
