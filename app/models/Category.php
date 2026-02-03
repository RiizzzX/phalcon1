<?php
namespace App\Models;

use Phalcon\Mvc\Model;

class Category extends Model
{
    public $id;
    public $name;
    public $description;
    public $created_at;

    public function initialize()
    {
        $this->setSource('categories');
    }

    public function beforeCreate()
    {
        $this->created_at = date('Y-m-d H:i:s');
    }
}
