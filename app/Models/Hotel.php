<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Hotel is the TENANT of the platform. All tenant data hangs off hotel_id.
 */
class Hotel extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUSES = ['pending', 'active', 'suspended', 'rejected', 'trial'];

    /** Slug of the seeded reference hotel whose catalogue new tenants inherit. */
    public const SAMPLE_HOTEL_SLUG = 'sunuhotel-dakar';

    protected $fillable = [
        'uuid', 'slug', 'name', 'legal_name', 'address', 'city', 'country',
        'phone', 'email', 'website', 'timezone', 'currency', 'tax_rate',
        'check_in_time', 'check_out_time', 'logo_path', 'settings', 'status',
        'trial_ends_at', 'created_by',
    ];

    protected $casts = [
        'settings' => 'array',
        'tax_rate' => 'float',
        'trial_ends_at' => 'datetime',
        'check_in_time' => 'datetime:H:i',
        'check_out_time' => 'datetime:H:i',
    ];

    protected $appends = ['logo_url'];

    public function getLogoUrlAttribute(): ?string
    {
        if (! $this->logo_path) {
            return null;
        }

        return request()->getSchemeAndHttpHost().'/'.ltrim($this->logo_path, '/');
    }

    /**
     * Replace the hotel logo: removes the previous file, drops the new one
     * under public/uploads/hotels/{id} and persists logo_path.
     */
    public function setLogo(UploadedFile $file): void
    {
        if ($this->logo_path) {
            $old = public_path(ltrim($this->logo_path, '/'));
            if (is_file($old)) {
                unlink($old);
            }
        }

        $dir = public_path('uploads/hotels/'.$this->id);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = 'logo.'.$file->getClientOriginalExtension();
        $target = $dir.'/'.$filename;

        // Windows rename() refuses to overwrite an existing file, so use a
        // copy + unlink (works for both fresh and replacement uploads).
        if (! copy($file->getRealPath(), $target)) {
            throw new \RuntimeException('Unable to store the hotel logo.');
        }
        @unlink($file->getRealPath());

        $this->logo_path = '/uploads/hotels/'.$this->id.'/'.$filename;
        $this->save();
    }

    protected static function booted(): void
    {
        static::creating(function (Hotel $hotel) {
            $hotel->uuid ??= (string) Str::uuid();
            $hotel->slug ??= Str::slug($hotel->name);
        });
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function roomTypes()
    {
        return $this->hasMany(RoomType::class);
    }

    public function rooms()
    {
        return $this->hasMany(Room::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function guests()
    {
        return $this->hasMany(Guest::class);
    }

    public function ratePlans()
    {
        return $this->hasMany(RatePlan::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function employees()
    {
        return $this->hasMany(Employee::class);
    }

    public function expenseTypes()
    {
        return $this->hasMany(ExpenseType::class);
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * Find or create the built-in salary expense type shared by the payroll flow.
     */
    public function salaryExpenseType(): ExpenseType
    {
        return $this->expenseTypes()->firstOrCreate(
            ['key' => ExpenseType::KEY_SALARY],
            ['name' => 'Salaire', 'is_active' => true],
        );
    }

    /**
     * Bootstrap a fresh tenant by copying the sample hotel's catalogue:
     * room types and expense types. No-op child rows (rooms, guests, …) are
     * deliberately not imported.
     */
    public function importSetupFrom(Hotel $source): void
    {
        foreach ($source->roomTypes as $roomType) {
            $this->roomTypes()->create([
                'name' => $roomType->name,
                'description' => $roomType->description,
                'base_capacity' => $roomType->base_capacity,
                'max_capacity' => $roomType->max_capacity,
                'base_rate_cents' => $roomType->base_rate_cents,
                'features' => $roomType->features,
                'is_active' => $roomType->is_active,
            ]);
        }

        foreach ($source->expenseTypes as $expenseType) {
            $this->expenseTypes()->create([
                'name' => $expenseType->name,
                'key' => $expenseType->key,
                'color' => $expenseType->color,
                'is_active' => $expenseType->is_active,
            ]);
        }
    }

    /**
     * Booking number hex-style short hash for human reference.
     */
    public function nextBookingNumber(): string
    {
        $seq = (int) ($this->settings['booking_seq'] ?? 0) + 1;

        $this->settings = array_merge($this->settings ?? [], ['booking_seq' => $seq]);
        $this->saveQuietly();

        return strtoupper(substr($this->slug, 0, 6)).'-'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }
}