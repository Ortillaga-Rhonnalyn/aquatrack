<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    protected $primaryKey = 'log_id';

    protected $fillable = ['user_id', 'action', 'table_name', 'record_id', 'log_date'];

    protected function casts(): array
    {
        return ['log_date' => 'datetime'];
    }
}
