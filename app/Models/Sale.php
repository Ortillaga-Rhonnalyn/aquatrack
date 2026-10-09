<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    protected $primaryKey = 'sale_id';

    protected function casts(): array
    {
        return ['sale_date' => 'datetime', 'total_amount' => 'decimal:2'];
    }
}
