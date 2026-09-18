<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiModelPrice extends Model
{
    protected $fillable = ['model', 'input_price_per_million', 'cached_input_price_per_million', 'output_price_per_million', 'valid_from', 'valid_until'];

    protected $casts = [
        'valid_from' => 'date',
        'valid_until' => 'date',
        'input_price_per_million' => 'decimal:6',
        'cached_input_price_per_million' => 'decimal:6',
        'output_price_per_million' => 'decimal:6',
    ];
}
