<?php
use Phalcon\Mvc\Router;

$router = new Router(false);

// Disable default routes
$router->removeExtraSlashes(true);

// Landing page (home)
$router->add('/', [
    'controller' => 'index',
    'action' => 'index'
])->setName('home');

// Inventory routes (pakai InventoryController)
$router->add('/inventory', [
    'controller' => 'inventory',
    'action' => 'index'
]);

$router->add('/inventory/index', [
    'controller' => 'inventory',
    'action' => 'index'
])->setName('inventory_index');

$router->add('/inventory/list', [
    'controller' => 'inventory',
    'action' => 'list'
])->setName('inventory_list');

$router->add('/inventory/add', [
    'controller' => 'inventory',
    'action' => 'add'
])->setName('inventory_add');

$router->add('/inventory/add', [
    'controller' => 'inventory',
    'action' => 'add'
])->via(['POST'])->setName('inventory_add_post');

$router->add('/inventory/edit/{id:[0-9]+}', [
    'controller' => 'inventory',
    'action' => 'edit'
])->setName('inventory_edit');

$router->add('/inventory/edit/{id:[0-9]+}', [
    'controller' => 'inventory',
    'action' => 'edit'
])->via(['POST'])->setName('inventory_edit_post');

$router->add('/inventory/delete/{id:[0-9]+}', [
    'controller' => 'inventory',
    'action' => 'delete'
])->setName('inventory_delete');

$router->add('/inventory/delete/{id:[0-9]+}', [
    'controller' => 'inventory',
    'action' => 'delete'
])->via(['POST'])->setName('inventory_delete_post');

$router->add('/inventory/search', [
    'controller' => 'inventory',
    'action' => 'search'
])->setName('inventory_search');

$router->add('/inventory/update-stock/{id:[0-9]+}', [
    'controller' => 'index',
    'action' => 'updateStock'
])->via(['POST'])->setName('inventory_update_stock');

$router->add('/inventory/search', [
    'controller' => 'index',
    'action' => 'search'
])->via(['GET'])->setName('inventory_search');

// Index controller routes (direct paths)
$router->add('/index/list', [
    'controller' => 'index',
    'action' => 'list'
])->setName('index_list');

$router->add('/index/add', [
    'controller' => 'index',
    'action' => 'add'
])->via(['POST'])->setName('index_add');

$router->add('/index/edit/{id:[0-9]+}', [
    'controller' => 'index',
    'action' => 'edit'
])->setName('index_edit');

$router->add('/index/delete/{id:[0-9]+}', [
    'controller' => 'index',
    'action' => 'delete'
])->setName('index_delete');

$router->add('/index/updateStock/{id:[0-9]+}', [
    'controller' => 'index',
    'action' => 'updateStock'
])->via(['POST'])->setName('index_update_stock');

$router->add('/index/search', [
    'controller' => 'index',
    'action' => 'search'
])->via(['GET'])->setName('index_search');

$router->add('/index/getStats', [
    'controller' => 'index',
    'action' => 'getStats'
])->setName('index_get_stats');

// Odoo Dashboard
$router->add('/odoo-dashboard', [
    'controller' => 'odooDashboard',
    'action' => 'index'
])->setName('odoo_dashboard');

$router->add('/odoo-dashboard/install-modules', [
    'controller' => 'odooDashboard',
    'action' => 'installModules'
])->setName('odoo_install_modules');

// Odoo Equipment routes
$router->add('/odoo-equipment', [
    'controller' => 'odooEquipment',
    'action' => 'index'
])->setName('odoo_equipment_index');

$router->add('/odoo-equipment/create', [
    'controller' => 'odooEquipment',
    'action' => 'create'
])->setName('odoo_equipment_create');

$router->add('/odoo-equipment/view/{id:[0-9]+}', [
    'controller' => 'odooEquipment',
    'action' => 'view'
])->setName('odoo_equipment_view');

$router->add('/odoo-equipment/rent/{id:[0-9]+}', [
    'controller' => 'odooEquipment',
    'action' => 'rent'
])->setName('odoo_equipment_rent');

$router->add('/odoo-equipment/rentals', [
    'controller' => 'odooEquipment',
    'action' => 'rentals'
])->setName('odoo_equipment_rentals');

$router->add('/odoo-equipment/logs', [
    'controller' => 'odooEquipment',
    'action' => 'logs'
])->setName('odoo_equipment_logs');

// Odoo Purchase routes
$router->add('/odoo-purchase', [
    'controller' => 'odooPurchase',
    'action' => 'index'
])->setName('odoo_purchase_index');

// Specific routes FIRST before generic /create
$router->add('/odoo-purchase/create-supplier', [
    'controller' => 'odooPurchase',
    'action' => 'createSupplier'
])->setName('odoo_purchase_create_supplier');

$router->add('/odoo-purchase/create', [
    'controller' => 'odooPurchase',
    'action' => 'create'
])->setName('odoo_purchase_create');

$router->add('/odoo-purchase/view/{id:[0-9]+}', [
    'controller' => 'odooPurchase',
    'action' => 'view'
])->setName('odoo_purchase_view');

// Odoo Inventory routes
$router->add('/odoo-inventory', [
    'controller' => 'odooInventory',
    'action' => 'index'
])->setName('odoo_inventory_index');

$router->add('/odoo-inventory/movements', [
    'controller' => 'odooInventory',
    'action' => 'movements'
])->setName('odoo_inventory_movements');

$router->add('/odoo-inventory/create-product', [
    'controller' => 'odooInventory',
    'action' => 'createProduct'
])->setName('odoo_inventory_create');

$router->add('/odoo-inventory/view/{id:[0-9]+}', [
    'controller' => 'odooInventory',
    'action' => 'view'
])->setName('odoo_inventory_view');

$router->add('/odoo-inventory/edit/{id:[0-9]+}', [
    'controller' => 'odooInventory',
    'action' => 'edit'
])->setName('odoo_inventory_edit');

$router->add('/odoo-inventory/update-stock/{id:[0-9]+}', [
    'controller' => 'odooInventory',
    'action' => 'updateStock'
])->setName('odoo_inventory_update_stock');

// Odoo Sales routes
$router->add('/odoo-sales', [
    'controller' => 'odooSales',
    'action' => 'index'
])->setName('odoo_sales_index');

// Specific routes FIRST
$router->add('/odoo-sales/create-customer', [
    'controller' => 'odooSales',
    'action' => 'createCustomer'
])->setName('odoo_sales_create_customer');

$router->add('/odoo-sales/create', [
    'controller' => 'odooSales',
    'action' => 'create'
])->setName('odoo_sales_create');

$router->add('/odoo-sales/view/{id:[0-9]+}', [
    'controller' => 'odooSales',
    'action' => 'view'
])->setName('odoo_sales_view');

// Odoo Invoicing routes
$router->add('/odoo-invoicing', [
    'controller' => 'odooInvoicing',
    'action' => 'index'
])->setName('odoo_invoicing_index');

$router->add('/odoo-invoicing/create', [
    'controller' => 'odooInvoicing',
    'action' => 'create'
])->setName('odoo_invoicing_create');

$router->add('/odoo-invoicing/view/{id:[0-9]+}', [
    'controller' => 'odooInvoicing',
    'action' => 'view'
])->setName('odoo_invoicing_view');

// Catch-all route for 404 errors

$router->notFound([
    'controller' => 'index',
    'action' => 'index'
]);

return $router;