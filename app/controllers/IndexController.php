<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\Inventory;

class IndexController extends ControllerBase
{
    public function indexAction()
    {
        // Landing page dengan penjelasan sistem
        $this->view->title = 'Simple Inventory Tracker - Sistem Manajemen Inventaris';
    }

    public function listAction()
    {
        // Ambil semua items dari database
        $items = Inventory::find([
            'order' => 'created_at DESC'
        ]);

        $this->view->items = $items;
        $this->view->title = 'Daftar Inventaris - Simple Inventory Tracker';
    }

    public function addAction()
    {
        if ($this->request->isPost()) {
            $item = new Inventory();
            $item->name = $this->request->getPost('name');
            $item->description = $this->request->getPost('description');
            $item->quantity = (int)$this->request->getPost('quantity');
            $item->category = $this->request->getPost('category');
            $item->selling_price = (float)$this->request->getPost('selling_price');

            if ($item->save()) {
                $this->flash->success('Barang berhasil ditambahkan');
            } else {
                foreach ($item->getMessages() as $message) {
                    $this->flash->error($message);
                }
            }
        }
        return $this->response->redirect('index/list');
    }

    public function editAction($id)
    {
        $item = Inventory::findFirst($id);
        if (!$item) {
            $this->flash->error('Barang tidak ditemukan');
            return $this->response->redirect('index/list');
        }

        if ($this->request->isPost()) {
            $item->name = $this->request->getPost('name');
            $item->description = $this->request->getPost('description');
            $item->quantity = (int)$this->request->getPost('quantity');
            $item->category = $this->request->getPost('category');
            $item->selling_price = (float)$this->request->getPost('selling_price');

            if ($item->save()) {
                $this->flash->success('Barang berhasil diupdate');
                return $this->response->redirect('index/list');
            } else {
                foreach ($item->getMessages() as $message) {
                    $this->flash->error((string)$message);
                }
            }
        }

        $this->view->item = $item;
        $this->view->title = 'Edit Barang - Simple Inventory Tracker';
    }

    public function deleteAction($id)
    {
        $item = Inventory::findFirst($id);
        if ($item) {
            $item->delete();
            $this->flash->success('Barang berhasil dihapus');
        } else {
            $this->flash->error('Barang tidak ditemukan');
        }
        return $this->response->redirect('index/list');
    }

    public function updateStockAction($id)
    {
        $item = Inventory::findFirst($id);
        if ($item) {
            $operation = $this->request->getPost('operation');
            $amount = (int)$this->request->getPost('amount');

            if ($operation === 'add') {
                $item->quantity += $amount;
            } elseif ($operation === 'remove') {
                $item->quantity = max(0, $item->quantity - $amount);
            }

            if ($item->save()) {
                $this->flash->success('Stok berhasil diperbarui');
            } else {
                $this->flash->error('Gagal memperbarui stok');
            }
        } else {
            $this->flash->error('Barang tidak ditemukan');
        }
        return $this->response->redirect($this->request->getServer('HTTP_REFERER') ?: 'index/list');
    }

    public function searchAction()
    {
        $searchTerm = $this->request->get('q');
        $category = $this->request->get('category');
        $startDate = $this->request->get('start_date');
        $endDate = $this->request->get('end_date');
        $minPrice = $this->request->get('min_price');
        $maxPrice = $this->request->get('max_price');

        $conditions = [];
        $bind = [];

        if ($searchTerm) {
            $conditions[] = "(name LIKE :search: OR description LIKE :search:)";
            $bind['search'] = "%$searchTerm%";
        }

        if ($category) {
            $conditions[] = "category = :category:";
            $bind['category'] = $category;
        }

        if ($startDate) {
            $conditions[] = "created_at >= :start_date:";
            $bind['start_date'] = $startDate . ' 00:00:00';
        }

        if ($endDate) {
            $conditions[] = "created_at <= :end_date:";
            $bind['end_date'] = $endDate . ' 23:59:59';
        }

        if ($minPrice) {
            $conditions[] = "price >= :min_price:";
            $bind['min_price'] = (float)$minPrice;
        }

        if ($maxPrice) {
            $conditions[] = "price <= :max_price:";
            $bind['max_price'] = (float)$maxPrice;
        }

        $queryParams = [
            'order' => 'created_at DESC'
        ];

        if (!empty($conditions)) {
            $queryParams['conditions'] = implode(' AND ', $conditions);
            $queryParams['bind'] = $bind;
        }

        $items = Inventory::find($queryParams);

        $this->view->items = $items;
        $this->view->searchTerm = $searchTerm;
        $this->view->category = $category;
        $this->view->startDate = $startDate;
        $this->view->endDate = $endDate;
        $this->view->minPrice = $minPrice;
        $this->view->maxPrice = $maxPrice;
        $this->view->title = 'Hasil Pencarian - Simple Inventory Tracker';
        $this->view->pick('index/list');
    }

