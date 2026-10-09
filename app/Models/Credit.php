<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Credit extends Model
{
    protected $primaryKey = 'credit_id';

    protected $fillable = ['customer_id', 'sale_id', 'credit_date', 'credit_amount', 'amount_paid', 'remaining_balance', 'due_date', 'credit_status', 'notes'];

    protected function casts(): array
    {
        return [
            'credit_date' => 'datetime',
            'due_date' => 'date',
            'credit_amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'remaining_balance' => 'decimal:2',
        ];
    }
}
