<?php

namespace App\Controllers;

use App\Library\OdooClient;

class OdooSalesController extends OdooControllerBase
{
    /**
     * List sales orders
     */
    public function indexAction()
    {
        try {
            $orders = $this->odoo->executePublic('sale.order', 'search_read',
                [[]], // args: domain
                ['fields' => ['name', 'partner_id', 'date_order', 'amount_total', 'state']] // kwargs
            );
            
            $this->view->orders = $orders ?: [];
            $this->view->title = "Sales Orders";
        } catch (\Exception $e) {
            $this->flash->error("Error: " . $e->getMessage());
            $this->view->orders = [];
        }
    }
    
    /**
     * Create sales order
     */
    public function createAction()
    {
        if ($this->request->isPost()) {
            try {
                $partnerId = (int)$this->request->getPost('partner_id');
                $productId = $this->request->getPost('product_id');
                $quantity = (float)$this->request->getPost('quantity');
                $price = (float)$this->request->getPost('price');
                
                // Create SO header
                $soData = [
                    'partner_id' => $partnerId
                ];
                
                $orderId = $this->odoo->executePublic('sale.order', 'create', [[$soData]]);
                
                if ($orderId && $productId) {
                    // Create SO Line
                    $lineData = [
                        'order_id' => $orderId,
                        'product_id' => (int)$productId,
                        'product_uom_qty' => $quantity,
                        'price_unit' => $price,
                        'name' => $this->request->getPost('product_name') ?: 'Product'
                    ];
                    
                    $this->odoo->executePublic('sale.order.line', 'create', [[$lineData]]);
                }
                
                if ($orderId) {
                    $this->flash->success("Sales Order berhasil dibuat dengan ID: $orderId");
                    return $this->response->redirect('odoo-sales');
                }
            } catch (\Exception $e) {
                $this->flash->error("Error: " . $e->getMessage());
            }
        }
        
        // Get customers dari res.partner dengan customer_rank > 0
        try {
            $customers = $this->odoo->executePublic('res.partner', 'search_read',
                [[['customer_rank', '>', 0]]], // args: domain untuk filter customers
                ['fields' => ['name', 'email', 'phone']] // kwargs
            );
            $this->view->customers = $customers ?: [];
        } catch (\Exception $e) {
            $this->view->customers = [];
        }
        
        // Get products dari product.product
        try {
            $products = $this->odoo->executePublic('product.product', 'search_read',
                [[]], // args: domain kosong untuk semua products
                ['fields' => ['name', 'default_code', 'list_price', 'type']] // kwargs
            );
            $this->view->products = $products ?: [];
        } catch (\Exception $e) {
            $this->view->products = [];
        }
        
        $this->view->title = "Buat Sales Order";
    }
    
    /**
     * View sales order
     */
    public function viewAction($id = null)
    {
        if (!$id) {
            $id = $this->dispatcher->getParam('id');
        }
        
        try {
            $order = $this->odoo->executePublic('sale.order', 'read', [
                [$id],
                ['name', 'partner_id', 'date_order', 'amount_total', 'state', 'note']
            ]);
            
            if (!empty($order)) {
                $this->view->order = $order[0];
                
                // Get order lines
                $orderLines = $this->odoo->executePublic('sale.order.line', 'search_read',
                    [[['order_id', '=', $id]]],
                    ['fields' => ['product_id', 'product_uom_qty', 'price_unit', 'price_subtotal']]
                );
                $this->view->order_lines = $orderLines ?: [];
                
                $this->view->title = "Sales Order Detail";
            } else {
                $this->flash->error("Order not found");
                return $this->response->redirect('odoo-sales');
            }
        } catch (\Exception $e) {
            $this->flash->error("Error: " . $e->getMessage());
            return $this->response->redirect('odoo-sales');
        }
    }
    
    /**
     * Create customer/partner
     */
    public function createCustomerAction()
    {
        if ($this->request->isPost()) {
            try {
                $name = $this->request->getPost('name');
                $email = $this->request->getPost('email');
                $phone = $this->request->getPost('phone');
                $street = $this->request->getPost('street');
                $city = $this->request->getPost('city');
                
                $customerData = [
                    'name' => $name,
                    'customer_rank' => 1,
                ];
                
                if ($email) $customerData['email'] = $email;
                if ($phone) $customerData['phone'] = $phone;
                if ($street) $customerData['street'] = $street;
                if ($city) $customerData['city'] = $city;
                
                $customerId = $this->odoo->executePublic('res.partner', 'create', [[$customerData]]);
                
                $this->flash->success("✅ Customer berhasil dibuat dengan ID: " . $customerId);
                return $this->response->redirect('odoo-sales/create');
            } catch (\Exception $e) {
                $this->flash->error("Error: " . $e->getMessage());
            }
        }
        
        $this->view->title = "Tambah Customer";
    }
}
