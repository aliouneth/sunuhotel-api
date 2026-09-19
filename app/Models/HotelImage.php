<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;

class HotelImage extends Model
{
    use HasFactory;

    protected $fillable = ['hotel_id', 'image_path', 'sort_order'];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    protected $appends = ['image_url'];

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image_path) {
            return null;
        }

        return request()->getSchemeAndHttpHost().'/'.ltrim($this->image_path, '/');
    }

    public function setImage(UploadedFile $file, int $hotelId, int $sortOrder): void
    {
        $dir = public_path('uploads/hotels/'.$hotelId);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = $hotelId.'_'.time().'_'.random_int(1000, 9999).'.'.$file->getClientOriginalExtension();
        $target = $dir.'/'.$filename;

        if (! copy($file->getRealPath(), $target)) {
            throw new \RuntimeException('Unable to store the hotel image.');
        }
        @unlink($file->getRealPath());

        $this->hotel_id = $hotelId;
        $this->image_path = '/uploads/hotels/'.$hotelId.'/'.$filename;
        $this->sort_order = $sortOrder;
        $this->save();
    }
}
