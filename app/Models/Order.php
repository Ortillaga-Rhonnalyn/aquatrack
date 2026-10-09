<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    protected $primaryKey = 'order_id';

    protected $fillable = ['customer_id', 'order_date', 'total_amount', 'order_status', 'created_by'];

    protected function casts(): array
    {
        return ['order_date' => 'datetime', 'total_amount' => 'decimal:2'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
    }
}
