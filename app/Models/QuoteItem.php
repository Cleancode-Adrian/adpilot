<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuoteItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['quote_id', 'position', 'name', 'description', 'quantity', 'unit', 'unit_price', 'recurring'];

    protected $casts = ['quantity' => 'float', 'unit_price' => 'float'];
}
