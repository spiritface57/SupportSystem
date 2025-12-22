<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UploadEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'event_name',
        'event_version',
        'upload_id',
        'source',
        'payload',
        'created_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];
}
