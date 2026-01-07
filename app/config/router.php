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

// Inventory routes (pakai IndexController)
$router->add('/inventory', [
    'controller' => 'index',
    'action' => 'index'
]);

$router->add('/inventory/index', [
    'controller' => 'index',
    'action' => 'index'
])->setName('inventory_index');

$router->add('/inventory/list', [
    'controller' => 'index',
    'action' => 'list'
])->setName('inventory_list');

$router->add('/inventory/add', [
    'controller' => 'index',
    'action' => 'add'
])->via(['POST'])->setName('inventory_add');

$router->add('/inventory/edit/{id:[0-9]+}', [
    'controller' => 'index',
    'action' => 'edit'
])->setName('inventory_edit');

$router->add('/inventory/edit/{id:[0-9]+}', [
    'controller' => 'index',
    'action' => 'edit'
])->via(['POST'])->setName('inventory_edit_post');

$router->add('/inventory/delete/{id:[0-9]+}', [
    'controller' => 'index',
    'action' => 'delete'
])->setName('inventory_delete');

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

// Catch-all route for 404 errors

$router->notFound([
    'controller' => 'index',
    'action' => 'index'
]);

return $router;