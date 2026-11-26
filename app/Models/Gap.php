<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Gap extends Model
{
    protected $table = 'gaps';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['started_at' => 'datetime', 'ended_at' => 'datetime'];
}
