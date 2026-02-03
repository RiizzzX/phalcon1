<?php
namespace App\Models;

use Phalcon\Mvc\Model;

class Inventory extends Model
{
    public $id;
    public $name;
    public $description;
    public $quantity;
    public $category;
    public $selling_price;
    public $created_at;
    public $updated_at;
    
    // New fields from migration
    public $odoo_id;
    public $sku;
    public $product_type;
    public $cost_price;
    public $status;
    public $synced_to_odoo;
    public $last_sync_at;
    public $sync_notes;

    public function initialize()
    {
        $this->setSource('inventory');
    }

    public function beforeCreate()
    {
        $this->created_at = date('Y-m-d H:i:s');
        $this->updated_at = date('Y-m-d H:i:s');
    }

    public function beforeUpdate()
    {
        $this->updated_at = date('Y-m-d H:i:s');
    }

    public function validation()
    {
        // Validation disabled - use simple form validation instead
        return true;
    }
}