<?php

namespace App\Controllers;

use App\Models\Inventory;

class InventoryApiController extends ControllerBase
{
    /**
     * GET /inventory-api/list
     * Retrieve all products with optional filters
     */
    public function listAction()
    {
        try {
            $category = $this->request->getQuery('category');
            $status = $this->request->getQuery('status');
            $limit = (int)($this->request->getQuery('limit') ?? 50);
            
            $conditions = '';
            $params = [];
            
            if ($category) {
                $conditions .= 'category = ?1';
                $params[1] = $category;
            }
            
            if ($status) {
                $op = $conditions ? 'AND' : '';
                $conditions .= " $op status = ?" . (count($params) + 1);
                $params[count($params) + 1] = $status;
            }
            
            $query = $conditions ? ['conditions' => $conditions, 'bind' => $params] : [];
            $query['limit'] = $limit;
            $query['order'] = 'id DESC';
            
            $products = Inventory::find($query);
            
            $this->response->setJsonContent([
                'success' => true,
                'data' => $products,
                'count' => count($products)
            ]);
        } catch (\Exception $e) {
            $this->response->setStatusCode(500);
            $this->response->setJsonContent([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
        
        return $this->response;
    }

    /**
     * GET /inventory-api/view/{id}
     * Get specific product details
     */
    public function viewAction($id)
    {
        try {
            $product = Inventory::findFirstById($id);
            
            if (!$product) {
                $this->response->setStatusCode(404);
                $this->response->setJsonContent([
                    'success' => false,
                    'error' => 'Product not found'
                ]);
            } else {
                $this->response->setJsonContent([
                    'success' => true,
                    'data' => $product
                ]);
            }
        } catch (\Exception $e) {
            $this->response->setStatusCode(500);
            $this->response->setJsonContent([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
        
        return $this->response;
    }

    /**
     * POST /inventory-api/create
     * Create new product
     * Body: {name, category, sku?, description?, quantity?, cost_price?, selling_price?, product_type?}
     */
    public function createAction()
    {
        try {
            $data = $this->request->getJsonRawBody(true);
            
            if (!isset($data['name']) || !isset($data['category'])) {
                $this->response->setStatusCode(400);
                $this->response->setJsonContent([
                    'success' => false,
                    'error' => 'Missing required fields: name, category'
                ]);
                return $this->response;
            }
            
            $inv = new Inventory();
            $inv->name = $data['name'];
            $inv->category = $data['category'];
            $inv->sku = $data['sku'] ?? null;
            $inv->description = $data['description'] ?? null;
            $inv->quantity = (int)($data['quantity'] ?? 0);
            $inv->product_type = $data['product_type'] ?? 'consu';
            $inv->cost_price = (float)($data['cost_price'] ?? 0);
            $inv->selling_price = (float)($data['selling_price'] ?? 0);
            $inv->status = $data['status'] ?? 'active';
            $inv->synced_to_odoo = 0;
            
            if ($inv->save()) {
                $this->response->setStatusCode(201);
                $this->response->setJsonContent([
                    'success' => true,
                    'message' => 'Product created',
                    'data' => $inv
                ]);
            } else {
                $this->response->setStatusCode(400);
                $this->response->setJsonContent([
                    'success' => false,
                    'error' => 'Failed to save product'
                ]);
            }
        } catch (\Exception $e) {
            $this->response->setStatusCode(500);
            $this->response->setJsonContent([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
        
        return $this->response;
    }

    /**
     * PUT /inventory-api/update/{id}
     * Update product
     */
    public function updateAction($id)
    {
        try {
            $inv = Inventory::findFirstById($id);
            
            if (!$inv) {
                $this->response->setStatusCode(404);
                $this->response->setJsonContent([
                    'success' => false,
                    'error' => 'Product not found'
                ]);
                return $this->response;
            }
            
            $data = $this->request->getJsonRawBody(true);
            
            // Update fields if provided
            if (isset($data['name'])) $inv->name = $data['name'];
            if (isset($data['category'])) $inv->category = $data['category'];
            if (isset($data['sku'])) $inv->sku = $data['sku'];
            if (isset($data['description'])) $inv->description = $data['description'];
            if (isset($data['quantity'])) $inv->quantity = (int)$data['quantity'];
            if (isset($data['product_type'])) $inv->product_type = $data['product_type'];
            if (isset($data['cost_price'])) $inv->cost_price = (float)$data['cost_price'];
            if (isset($data['selling_price'])) $inv->selling_price = (float)$data['selling_price'];
            if (isset($data['status'])) $inv->status = $data['status'];
            
            if ($inv->save()) {
                $this->response->setJsonContent([
                    'success' => true,
                    'message' => 'Product updated',
                    'data' => $inv
                ]);
            } else {
                $this->response->setStatusCode(400);
                $this->response->setJsonContent([
                    'success' => false,
                    'error' => 'Failed to update product'
                ]);
            }
        } catch (\Exception $e) {
            $this->response->setStatusCode(500);
            $this->response->setJsonContent([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
        
        return $this->response;
    }

    /**
     * DELETE /inventory-api/delete/{id}
     * Delete product
     */
    public function deleteAction($id)
    {
        try {
            $inv = Inventory::findFirstById($id);
            
            if (!$inv) {
                $this->response->setStatusCode(404);
                $this->response->setJsonContent([
                    'success' => false,
                    'error' => 'Product not found'
                ]);
                return $this->response;
            }
            
            if ($inv->delete()) {
                $this->response->setJsonContent([
                    'success' => true,
                    'message' => 'Product deleted'
                ]);
            } else {
                $this->response->setStatusCode(400);
                $this->response->setJsonContent([
                    'success' => false,
                    'error' => 'Failed to delete product'
                ]);
            }
        } catch (\Exception $e) {
            $this->response->setStatusCode(500);
            $this->response->setJsonContent([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
        
        return $this->response;
    }

    /**
     * POST /inventory-api/adjust-stock/{id}
     * Adjust stock quantity (add/subtract)
     * Body: {quantity_change, note?}
     */
    public function adjustStockAction($id)
    {
        try {
            $inv = Inventory::findFirstById($id);
            
            if (!$inv) {
                $this->response->setStatusCode(404);
                $this->response->setJsonContent([
                    'success' => false,
                    'error' => 'Product not found'
                ]);
                return $this->response;
            }
            
            $data = $this->request->getJsonRawBody(true);
            
            if (!isset($data['quantity_change'])) {
                $this->response->setStatusCode(400);
                $this->response->setJsonContent([
                    'success' => false,
                    'error' => 'Missing required field: quantity_change'
                ]);
                return $this->response;
            }
            
            $change = (int)$data['quantity_change'];
            $oldQty = $inv->quantity;
            $inv->quantity += $change;
            
            if ($inv->save()) {
                $this->response->setJsonContent([
                    'success' => true,
                    'message' => 'Stock adjusted',
                    'old_quantity' => $oldQty,
                    'new_quantity' => $inv->quantity,
                    'change' => $change,
                    'data' => $inv
                ]);
            } else {
                $this->response->setStatusCode(400);
                $this->response->setJsonContent([
                    'success' => false,
                    'error' => 'Failed to adjust stock'
                ]);
            }
        } catch (\Exception $e) {
            $this->response->setStatusCode(500);
            $this->response->setJsonContent([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
        
        return $this->response;
    }

    /**
     * POST /inventory-api/search
     * Search products
     * Body: {query, fields?}
     */
    public function searchAction()
    {
        try {
            $data = $this->request->getJsonRawBody(true);
            
            if (!isset($data['query'])) {
                $this->response->setStatusCode(400);
                $this->response->setJsonContent([
                    'success' => false,
                    'error' => 'Missing required field: query'
                ]);
                return $this->response;
            }
            
            $query = '%' . $data['query'] . '%';
            
            $results = Inventory::find([
                'conditions' => 'name LIKE ?1 OR sku LIKE ?1 OR description LIKE ?1',
                'bind' => [1 => $query],
                'limit' => 50
            ]);
            
            $this->response->setJsonContent([
                'success' => true,
                'data' => $results,
                'count' => count($results)
            ]);
        } catch (\Exception $e) {
            $this->response->setStatusCode(500);
            $this->response->setJsonContent([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
        
        return $this->response;
    }
}