    public function getStatsAction()
    {
        $this->view->disable();
        try {
            // Total items
            $totalItems = Inventory::count();

            // Total categories (unique categories)
            $categories = Inventory::find([
                'columns' => 'DISTINCT category'
            ]);
            $totalCategories = count($categories);

            // Low stock items (quantity <= 5)
            $lowStock = Inventory::count([
                'conditions' => 'quantity <= 5'
            ]);

            // Total value (sum of selling_price * quantity)
            $totalValue = 0;
            $items = Inventory::find();
            foreach ($items as $item) {
                $totalValue += ($item->selling_price ?? 0) * ($item->quantity ?? 0);
            }

            $stats = [
                'total_items' => $totalItems,
                'total_categories' => $totalCategories,
                'low_stock' => $lowStock,
                'total_value' => $totalValue
            ];

            $this->response->setJsonContent($stats);
            $this->response->setContentType('application/json', 'UTF-8');
            return $this->response;
        } catch (\Throwable $e) {
            $this->response->setStatusCode(500, 'Internal Server Error');
            $this->response->setJsonContent([
                'error' => true,
                'message' => $e->getMessage()
            ]);
            $this->response->setContentType('application/json', 'UTF-8');
            return $this->response;
        }
    }

    // Fallback JSON endpoint for AJAX product creation when Odoo controller is unavailable
    public function odooCreateProductAction()
    {
        error_log('DEBUG: odooCreateProductAction called');
        $this->view->disable();
        // ensure POST parsing works for both form-urlencoded and raw JSON
        $input = $this->request->getRawBody();
        if ($input && $this->request->getContentType() === 'application/json') {
            $data = json_decode($input, true);
            foreach ($data as $k => $v) {
                $_POST[$k] = $v; // make accessible via getPost
            }
        }
        if (!$this->request->isPost()) {
            $this->response->setStatusCode(400);
            $this->response->setJsonContent(['success' => false, 'message' => 'Invalid request']);
            return $this->response;
        }

        try {
            $name = $this->request->getPost('name');
            $category = $this->request->getPost('category');
            $selling = (float)($this->request->getPost('selling_price') ?? 0);
            $cost = (float)($this->request->getPost('cost_price') ?? 0);
            $sku = $this->request->getPost('sku');

            if (!$name || !$category) {
                $this->response->setJsonContent(['success' => false, 'message' => 'Missing fields']);
                return $this->response;
            }

            $product = new Inventory();
            $product->name = $name;
            $product->category = $category;
            $product->selling_price = $selling;
            $product->cost_price = $cost;
            $product->sku = $sku;
            $product->quantity = (int)($this->request->getPost('quantity') ?? 0);
            $product->status = 'active';
            $product->synced_to_odoo = 0;

            if ($product->save()) {
                $this->response->setJsonContent(['success' => true, 'message' => 'Product created', 'id' => $product->id]);
            } else {
                $msgs = [];
                foreach ($product->getMessages() as $m) $msgs[] = (string)$m;
                $this->response->setJsonContent(['success' => false, 'message' => implode('; ', $msgs)]);
            }
        } catch (\Throwable $e) {
            $this->response->setStatusCode(500);
            $this->response->setJsonContent(['success' => false, 'message' => $e->getMessage()]);
        }

        return $this->response;
    }

    // Debug helper: show all registered routes (pattern and name)
    public function debugRoutesAction()
    {
        $this->view->disable();
        $router = $this->getDI()->getRouter();
        $routes = [];
        foreach ($router->getRoutes() as $r) {
            $routes[] = [
                'pattern' => $r->getPattern(),
                'name' => $r->getName(),
                'paths' => $r->getPaths()
            ];
        }
        $this->response->setJsonContent($routes);
        return $this->response;
    }
}