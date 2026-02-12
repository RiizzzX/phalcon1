<?php

namespace App\Controllers;

use App\Models\Inventory;
use App\Models\Category;

class OdooInventoryController extends OdooControllerBase
{
    /**
     * List all products
     */
    public function indexAction()
    {
        try {
            $this->view->pick('index');
            
            $products = Inventory::find([
                'order' => 'id DESC'
            ]);
            
            // Load categories for dropdown
            $categories = Category::find(['order' => 'name ASC']);
            
            $this->view->products = $products;
            $this->view->categories = $categories;
            $this->view->title = "Inventory Management";
            
            $this->response->setHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
            $this->response->setHeader('Pragma', 'no-cache');
            $this->response->setHeader('Expires', '0');
            
        } catch (\Exception $e) {
            $this->flash->error("Error loading products: " . $e->getMessage());
            $this->view->products = [];
            $this->view->categories = [];
        }
    }

    /**
     * View single product
     */
    public function viewAction($id)
    {
        try {
            $product = Inventory::findFirstById($id);
            
            if (!$product) {
                $this->flash->error("Product not found");
                return $this->response->redirect('/odoo-inventory');
            }
            
            if ($this->request->isAjax()) {
                $this->response->setJsonContent([
                    'success' => true,
                    'data' => $product
                ]);
                return $this->response;
            }
            
            $this->view->product = $product;
            $this->view->title = "Product: " . $product->name;
            
        } catch (\Exception $e) {
            $this->flash->error("Error: " . $e->getMessage());
            return $this->response->redirect('/odoo-inventory');
        }
    }

    /**
     * Show form to create new product
     */
    public function createAction()
    {
        // Load categories for dropdown
        $categories = Category::find(['order' => 'name ASC']);
        $this->view->categories = $categories;
    }

    /**
     * Create new product
     */
    public function createProductAction()
    {
        error_log('DEBUG: createProductAction called');
        $this->view->disable();
        if (!$this->request->isPost()) {
            $this->response->setStatusCode(400);
            $this->response->setJsonContent(['success' => false, 'message' => 'Invalid request']);
            return $this->response;
        }
        try {
            $name = $this->request->get('name');
            $category = $this->request->get('category');
            if (!$name || !$category) {
                $this->response->setJsonContent(['success' => false, 'message' => 'Missing fields']);
                return $this->response;
            }
            $product = new Inventory();
            $product->name = $name;
            $product->category = $category;
            $product->sku = $this->request->get('sku');
            $product->description = $this->request->get('description');
            $product->product_type = $this->request->get('product_type') ?? 'product';
            $product->quantity = (int)($this->request->get('quantity') ?? 0);
            $product->cost_price = (float)($this->request->get('cost_price') ?? 0);
            $product->selling_price = (float)($this->request->get('selling_price') ?? 0);
            $product->status = 'active';
            $product->synced_to_odoo = 0;
            if ($product->save()) {
                $this->response->setJsonContent([
                    'success' => true,
                    'message' => 'Product created',
                    'id' => $product->id
                ]);
            } else {
                $this->response->setJsonContent(['success' => false, 'message' => 'Save failed']);
            }
        } catch (\Exception $e) {
            $this->response->setJsonContent(['success' => false, 'message' => $e->getMessage()]);
        }
        return $this->response;
    }

