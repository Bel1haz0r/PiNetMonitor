<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $table = 'settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['value' => 'array'];

    /** All settings merged over the defaults in config/monitor.php. */
    public static function allMerged(): array
    {
        $stored = static::query()->pluck('value', 'key')->map(fn ($v) => is_string($v) ? json_decode($v, true) : $v)->all();
        $defaults = config('monitor.defaults');

        return array_merge($defaults, array_intersect_key($stored, $defaults));
    }

    public static function put(array $values): void
    {
        foreach (array_intersect_key($values, config('monitor.defaults')) as $key => $value) {
            static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
