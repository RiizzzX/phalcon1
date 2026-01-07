<?php
declare(strict_types=1);


use Phalcon\Mvc\Controller;
use App\Models\Inventory;

class IndexController extends Controller
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
            $item->price = (float)$this->request->getPost('price');

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
            $item->price = (float)$this->request->getPost('price');

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

        // Total value (sum of price * quantity)
        $totalValue = 0;
        $items = Inventory::find();
        foreach ($items as $item) {
            $totalValue += $item->price * $item->quantity;
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
    }
}