    /**
     * Edit product
     */
    public function editAction($id)
    {
        if (!$this->request->isPost()) {
            // Load form view
            $product = Inventory::findFirstById($id);
            if (!$product) {
                $this->flash->error("Product not found");
                return $this->response->redirect('/odoo-inventory');
            }
            
            // Load categories for dropdown
            $categories = Category::find(['order' => 'name ASC']);
            $this->view->categories = $categories;
            $this->view->product = $product;
            $this->view->title = "Edit Product: " . $product->name;
            return;
        }
        
        // Handle POST (AJAX)
        try {
            $product = Inventory::findFirstById($id);
            if (!$product) {
                $this->view->disable();
                $this->response->setJsonContent(['success' => false, 'message' => 'Product not found']);
                return $this->response;
            }
            $product->name = $this->request->get('name') ?? $product->name;
            $product->category = $this->request->get('category') ?? $product->category;
            $product->sku = $this->request->get('sku') ?? $product->sku;
            $product->description = $this->request->get('description') ?? $product->description;
            $product->product_type = $this->request->get('product_type') ?? $product->product_type;
            $product->quantity = (int)($this->request->get('quantity') ?? $product->quantity);
            $product->cost_price = (float)($this->request->get('cost_price') ?? $product->cost_price);
            $product->selling_price = (float)($this->request->get('selling_price') ?? $product->selling_price);
            $product->status = $this->request->get('status') ?? $product->status;
            if ($product->save()) {
                $this->view->disable();
                $this->response->setJsonContent(['success' => true, 'message' => 'Product updated']);
                return $this->response;
            } else {
                $this->view->disable();
                $this->response->setJsonContent(['success' => false, 'message' => 'Save failed']);
            }
        } catch (\Exception $e) {
            $this->view->disable();
            $this->response->setJsonContent(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Delete product
     */
    public function deleteAction($id)
    {
        if (!$this->request->isPost()) {
            $this->view->disable();
            $this->response->setStatusCode(400);
            $this->response->setJsonContent(['success' => false, 'message' => 'Invalid request']);
            return $this->response;
        }
        try {
            $product = Inventory::findFirstById($id);
            if (!$product) {
                $this->view->disable();
                $this->response->setJsonContent(['success' => false, 'message' => 'Product not found']);
                return $this->response;
            }
            $name = $product->name;
            if ($product->delete()) {
                $this->view->disable();
                $this->response->setJsonContent(['success' => true, 'message' => 'Product deleted']);
            } else {
                $this->view->disable();
                $this->response->setJsonContent(['success' => false, 'message' => 'Delete failed']);
            }
        } catch (\Exception $e) {
            $this->view->disable();
            $this->response->setJsonContent(['success' => false, 'message' => $e->getMessage()]);
        }
        return $this->response;
    }

    /**
     * Delete multiple products
     */
    public function bulkDeleteAction()
    {
        if (!$this->request->isPost()) {
            $this->view->disable();
            $this->response->setStatusCode(400);
            return $this->response->setJsonContent(['success' => false, 'message' => 'Invalid request']);
        }

        try {
            $ids = $this->request->getPost('ids');
            if (empty($ids) || !is_array($ids)) {
                $this->view->disable();
                return $this->response->setJsonContent(['success' => false, 'message' => 'No products selected']);
            }

            $count = 0;
            foreach ($ids as $id) {
                $product = Inventory::findFirstById($id);
                if ($product && $product->delete()) {
                    $count++;
                }
            }

            $this->view->disable();
            return $this->response->setJsonContent([
                'success' => true, 
                'message' => "Successfully deleted $count products"
            ]);
        } catch (\Exception $e) {
            $this->view->disable();
            return $this->response->setJsonContent(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Search products
     */
    public function searchAction()
    {
        try {
            $query = $this->request->getPost('q');
            
            if (!$query) {
                $this->response->setJsonContent(['success' => false, 'message' => 'No query']);
                return $this->response;
            }
            
            $search = '%' . $query . '%';
            $products = Inventory::find([
                'conditions' => 'name LIKE ?1 OR sku LIKE ?1',
                'bind' => [1 => $search],
                'limit' => 20
            ]);
            
            $this->response->setJsonContent([
                'success' => true,
                'results' => $products
            ]);
            
        } catch (\Exception $e) {
            $this->response->setStatusCode(500);
            $this->response->setJsonContent(['success' => false, 'error' => $e->getMessage()]);
        }
        
        return $this->response;
    }

}
