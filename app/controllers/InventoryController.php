<?php
namespace App\Controllers;

use Phalcon\Mvc\Controller;
use App\Models\Inventory;

class InventoryController extends Controller
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
        $this->response->redirect('inventory/list');
    }

    public function editAction($id)
    {
        $item = Inventory::findFirst($id);
        if (!$item) {
            $this->flash->error('Barang tidak ditemukan');
            $this->response->redirect('inventory/list');
            return;
        }

        if ($this->request->isPost()) {
            $item->name = $this->request->getPost('name');
            $item->description = $this->request->getPost('description');
            $item->quantity = (int)$this->request->getPost('quantity');
            $item->category = $this->request->getPost('category');
            $item->price = (float)$this->request->getPost('price');

            if ($item->save()) {
                $this->flash->success('Barang berhasil diupdate');
            } else {
                foreach ($item->getMessages() as $message) {
                    $this->flash->error($message);
                }
            }
            $this->response->redirect('inventory/list');
            return;
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
        $this->response->redirect('inventory/list');
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

            $item->save();
            $this->flash->success('Stok berhasil diperbarui');
        }
        $this->response->redirect('inventory/list');
    }

    public function searchAction()
    {
        $searchTerm = $this->request->get('q');
        $category = $this->request->get('category');

        $conditions = [];
        $bind = [];

        if ($searchTerm) {
            $conditions[] = "name LIKE :search: OR description LIKE :search:";
            $bind['search'] = "%$searchTerm%";
        }

        if ($category) {
            $conditions[] = "category = :category:";
            $bind['category'] = $category;
        }

        $where = '';
        if (!empty($conditions)) {
            $where = 'WHERE ' . implode(' AND ', $conditions);
        }

        $sql = "SELECT * FROM inventory $where ORDER BY created_at DESC";
        $items = $this->modelsManager->executeQuery($sql, $bind);

        $this->view->items = $items;
        $this->view->searchTerm = $searchTerm;
        $this->view->category = $category;
        $this->view->title = 'Hasil Pencarian - Simple Inventory Tracker';
    }
}