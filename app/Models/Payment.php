<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $primaryKey = 'payment_id';

    protected $fillable = ['order_id', 'amount_paid', 'payment_method', 'payment_status', 'payment_date', 'received_by'];

    protected function casts(): array
    {
        return ['amount_paid' => 'decimal:2', 'payment_date' => 'datetime'];
    }
}
