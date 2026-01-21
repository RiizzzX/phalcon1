<?php

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
                [[]], // args: domain  
                ['fields' => ['name', 'partner_id', 'date_order', 'amount_total', 'state']] // kwargs
            );
            
            $this->view->orders = $orders ?: [];
            $this->view->title = "Purchase Orders";
        } catch (\Exception $e) {
            $this->flash->error("Error: " . $e->getMessage());
            $this->view->orders = [];
        }
    }
    
    /**
     * Create purchase order
     */
    public function createAction()
    {
        if ($this->request->isPost()) {
            try {
                $partnerId = (int)$this->request->getPost('partner_id');
                $productId = $this->request->getPost('product_id');
                $quantity = (float)$this->request->getPost('quantity');
                $price = (float)$this->request->getPost('price');
                
                // Create PO header
                $poData = [
                    'partner_id' => $partnerId,
                    'date_order' => date('Y-m-d H:i:s')
                ];
                
                $orderId = $this->odoo->executePublic('purchase.order', 'create', [[$poData]]);
                
                if ($orderId && $productId) {
                    // Create PO Line
                    $lineData = [
                        'order_id' => $orderId,
                        'product_id' => (int)$productId,
                        'product_qty' => $quantity,
                        'price_unit' => $price,
                        'name' => $this->request->getPost('product_name') ?: 'Product',
                        'date_planned' => date('Y-m-d H:i:s')
                    ];
                    
                    $this->odoo->executePublic('purchase.order.line', 'create', [[$lineData]]);
                }
                
                if ($orderId) {
                    $this->flash->success("Purchase Order berhasil dibuat dengan ID: $orderId");
                    return $this->response->redirect('odoo-purchase');
                }
            } catch (\Exception $e) {
                $this->flash->error("Error: " . $e->getMessage());
            }
        }
        
        // Get suppliers
        try {
            $suppliers = $this->odoo->executePublic('res.partner', 'search_read',
                [[['supplier_rank', '>', 0]]], // args: domain
                ['fields' => ['name', 'email', 'phone']] // kwargs
            );
            $this->view->suppliers = $suppliers ?: [];
        } catch (\Exception $e) {
            $this->view->suppliers = [];
        }
        
        // Get products
        try {
            $products = $this->odoo->executePublic('product.product', 'search_read',
                [[['type', '=', 'product']]], // args: only storable products
                ['fields' => ['name', 'default_code', 'standard_price', 'uom_id']] // kwargs
            );
            $this->view->products = $products ?: [];
        } catch (\Exception $e) {
            $this->view->products = [];
        }
        
        // Pre-fill product if coming from inventory
        $productId = $this->request->get('product_id');
        if ($productId) {
            $this->view->preselected_product = (int)$productId;
        }
        
        $this->view->title = "Buat Purchase Order";
    }
    
    /**
     * View purchase order detail
     */
    public function viewAction($id = null)
    {
        if (!$id) {
            $id = $this->dispatcher->getParam('id');
        }
        
        try {
            $order = $this->odoo->executePublic('purchase.order', 'read', [
                [$id],
                ['name', 'partner_id', 'date_order', 'amount_total', 'state', 'notes']
            ]);
            
            if (!empty($order)) {
                $this->view->order = $order[0];
                $this->view->title = "Purchase Order Detail";
            } else {
                $this->flash->error("Order not found");
                return $this->response->redirect('odoo-purchase');
            }
        } catch (\Exception $e) {
            $this->flash->error("Error: " . $e->getMessage());
            return $this->response->redirect('odoo-purchase');
        }
    }
    
    /**
     * Create supplier/partner
     */
    public function createSupplierAction()
    {
        if ($this->request->isPost()) {
            try {
                $name = $this->request->getPost('name');
                $email = $this->request->getPost('email');
                $phone = $this->request->getPost('phone');
                $street = $this->request->getPost('street');
                $city = $this->request->getPost('city');
                
                $supplierData = [
                    'name' => $name,
                    'supplier_rank' => 1,
                ];
                
                if ($email) $supplierData['email'] = $email;
                if ($phone) $supplierData['phone'] = $phone;
                if ($street) $supplierData['street'] = $street;
                if ($city) $supplierData['city'] = $city;
                
                $supplierId = $this->odoo->executePublic('res.partner', 'create', [[$supplierData]]);
                
                $this->flash->success("✅ Supplier berhasil dibuat dengan ID: " . $supplierId);
                return $this->response->redirect('odoo-purchase/create');
            } catch (\Exception $e) {
                $this->flash->error("Error: " . $e->getMessage());
            }
        }
        
        $this->view->title = "Tambah Supplier";
    }
}
