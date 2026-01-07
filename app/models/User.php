<?php
use Phalcon\Mvc\Model;

class User extends Model
{
    public $id;
    public $name;
    public $email;

    // Tentukan nama tabel
    public function initialize()
    {
        $this->setSource("users");
    }
}