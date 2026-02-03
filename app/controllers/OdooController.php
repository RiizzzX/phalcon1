<?php
namespace App\Controllers;

class OdooController extends OdooControllerBase
{
    /**
     * Simple landing - redirect to canonical dashboard
     */
    public function indexAction()
    {
        return $this->response->redirect('/odoo-dashboard');
    }

    /**
     * Before any action: if the requested action doesn't exist on this class,
     * forward to a logical Odoo controller (inventory/purchase/sales/etc.) or to the dashboard.
     */
    public function beforeExecuteRoute($dispatcher)
    {
        $action = $dispatcher->getActionName();
        // If this controller has the action method, let it continue
        if ($action && method_exists($this, $action . 'Action')) {
            return true;
        }

        // Mapping of friendly action names to existing controllers
        $fallbackMap = [
            'inventory' => ['controller' => 'odooInventory', 'action' => 'index'],
            'purchase'  => ['controller' => 'odooPurchase',  'action' => 'index'],
            'sales'     => ['controller' => 'odooSales',     'action' => 'index'],
            'invoicing' => ['controller' => 'odooInvoicing','action' => 'index'],
            'equipment' => ['controller' => 'odooEquipment','action' => 'index'],
            'dashboard' => ['controller' => 'odooDashboard','action' => 'index'],
        ];

        if ($action && isset($fallbackMap[strtolower($action)])) {
            $target = $fallbackMap[strtolower($action)];
            $dispatcher->forward([
                'controller' => $target['controller'],
                'action'     => $target['action'],
            ]);
            // return false to stop current dispatch
            return false;
        }

        // Default fallback: dashboard
        $dispatcher->forward([
            'controller' => 'odooDashboard',
            'action'     => 'index',
        ]);

        return false;
    }

    // Explicit action handlers to prevent "Action not found on handler 'odoo'" errors
    public function dashboardAction()
    {
        // Redirect to canonical dashboard URL
        return $this->response->redirect('/odoo-dashboard');
    }

    public function inventoryAction()
    {
        return $this->response->redirect('/odoo-inventory');
    }

    public function purchaseAction()
    {
        return $this->response->redirect('/odoo-purchase');
    }

    public function salesAction()
    {
        return $this->response->redirect('/odoo-sales');
    }

    public function invoicingAction()
    {
        return $this->response->redirect('/odoo-invoicing');
    }

    public function equipmentAction()
    {
        return $this->response->redirect('/odoo-equipment');
    }
}
