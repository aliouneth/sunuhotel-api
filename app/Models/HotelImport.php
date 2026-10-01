<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One platform hotel Excel import action (audit record).
 *
 * Status flow: staged (file uploaded + headers detected) -> completed
 * (hotels created) or failed (mapping invalid / parse error).
 */
class HotelImport extends Model
{
    use HasFactory;

    public const STATUS_STAGED = 'staged';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'token', 'user_id', 'original_name', 'stored_path', 'status',
        'total_rows', 'mapped_fields', 'created_count', 'duplicate_count',
        'skipped_count', 'error_count', 'headers', 'mapping', 'summary', 'ip',
    ];

    protected $casts = [
        'headers' => 'array',
        'mapping' => 'array',
        'summary' => 'array',
        'total_rows' => 'integer',
        'mapped_fields' => 'integer',
        'created_count' => 'integer',
        'duplicate_count' => 'integer',
        'skipped_count' => 'integer',
        'error_count' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(HotelImportIssue::class);
    }
}
