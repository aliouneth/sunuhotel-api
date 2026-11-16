<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An anonymous public-page view used by the platform audience dashboard.
 *
 * Rows are append-only and carry no direct identifier: visitor_hash is a daily
 * HMAC of request metadata, not an IP address or a cookie.
 */
class PageView extends Model
{
    protected $table = 'page_views';

    public $timestamps = false;

    protected $fillable = [
        'path',
        'referrer_host',
        'visitor_hash',
        'viewed_on',
        'created_at',
    ];

    protected $casts = [
        'viewed_on' => 'date',
        'created_at' => 'datetime',
    ];
}
