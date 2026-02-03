<?php

namespace App\Controllers;

class OdooSuppliersController extends OdooControllerBase
{
    public function indexAction()
    {
        $this->view->title = "Kelola Suppliers";
    }
}
