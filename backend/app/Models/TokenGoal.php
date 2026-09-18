<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TokenGoal extends Model
{
    protected $fillable = ['provider', 'daily_tokens', 'updated_by'];

    protected $casts = ['daily_tokens' => 'integer'];
}
