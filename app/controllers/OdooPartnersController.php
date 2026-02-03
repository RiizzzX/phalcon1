<?php

namespace App\Controllers;

class OdooPartnersController extends OdooControllerBase
{
    public function indexAction()
    {
        $this->view->title = 'Kelola Partners';
    }
}
