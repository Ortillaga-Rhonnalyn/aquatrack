<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inventory extends Model
{
    protected $table = 'inventory';

    protected $primaryKey = 'inventory_id';

    protected $fillable = ['item_name', 'item_type', 'quantity', 'reorder_level', 'unit', 'status'];
}
