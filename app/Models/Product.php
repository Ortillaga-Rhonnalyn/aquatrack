<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $primaryKey = 'product_id';

    protected $fillable = ['product_name', 'description', 'unit_price', 'status'];

    protected function casts(): array
    {
        return ['unit_price' => 'decimal:2'];
    }
}
