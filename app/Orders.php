<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Orders extends Model
{
    //
    protected $fillable = [
        'shop_id',
        'order_id',
        'order_status',
        'order_price',
        'order_currency',
        'order_imported_at'
    ];
}
