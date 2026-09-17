<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only audit trail. NOT tenant-scoped on purpose: it can be queried
 * both per-hotel and by the platform for SOC/compliance reporting. The
 * hotel_id is still written so a per-tenant view is cheap (indexed).
 */
class AuditLog extends Model
{
    use HasFactory;

    public $timestamps = true;

    protected $fillable = [
        'hotel_id', 'user_id', 'action', 'entity_type', 'entity_id',
        'context', 'ip', 'user_agent',
    ];

    protected $casts = [
        'context' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}