<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryTransaction extends Model
{
    protected $primaryKey = 'transaction_id';

    protected $fillable = ['inventory_id', 'transaction_type', 'quantity', 'transaction_date', 'reference', 'recorded_by'];

    protected function casts(): array
    {
        return ['transaction_date' => 'datetime'];
    }
}
