<?php

use Phalcon\Mvc\Model;

class RentalLog extends Model
{
    public $id;
    public $odoo_rental_id;
    public $rental_number;
    public $customer_name;
    public $customer_phone;
    public $equipment_name;
    public $equipment_code;
    public $rental_date;
    public $return_date;
    public $total_amount;
    public $status;
    public $created_at;
    public $synced_at;

    public function initialize()
    {
        $this->setSource('rental_logs');
    }

    /**
     * Get all rental logs
     */
    public static function getAllRentals()
    {
        return self::find([
            'order' => 'created_at DESC'
        ]);
    }

    /**
     * Get rental by Odoo ID
     */
    public static function findByOdooId($odooId)
    {
        return self::findFirst([
            'conditions' => 'odoo_rental_id = :id:',
            'bind' => ['id' => $odooId]
        ]);
    }

    /**
     * Sync rental from Odoo to MySQL
     */
    public static function syncFromOdoo($rentalData)
    {
        $log = self::findByOdooId($rentalData['id']);
        
        if (!$log) {
            $log = new RentalLog();
            $log->odoo_rental_id = $rentalData['id'];
        }
        
        $log->rental_number = $rentalData['name'];
        $log->customer_name = $rentalData['customer_name'];
        $log->customer_phone = $rentalData['customer_phone'];
        
        // Extract equipment name from array or use directly
        if (is_array($rentalData['equipment_id'])) {
            $log->equipment_name = $rentalData['equipment_id'][1];
        } else {
            $log->equipment_name = 'Equipment #' . $rentalData['equipment_id'];
        }
        
        $log->rental_date = $rentalData['rental_date'];
        $log->return_date = $rentalData['return_date'];
        $log->total_amount = $rentalData['total_amount'];
        $log->status = $rentalData['state'];
        
        return $log->save();
    }
}
