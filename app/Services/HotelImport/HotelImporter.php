<?php

namespace App\Services\HotelImport;

use App\Models\Hotel;
use App\Models\HotelImport;
use App\Models\HotelImportIssue;
use Illuminate\Support\Str;

/**
 * Creates hotel records from a staged, mapped workbook.
 *
 * Design notes for the "must support large files" requirement:
 *  - the workbook is streamed row by row (HotelExcelReader), never loaded whole
 *  - hotels are written with chunked bulk inserts instead of one INSERT per row
 *  - duplicate lookups use chunkById against (name, slug) rather than a query
 *    per row, and the seen-sets grow with the file, not with a per-row roundtrip
 *
 * Every row lands with status = 'pending'; activation only happens through the
 * normal approval workflow.
 */
final class HotelImporter
{
    public function __construct(private readonly HotelExcelReader $reader) {}

    /**
     * @param  array<int, string>  $mapping  column index => hotel field key
     * @return array<string, mixed> import summary
     */
    public function import(HotelImport $import, array $mapping): array
    {
        $path = (string) $import->stored_path;

        $existingSlugs = [];
        $existingNames = [];
        Hotel::withTrashed()
            ->select('id', 'name', 'slug')
            ->chunkById(500, function ($hotels) use (&$existingSlugs, &$existingNames): void {
                foreach ($hotels as $hotel) {
                    $existingSlugs[mb_strtolower((string) $hotel->slug)] = true;
                    $existingNames[mb_strtolower(trim((string) $hotel->name))] = true;
                }
            });

        $chunkSize = max(1, (int) config('hotel_import.chunk_size', 250));
        $maxIssues = max(0, (int) config('hotel_import.max_reported_issues', 200));

        $buffer = [];
        $issues = [];
        $created = 0;
        $duplicates = 0;
        $skipped = 0;
        $duplicateSamples = [];
        $errorSamples = [];

        $flushHotels = function () use (&$buffer, &$created): void {
            if ($buffer === []) {
                return;
            }
            Hotel::query()->insert($buffer);
            $created += count($buffer);
            $buffer = [];
        };

        $flushIssues = function () use (&$issues): void {
            if ($issues === []) {
                return;
            }
            HotelImportIssue::query()->insert($issues);
            $issues = [];
        };

        $headers = array_map(
            fn (array $header): array => ['index' => (int) $header['index'], 'label' => (string) $header['label']],
            (array) ($import->headers ?? [])
        );

        $stream = $this->reader->streamRows(
            $path,
            $headers,
            function (int $rowNumber, array $values) use (
                $import, $mapping, &$buffer, &$issues, &$created, &$duplicates, &$skipped,
                &$duplicateSamples, &$errorSamples, &$existingSlugs, &$existingNames,
                $chunkSize, $maxIssues, $flushHotels, $flushIssues
            ): void {
                $payload = [];
                foreach ($mapping as $columnIndex => $field) {
                    $payload[$field] = (string) ($values[$columnIndex] ?? '');
                }

                [$attributes, $errors] = $this->coerceRow($payload);

                if ($errors !== []) {
                    $skipped++;
                    if (count($errorSamples) < 20) {
                        $errorSamples[] = ['row' => $rowNumber, 'reasons' => $errors];
                    }
                    if (count($issues) < $maxIssues) {
                        $issues[] = [
                            'hotel_import_id' => $import->id,
                            'row_number' => $rowNumber,
                            'kind' => HotelImportIssue::KIND_INVALID,
                            'reason' => implode(' ', $errors),
                            'data' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }

                    return;
                }

                $name = trim($attributes['name']);
                $nameKey = mb_strtolower($name);
                $requestedSlug = trim((string) ($attributes['slug'] ?? ''));
                $baseSlug = $requestedSlug !== '' ? Str::slug($requestedSlug) : Str::slug($name);

                if ($baseSlug === '') {
                    $baseSlug = 'hotel';
                }

                $isDuplicate = isset($existingNames[$nameKey]) || isset($existingSlugs[mb_strtolower($baseSlug)]);

                // Slug is UNIQUE in the database, so a duplicate must still get
                // a unique slug. The row is imported either way; it is only
                // flagged for a human decision.
                $slug = $baseSlug;
                while (isset($existingSlugs[mb_strtolower($slug)])) {
                    $slug = $baseSlug.'-'.Str::lower(Str::random(4));
                }

                if ($isDuplicate) {
                    $duplicates++;
                    if (count($duplicateSamples) < 20) {
                        $duplicateSamples[] = [
                            'row' => $rowNumber,
                            'name' => $name,
                            'slug' => $slug,
                            'match' => isset($existingNames[$nameKey]) ? 'existing_name' : 'existing_slug',
                        ];
                    }
                    if (count($issues) < $maxIssues) {
                        $issues[] = [
                            'hotel_import_id' => $import->id,
                            'row_number' => $rowNumber,
                            'kind' => HotelImportIssue::KIND_DUPLICATE,
                            'reason' => isset($existingNames[$nameKey])
                                ? "A hotel named '{$name}' already exists."
                                : "The slug '{$baseSlug}' is already taken.",
                            'data' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                }

                $existingSlugs[mb_strtolower($slug)] = true;
                $existingNames[$nameKey] = true;

                $now = now();
                $buffer[] = [
                    'uuid' => (string) Str::uuid(),
                    'slug' => $slug,
                    'name' => $name,
                    'legal_name' => $attributes['legal_name'] ?? null,
                    'address' => $attributes['address'] ?? null,
                    'city' => $attributes['city'] ?? null,
                    'country' => $attributes['country'] ?? null,
                    'phone' => $attributes['phone'] ?? null,
                    'phone_2' => $attributes['phone_2'] ?? null,
                    'email' => $attributes['email'] ?? null,
                    'website' => $attributes['website'] ?? null,
                    'description' => $attributes['description'] ?? null,
                    'comment' => $attributes['comment'] ?? null,
                    'other_services' => $attributes['other_services'] ?? null,
                    'stars' => $attributes['stars'] ?? null,
                    'currency' => $attributes['currency'] ?? 'USD',
                    'timezone' => $attributes['timezone'] ?? 'UTC',
                    'tax_rate' => $attributes['tax_rate'] ?? 0,
                    'check_in_time' => $attributes['check_in_time'] ?? '15:00',
                    'check_out_time' => $attributes['check_out_time'] ?? '11:00',
                    'settings' => json_encode(['locale' => 'en']),
                    // Imported hotels are NEVER active on creation: they wait in
                    // "pending" for the normal approval workflow.
                    'status' => Hotel::STATUS_PENDING,
                    'created_by' => $import->user_id,
                    'wallet_balance_cents' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (count($buffer) >= $chunkSize) {
                    $flushHotels();
                }

                if (count($issues) >= $chunkSize) {
                    $flushIssues();
                }
            }
        );

        $flushHotels();
        $flushIssues();

        $summary = [
            'total_rows' => (int) $import->total_rows,
            'processed_rows' => $stream['processed'],
            'created' => $created,
            'duplicates' => $duplicates,
            'skipped' => $skipped,
            'errors' => $skipped,
            'truncated' => (bool) $stream['truncated'],
            'status_after_import' => 'pending',
            'duplicate_samples' => $duplicateSamples,
            'error_samples' => $errorSamples,
        ];

        $import->forceFill([
            'status' => HotelImport::STATUS_COMPLETED,
            'mapped_fields' => count($mapping),
            'created_count' => $created,
            'duplicate_count' => $duplicates,
            'skipped_count' => $skipped,
            'error_count' => $skipped,
            'mapping' => $mapping,
            'summary' => $summary,
        ])->save();

        return $summary;
    }

    /**
     * Turn raw cell strings into column values for the hotels table, collecting
     * per-row validation errors instead of failing the whole file.
     *
     * @param  array<string, string>  $payload
     * @return array{0: array<string, mixed>, 1: array<int, string>}
     */
    private function coerceRow(array $payload): array
    {
        $errors = [];
        $out = [];

        foreach ($payload as $field => $raw) {
            $raw = trim((string) $raw);
            $type = HotelImportFieldMap::type($field);
            $max = HotelImportFieldMap::maxLength($field);

            if ($raw === '') {
                $out[$field] = null;

                continue;
            }

            if (mb_strlen($raw) > $max) {
                $errors[] = ucfirst(str_replace('_', ' ', $field))." is longer than {$max} characters.";

                continue;
            }

            switch ($type) {
                case 'email':
                    if (! filter_var($raw, FILTER_VALIDATE_EMAIL)) {
                        $errors[] = "Invalid email address '{$raw}'.";
                    } else {
                        $out[$field] = $raw;
                    }
                    break;

                case 'url':
                    $url = preg_match('#^https?://#i', $raw) === 1 ? $raw : 'https://'.$raw;
                    if (! filter_var($url, FILTER_VALIDATE_URL)) {
                        $errors[] = "Invalid website URL '{$raw}'.";
                    } else {
                        $out[$field] = $url;
                    }
                    break;

                case 'country':
                    $code = strtoupper($raw);
                    if (preg_match('/^[A-Z]{2}$/', $code) !== 1) {
                        $errors[] = "Country '{$raw}' must be a 2-letter code.";
                    } else {
                        $out[$field] = $code;
                    }
                    break;

                case 'currency':
                    $code = strtoupper($raw);
                    if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
                        $errors[] = "Currency '{$raw}' must be a 3-letter code.";
                    } else {
                        $out[$field] = $code;
                    }
                    break;

                case 'timezone':
                    if (! in_array($raw, timezone_identifiers_list(), true)) {
                        $errors[] = "Unknown timezone '{$raw}'.";
                    } else {
                        $out[$field] = $raw;
                    }
                    break;

                case 'int':
                    $number = $this->toInt($raw);
                    if ($number === null) {
                        $errors[] = "Invalid number '{$raw}'.";
                    } else {
                        $out[$field] = $number;
                    }
                    break;

                case 'decimal':
                    $number = $this->toFloat($raw);
                    if ($number === null) {
                        $errors[] = "Invalid number '{$raw}'.";
                    } else {
                        $out[$field] = $number;
                    }
                    break;

                case 'time':
                    $time = $this->toTime($raw);
                    if ($time === null) {
                        $errors[] = "Invalid time '{$raw}'. Use HH:MM.";
                    } else {
                        $out[$field] = $time;
                    }
                    break;

                default:
                    $out[$field] = $raw;
            }
        }

        // `stars` is the only bounded integer in the catalogue.
        if (array_key_exists('stars', $out) && $out['stars'] !== null) {
            if ($out['stars'] < 1 || $out['stars'] > 5) {
                $errors[] = 'Stars must be between 1 and 5.';
                $out['stars'] = null;
            }
        }

        if (array_key_exists('tax_rate', $out) && $out['tax_rate'] !== null) {
            if ($out['tax_rate'] < 0 || $out['tax_rate'] > 100) {
                $errors[] = 'Tax rate must be between 0 and 100.';
                $out['tax_rate'] = null;
            }
        }

        if (trim((string) ($out['name'] ?? '')) === '') {
            array_unshift($errors, 'Hotel name is required.');
        }

        return [$out, $errors];
    }

    private function toInt(string $raw): ?int
    {
        $normalized = str_replace([' ', ','], '', trim($raw));

        if (preg_match('/^-?\d+(\.0+)?$/', $normalized) === 1) {
            return (int) (float) $normalized;
        }

        return null;
    }

    private function toFloat(string $raw): ?float
    {
        $normalized = str_replace([' ', '%', ','], [' ', '', '.'], trim($raw));
        $normalized = trim($normalized);

        if (preg_match('/^-?\d+(\.\d+)?$/', $normalized) !== 1) {
            return null;
        }

        return (float) $normalized;
    }

    /**
     * Accept "15:00", "15:00:00", "15h00" and Excel's 0.625 day fraction.
     */
    private function toTime(string $raw): ?string
    {
        $value = trim($raw);

        if (preg_match('/^(\d{1,2})\s*[:hH]\s*(\d{1,2})(?::(\d{1,2}))?$/', $value, $m) === 1) {
            $hour = (int) $m[1];
            $minute = (int) $m[2];
            $second = isset($m[3]) ? (int) $m[3] : 0;

            if ($hour > 23 || $minute > 59 || $second > 59) {
                return null;
            }

            return sprintf('%02d:%02d:%02d', $hour, $minute, $second);
        }

        if (preg_match('/^\d+(\.\d+)?$/', $value) === 1) {
            $fraction = (float) $value;
            if ($fraction >= 0 && $fraction < 1) {
                $seconds = (int) round($fraction * 86400);

                return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
            }
        }

        return null;
    }
}
