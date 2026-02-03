<?php

namespace App\Controllers;

class OdooCustomersController extends OdooControllerBase
{
    public function indexAction()
    {
        $this->view->title = "Kelola Customers";
    }
}
