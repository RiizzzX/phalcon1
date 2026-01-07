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
    public $price;
    public $created_at;
    public $updated_at;

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
        $validator = new \Phalcon\Filter\Validation();

        $validator->add(
            'name',
            new \Phalcon\Filter\Validation\Validator\PresenceOf(
                [
                    'message' => 'Nama barang wajib diisi',
                ]
            )
        );

        $validator->add(
            'category',
            new \Phalcon\Filter\Validation\Validator\PresenceOf(
                [
                    'message' => 'Kategori wajib diisi',
                ]
            )
        );

        $validator->add(
            'quantity',
            new \Phalcon\Filter\Validation\Validator\Numericality([
                'message' => 'Jumlah harus angka',
                'allowEmpty' => true
            ])
        );

        $validator->add(
            'price',
            new \Phalcon\Filter\Validation\Validator\Numericality([
                'message' => 'Harga harus angka',
                'allowEmpty' => true
            ])
        );

        return $this->validate($validator);
    }
}