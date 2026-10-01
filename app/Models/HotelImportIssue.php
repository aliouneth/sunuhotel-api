<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Row-level outcome of a hotel import that needs a human decision:
 *
 *  - duplicate: the row matched an existing hotel or an earlier row in the same
 *    file. These ARE still imported (flagged only), per the import contract.
 *  - invalid: the row failed field validation and was skipped.
 */
class HotelImportIssue extends Model
{
    use HasFactory;

    public const KIND_DUPLICATE = 'duplicate';

    public const KIND_INVALID = 'invalid';

    protected $fillable = [
        'hotel_import_id', 'row_number', 'kind', 'reason', 'data',
    ];

    protected $casts = [
        'data' => 'array',
        'row_number' => 'integer',
    ];

    public function hotelImport(): BelongsTo
    {
        return $this->belongsTo(HotelImport::class);
    }
}
