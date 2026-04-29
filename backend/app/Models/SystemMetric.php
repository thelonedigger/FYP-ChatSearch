<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemMetric extends Model
{
    public $timestamps = false;
    protected $fillable = ['metric_type', 'metric_name', 'value', 'unit', 'metadata', 'created_at'];
    protected $casts = ['value' => 'float', 'metadata' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void { static::creating(fn($m) => $m->created_at ??= now()); }
}