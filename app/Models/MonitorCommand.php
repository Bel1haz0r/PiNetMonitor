<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonitorCommand extends Model
{
    protected $table = 'monitor_commands';

    protected $guarded = [];

    public $timestamps = true;

    protected $casts = ['payload' => 'array'];
}
