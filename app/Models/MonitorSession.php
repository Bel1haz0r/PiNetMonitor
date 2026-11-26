<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonitorSession extends Model
{
    protected $table = 'monitor_sessions';

    protected $guarded = [];

    public $timestamps = true;

    protected $casts = ['started_at' => 'datetime', 'ended_at' => 'datetime', 'last_sample_at' => 'datetime', 'wireless' => 'boolean'];
}
