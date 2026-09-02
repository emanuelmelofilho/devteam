<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CodexSetting extends Model
{
    protected $fillable = ['api_key', 'updated_by'];

    protected $hidden = ['api_key'];

    protected $casts = ['api_key' => 'encrypted'];
}
