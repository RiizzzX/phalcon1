<?php

namespace App\Controllers;

use App\Models\Inventory;
use App\Models\Category;

class InventoryController extends ControllerBase
{
    public function listAction()
    {
        $inventory = Inventory::find([
            'order' => 'id DESC',
            'limit' => 20
        ]);
        
        $this->view->inventory = $inventory;
        $this->view->syncStatus = ['total' => 0, 'synced' => 0, 'unsynced' => 0, 'sync_percentage' => 0];
    }

    public function addAction()
    {
        if ($this->request->isPost()) {
            $name = $this->request->getPost('name');
            $category = $this->request->getPost('category');
            
            if (!empty($name) && !empty($category)) {
                $inv = new Inventory();
                $inv->name = $name;
                $inv->category = $category;
                $inv->sku = $this->request->getPost('sku');
                $inv->description = $this->request->getPost('description');
                $inv->quantity = (int)($this->request->getPost('quantity') ?? 0);
                $inv->product_type = $this->request->getPost('product_type') ?? 'consu';
                $inv->cost_price = (float)($this->request->getPost('cost_price') ?? 0);
                $inv->selling_price = (float)($this->request->getPost('selling_price') ?? 0);
                $inv->status = 'active';
                $inv->synced_to_odoo = 0;
                
                if ($inv->save()) {
                    $this->flash->success("✓ Product created successfully!");
                    return $this->response->redirect('/inventory/list');
                } else {
                    $this->flash->error("Failed to create product");
                }
            } else {
                $this->flash->error("Product name and category are required");
            }
        }

        // Load categories for dropdown
        $categories = Category::find(['order' => 'name ASC']);
        $this->view->categories = $categories;
    }

    public function editAction($id)
    {
        $inv = Inventory::findFirstById($id);
        if (!$inv) {
            return $this->response->redirect('/inventory/list');
        }
        
        if ($this->request->isPost()) {
            $inv->name = $this->request->getPost('name');
            $inv->category = $this->request->getPost('category');
            $inv->sku = $this->request->getPost('sku');
            $inv->description = $this->request->getPost('description');
            $inv->quantity = (int)($this->request->getPost('quantity') ?? $inv->quantity);
            $inv->product_type = $this->request->getPost('product_type') ?? $inv->product_type;
            $inv->cost_price = (float)($this->request->getPost('cost_price') ?? $inv->cost_price);
            $inv->selling_price = (float)($this->request->getPost('selling_price') ?? $inv->selling_price);
            $inv->status = $this->request->getPost('status') ?? 'active';
            
            if ($inv->save()) {
                $this->flash->success("✓ Product updated!");
                return $this->response->redirect('/inventory/list');
            } else {
                $this->flash->error("Failed to update product");
            }
        }
        
        // Load categories for dropdown
        $categories = Category::find(['order' => 'name ASC']);
        $this->view->categories = $categories;
        $this->view->inventory = $inv;
    }

    public function deleteAction($id)
    {
        $inv = Inventory::findFirstById($id);
        if ($inv) {
            $inv->delete();
            $this->flash->success("Deleted!");
        }
        return $this->response->redirect('/inventory/list');
    }

    public function updateStockAction($id)
    {
        $inv = Inventory::findFirstById($id);
        if (!$inv) {
            return $this->response->redirect('/inventory/list');
        }
        
        if ($this->request->isPost()) {
            $inv->quantity = (int)$this->request->getPost('quantity');
            if ($inv->save()) {
                $this->flash->success("Stock updated!");
                return $this->response->redirect('/inventory/list');
            }
        }
        
        $this->view->inventory = $inv;
    }

    public function syncToOdooAction($id)
    {
        $this->flash->info('Sync disabled');
        return $this->response->redirect('/inventory/list');
    }

    public function syncAllAction()
    {
        $this->flash->info('Sync disabled');
        return $this->response->redirect('/inventory/list');
    }

    public function statusAction()
    {
        $this->response->setJsonContent([
            'total' => Inventory::count(),
            'synced' => 0,
            'unsynced' => Inventory::count(),
            'sync_percentage' => 0
        ]);
        return $this->response;
    }

    public function indexAction()
    {
        $this->view->syncStatus = [
            'total' => Inventory::count(),
            'synced' => 0,
            'unsynced' => Inventory::count(),
            'sync_percentage' => 0
        ];
    }
}

