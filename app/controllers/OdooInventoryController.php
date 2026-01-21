<?php

use App\Library\OdooClient;

class OdooInventoryController extends OdooControllerBase
{

    /**
     * List products/inventory
     */
    public function indexAction()
    {
        try {
            $products = $this->odoo->executePublic('product.product', 'search_read', 
                [[]], // args: domain
                ['fields' => ['name', 'default_code', 'qty_available', 'list_price', 'categ_id']] // kwargs
            );
            
            $this->view->products = $products ?: [];
            $this->view->title = "Inventory - Products";
        } catch (\Exception $e) {
            $this->flash->error("Error: " . $e->getMessage());
            $this->view->products = [];
        }
    }
    
    /**
     * Stock movements
     */
    public function movementsAction()
    {
        try {
            $movements = $this->odoo->executePublic('stock.move', 'search_read',
                [[['state', '=', 'done']]], // args: domain
                ['fields' => ['name', 'product_id', 'product_uom_qty', 'location_id', 'location_dest_id', 'date', 'state'], 'limit' => 50] // kwargs
            );
            
            $this->view->movements = $movements ?: [];
            $this->view->title = "Stock Movements";
        } catch (\Exception $e) {
            $this->flash->error("Error: " . $e->getMessage());
            $this->view->movements = [];
        }
    }
    
    /**
     * View product detail
     */
    public function viewAction($id)
    {
        try {
            $products = $this->odoo->executePublic('product.product', 'search_read',
                [[['id', '=', (int)$id]]], // args: domain
                ['fields' => ['name', 'default_code', 'qty_available', 'list_price', 'standard_price', 'categ_id', 'type', 'description', 'barcode', 'uom_id']] // kwargs
            );
            
            if (!empty($products)) {
                $this->view->product = $products[0];
                $this->view->title = "Product Detail - " . $products[0]['name'];
            } else {
                $this->flash->error("Product not found");
                return $this->response->redirect('odoo-inventory');
            }
        } catch (\Exception $e) {
            $this->flash->error("Error: " . $e->getMessage());
            return $this->response->redirect('odoo-inventory');
        }
    }
    
    /**
     * Create product
     */
    public function createProductAction()
    {
        if ($this->request->isPost()) {
            try {
                $data = [
                    'name' => $this->request->getPost('name'),
                    'default_code' => $this->request->getPost('code'),
                    'list_price' => (float)$this->request->getPost('price'),
                    'type' => 'product'
                ];
                
                $productId = $this->odoo->executePublic('product.product', 'create', [[$data]]);
                
                if ($productId) {
                    $this->flash->success("Product created with ID: $productId");
                    return $this->response->redirect('odoo-inventory');
                }
            } catch (\Exception $e) {
                $this->flash->error("Error: " . $e->getMessage());
            }
        }
        
        $this->view->title = "Create Product";
    }
    
    /**
     * Edit product
     */
    public function editAction($id)
    {
        if ($this->request->isPost()) {
            try {
                $data = [];
                
                if ($this->request->getPost('name')) {
                    $data['name'] = $this->request->getPost('name');
                }
                if ($this->request->getPost('code')) {
                    $data['default_code'] = $this->request->getPost('code');
                }
                if ($this->request->getPost('barcode')) {
                    $data['barcode'] = $this->request->getPost('barcode');
                }
                if ($this->request->getPost('price')) {
                    $data['list_price'] = (float)$this->request->getPost('price');
                }
                if ($this->request->getPost('cost')) {
                    $data['standard_price'] = (float)$this->request->getPost('cost');
                }
                if ($this->request->getPost('description')) {
                    $data['description'] = $this->request->getPost('description');
                }
                if ($this->request->getPost('type')) {
                    $data['type'] = $this->request->getPost('type');
                }
                
                $result = $this->odoo->executePublic('product.product', 'write', [[(int)$id], $data]);
                
                if ($result) {
                    $this->flash->success("Produk berhasil diupdate!");
                    return $this->response->redirect('odoo-inventory/view/' . $id);
                }
            } catch (\Exception $e) {
                $this->flash->error("Error: " . $e->getMessage());
            }
        }
        
        // Get product data for form
        try {
            $products = $this->odoo->executePublic('product.product', 'search_read',
                [[['id', '=', (int)$id]]],
                ['fields' => ['name', 'default_code', 'barcode', 'list_price', 'standard_price', 'type', 'description', 'categ_id']]
            );
            
            if (!empty($products)) {
                $this->view->product = $products[0];
                $this->view->title = "Edit Produk - " . $products[0]['name'];
            } else {
                $this->flash->error("Produk tidak ditemukan");
                return $this->response->redirect('odoo-inventory');
            }
        } catch (\Exception $e) {
            $this->flash->error("Error: " . $e->getMessage());
            return $this->response->redirect('odoo-inventory');
        }
    }
    
    /**
     * Update stock quantity
     */
    public function updateStockAction($id)
    {
        if ($this->request->isPost()) {
            try {
                $newQty = (float)$this->request->getPost('quantity');
                $locationId = 8; // Stock location
                
                // Get current stock quant
                $quants = $this->odoo->executePublic('stock.quant', 'search_read',
                    [[['product_id', '=', (int)$id], ['location_id', '=', $locationId]]],
                    ['fields' => ['id', 'quantity']]
                );
                
                if (!empty($quants)) {
                    // Update existing quant
                    $result = $this->odoo->executePublic('stock.quant', 'write', 
                        [[$quants[0]['id']], ['quantity' => $newQty]]
                    );
                } else {
                    // Create new quant
                    $result = $this->odoo->executePublic('stock.quant', 'create', [[
                        'product_id' => (int)$id,
                        'location_id' => $locationId,
                        'quantity' => $newQty,
                        'reserved_quantity' => 0,
                        'in_date' => date('Y-m-d H:i:s')
                    ]]);
                }
                
                if ($result) {
                    $this->flash->success("Stok berhasil diupdate menjadi $newQty unit!");
                    return $this->response->redirect('odoo-inventory/view/' . $id);
                }
            } catch (\Exception $e) {
                $this->flash->error("Error: " . $e->getMessage());
            }
        }
        
        // Get product data
        try {
            $products = $this->odoo->executePublic('product.product', 'search_read',
                [[['id', '=', (int)$id]]],
                ['fields' => ['name', 'default_code', 'qty_available']]
            );
            
            if (!empty($products)) {
                $this->view->product = $products[0];
                $this->view->title = "Update Stok - " . $products[0]['name'];
            } else {
                $this->flash->error("Produk tidak ditemukan");
                return $this->response->redirect('odoo-inventory');
            }
        } catch (\Exception $e) {
            $this->flash->error("Error: " . $e->getMessage());
            return $this->response->redirect('odoo-inventory');
        }
    }
}
