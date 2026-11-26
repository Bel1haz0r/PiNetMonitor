<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpeedTest extends Model
{
    protected $table = 'speed_tests';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['measured_at' => 'datetime', 'wireless' => 'boolean'];
}
