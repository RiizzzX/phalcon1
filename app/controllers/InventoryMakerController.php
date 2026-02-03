<?php

namespace App\Controllers;

use App\Models\Inventory;
use App\Library\OdooClient;

class InventoryMakerController extends ControllerBase
{
    /**
     * Dashboard - List products from LOCAL database
     */
    public function listAction()
    {
        try {
            $page = (int)($this->request->getQuery('page') ?? 1);
            $limit = 20;
            $offset = ($page - 1) * $limit;
            
            // Get from LOCAL database
            $products = Inventory::find([
                'order' => 'id DESC',
                'limit' => $limit,
                'offset' => $offset
            ]);
            
            $total = Inventory::count();
            $totalPages = ceil($total / $limit);
            
            $this->view->products = $products;
            $this->view->page = $page;
            $this->view->totalPages = $totalPages;
            $this->view->total = $total;
            $this->view->title = "Inventory List";
            
        } catch (\Exception $e) {
            $this->flash->error("Error: " . $e->getMessage());
            $this->view->products = [];
        }
    }
    
    /**
     * Add product to local database
     */
    public function addAction()
    {
        if ($this->request->isPost()) {
            try {
                $inventory = new Inventory();
                $inventory->name = $this->request->getPost('name');
                $inventory->category = $this->request->getPost('category');
                $inventory->quantity = (int)$this->request->getPost('quantity', 0);
                $inventory->selling_price = (float)$this->request->getPost('selling_price', 0);
                $inventory->description = $this->request->getPost('description');
                $inventory->sku = $this->request->getPost('sku');
                $inventory->product_type = $this->request->getPost('product_type', 'consu');
                $inventory->cost_price = (float)$this->request->getPost('cost_price', 0);
                $inventory->status = 'active';
                $inventory->synced_to_odoo = 0;
                
                if ($inventory->save()) {
                    // Try to sync to Odoo
                    $this->syncToOdoo($inventory);
                    
                    $this->flash->success("✓ Product created successfully!");
                    return $this->response->redirect('inventory-maker/list');
                } else {
                    $msgs = [];
                    foreach ($inventory->getMessages() as $msg) {
                        $msgs[] = $msg->getMessage();
                    }
                    $this->flash->error("Error: " . implode(", ", $msgs));
                }
            } catch (\Exception $e) {
                $this->flash->error("Error creating product: " . $e->getMessage());
            }
        }
        
        $this->view->title = "Add Product";
    }
    
    /**
     * Edit product
     */
    public function editAction($id)
    {
        $product = Inventory::findFirstById($id);
        
        if (!$product) {
            $this->flash->error("Product not found");
            return $this->response->redirect('inventory-maker/list');
        }
        
        if ($this->request->isPost()) {
            try {
                $product->name = $this->request->getPost('name');
                $product->category = $this->request->getPost('category');
                $product->selling_price = (float)$this->request->getPost('selling_price', 0);
                $product->description = $this->request->getPost('description');
                $product->sku = $this->request->getPost('sku');
                $product->product_type = $this->request->getPost('product_type');
                $product->cost_price = (float)$this->request->getPost('cost_price', 0);
                $product->synced_to_odoo = 0;
                
                if ($product->save()) {
                    $this->syncToOdoo($product);
                    $this->flash->success("✓ Product updated successfully!");
                    return $this->response->redirect('inventory-maker/list');
                } else {
                    $msgs = [];
                    foreach ($product->getMessages() as $msg) {
                        $msgs[] = $msg->getMessage();
                    }
                    $this->flash->error("Error: " . implode(", ", $msgs));
                }
            } catch (\Exception $e) {
                $this->flash->error("Error: " . $e->getMessage());
            }
        }
        
        $this->view->product = $product;
        $this->view->title = "Edit Product";
    }
    
    /**
     * Update stock quantity
     */
    public function updateStockAction($id)
    {
        $product = Inventory::findFirstById($id);
        
        if (!$product) {
            $this->flash->error("Product not found");
            return $this->response->redirect('inventory-maker/list');
        }
        
        if ($this->request->isPost()) {
            try {
                $oldQty = $product->quantity;
                $newQty = (int)$this->request->getPost('quantity', 0);
                $reason = $this->request->getPost('reason', 'Manual adjustment');
                
                $product->quantity = max(0, $newQty); // Prevent negative
                $product->synced_to_odoo = 0;
                
                if ($product->save()) {
                    $this->syncToOdoo($product);
                    
                    $this->flash->success("✓ Stock updated: {$oldQty} → {$newQty}");
                    return $this->response->redirect('inventory-maker/list');
                }
            } catch (\Exception $e) {
                $this->flash->error("Error updating stock: " . $e->getMessage());
            }
        }
        
        $this->view->product = $product;
        $this->view->title = "Update Stock";
    }
    
    /**
     * Delete product
     */
    public function deleteAction($id)
    {
        $product = Inventory::findFirstById($id);
        
        if (!$product) {
            $this->flash->error("Product not found");
            return $this->response->redirect('inventory-maker/list');
        }
        
        try {
            // Try to delete from Odoo first
            if ($product->odoo_id) {
                try {
                    $this->odoo->execute('product.product', 'unlink', [[$product->odoo_id]]);
                } catch (\Exception $e) {
                    // Log but continue with local delete
                    error_log("Failed to delete from Odoo: " . $e->getMessage());
                }
            }
            
            // Delete from local DB
            if ($product->delete()) {
                $this->flash->success("✓ Product deleted");
            } else {
                $this->flash->error("Failed to delete product");
            }
        } catch (\Exception $e) {
            $this->flash->error("Error: " . $e->getMessage());
        }
        
        return $this->response->redirect('inventory-maker/list');
    }
    
    /**
     * Helper: Sync product to Odoo
     */
    private function syncToOdoo(Inventory $product)
    {
        try {
            $data = [
                'name' => $product->name,
                'default_code' => $product->sku,
                'list_price' => (float)($product->selling_price ?? 0),
                'standard_price' => (float)$product->cost_price,
                'type' => $product->product_type ?? 'consu',
                'tracking' => 'none'
            ];
            
            if ($product->odoo_id) {
                // Update existing
                $this->odoo->execute('product.product', 'write', [[$product->odoo_id], $data]);
            } else {
                // Create new
                $result = $this->odoo->execute('product.product', 'create', [$data]);
                if (is_array($result)) {
                    $result = $result[0] ?? null;
                }
                if ($result) {
                    $product->odoo_id = $result;
                    $product->synced_to_odoo = 1;
                    $product->save();
                }
            }
            
            $product->synced_to_odoo = 1;
            $product->last_sync_at = date('Y-m-d H:i:s');
            $product->sync_notes = "Synced at " . date('Y-m-d H:i:s');
            $product->save();
            
        } catch (\Exception $e) {
            error_log("Sync error: " . $e->getMessage());
            $product->synced_to_odoo = 0;
            $product->sync_notes = "Error: " . $e->getMessage();
            $product->save();
        }
    }
}
