<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Delivery extends Model
{
    protected $primaryKey = 'delivery_id';

    protected $fillable = ['order_id', 'delivery_address', 'delivery_date', 'assigned_to', 'delivery_status', 'notes'];

    protected function casts(): array
    {
        return ['delivery_date' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id', 'order_id');
    }
}
