<?php

namespace App\Services\HotelImport;

use Illuminate\Support\Str;

/**
 * The catalogue of hotel fields an uploaded column can be mapped onto, plus the
 * heuristics used to pre-select a mapping from the detected header labels.
 *
 * `status` is deliberately NOT mappable: imported hotels must always land on
 * "pending approval" so this screen can never activate a tenant by accident.
 */
final class HotelImportFieldMap
{
    /**
     * key => [label, required, type, aliases]
     *
     * type drives cell coercion in the importer (text|email|url|int|decimal|
     * country|currency|timezone|time).
     *
     * @var array<string, array{label: string, required: bool, type: string, aliases: array<int, string>}>
     */
    private const TARGETS = [
        'name' => [
            'label' => 'Hotel name',
            'required' => true,
            'type' => 'text',
            'aliases' => ['hotel name', 'name', 'hotel', 'nom', 'nom de l hotel', 'nom hotel', 'establishment', 'property name'],
        ],
        'legal_name' => [
            'label' => 'Legal name',
            'required' => false,
            'type' => 'text',
            'aliases' => ['legal name', 'legalname', 'registered name', 'company name', 'raison sociale', 'nom legal'],
        ],
        'slug' => [
            'label' => 'URL slug',
            'required' => false,
            'type' => 'text',
            'aliases' => ['slug', 'url slug', 'permalink', 'code'],
        ],
        'address' => [
            'label' => 'Address',
            'required' => false,
            'type' => 'text',
            'aliases' => ['address', 'adresse', 'street address', 'street', 'location', 'adresse complete'],
        ],
        'city' => [
            'label' => 'City',
            'required' => false,
            'type' => 'text',
            'aliases' => ['city', 'ville', 'town', 'locality'],
        ],
        'country' => [
            'label' => 'Country (2 letters)',
            'required' => false,
            'type' => 'country',
            'aliases' => ['country', 'country code', 'pays', 'country code iso'],
        ],
        'phone' => [
            'label' => 'Phone',
            'required' => false,
            'type' => 'text',
            'aliases' => ['phone', 'phone number', 'telephone', 'tel', 'telephone number', 'contact number', 'telephone 1'],
        ],
        'phone_2' => [
            'label' => 'Phone 2',
            'required' => false,
            'type' => 'text',
            'aliases' => ['phone 2', 'phone2', 'secondary phone', 'alternate phone', 'tel 2', 'telephone 2', 'mobile', 'phone number 2'],
        ],
        'email' => [
            'label' => 'Email',
            'required' => false,
            'type' => 'email',
            'aliases' => ['email', 'e mail', 'mail', 'email address', 'courriel', 'contact email'],
        ],
        'website' => [
            'label' => 'Website',
            'required' => false,
            'type' => 'url',
            'aliases' => ['website', 'web site', 'url', 'site web', 'web', 'site internet', 'site'],
        ],
        'description' => [
            'label' => 'Description',
            'required' => false,
            'type' => 'text',
            'aliases' => ['description', 'desc', 'about', 'presentation', 'hotel description', 'description de l hotel'],
        ],
        'comment' => [
            'label' => 'Comment',
            'required' => false,
            'type' => 'text',
            'aliases' => ['comment', 'comments', 'note', 'notes', 'remark', 'remarks', 'observation', 'internal note'],
        ],
        'other_services' => [
            'label' => 'Other services',
            'required' => false,
            'type' => 'text',
            'aliases' => [
                'other services', 'others services', 'other service', 'services',
                'additional services', 'extra services', 'facilities', 'amenities',
                'autres services', 'service', 'prestations',
            ],
        ],
        'stars' => [
            'label' => 'Stars',
            'required' => false,
            'type' => 'int',
            'aliases' => ['stars', 'star', 'rating', 'category', 'hotel stars', 'classement', 'etoiles', 'nb stars'],
        ],
        'currency' => [
            'label' => 'Currency',
            'required' => false,
            'type' => 'currency',
            'aliases' => ['currency', 'currency code', 'devise'],
        ],
        'timezone' => [
            'label' => 'Timezone',
            'required' => false,
            'type' => 'timezone',
            'aliases' => ['timezone', 'time zone', 'fuseau horaire'],
        ],
        'tax_rate' => [
            'label' => 'Tax rate (%)',
            'required' => false,
            'type' => 'decimal',
            'aliases' => ['tax rate', 'tax', 'tax percent', 'vat', 'vat rate', 'tva', 'taxe'],
        ],
        'check_in_time' => [
            'label' => 'Check-in time',
            'required' => false,
            'type' => 'time',
            'aliases' => ['check in', 'check in time', 'checkin', 'arrival', 'arrival time', 'heure arrivee'],
        ],
        'check_out_time' => [
            'label' => 'Check-out time',
            'required' => false,
            'type' => 'time',
            'aliases' => ['check out', 'check out time', 'checkout', 'departure', 'departure time', 'heure depart'],
        ],
    ];

    /**
     * Maximum accepted length per target, mirroring the platform hotel create
     * rules so an import can never write a value the hotel editor would reject.
     *
     * `country`, `currency` and `stars` are intentionally absent: they are
     * constrained by shape (2 letters / 3 letters / 1-5), which the importer
     * reports with a more useful message than a length complaint.
     *
     * @var array<string, int>
     */
    private const LIMITS = [
        'name' => 120,
        'legal_name' => 160,
        'slug' => 60,
        'address' => 255,
        'city' => 120,
        'phone' => 40,
        'phone_2' => 40,
        'email' => 191,
        'website' => 191,
        'description' => 5000,
        'comment' => 5000,
        'other_services' => 5000,
        'timezone' => 64,
    ];

    /**
     * Public target catalogue for the mapping dropdown.
     *
     * @return array<int, array{key: string, label: string, required: bool, type: string}>
     */
    public static function targets(): array
    {
        $out = [];
        foreach (self::TARGETS as $key => $meta) {
            $out[] = [
                'key' => $key,
                'label' => $meta['label'],
                'required' => $meta['required'],
                'type' => $meta['type'],
            ];
        }

        return $out;
    }

    public static function isTarget(string $key): bool
    {
        return isset(self::TARGETS[$key]);
    }

    public static function isRequired(string $key): bool
    {
        return self::TARGETS[$key]['required'] ?? false;
    }

    public static function type(string $key): string
    {
        return self::TARGETS[$key]['type'] ?? 'text';
    }

    public static function maxLength(string $key): int
    {
        return self::LIMITS[$key] ?? 255;
    }

    /**
     * Validate the administrator's mapping before any row is read.
     *
     * @param  array<int|string, mixed>  $raw  column index => target key
     * @param  array<int, array{index: int, label: string}>  $headers
     * @return array<int, string> column index => target key
     *
     * @throws HotelImportException when a required field is unmapped, an
     *                              unknown target is used, two columns target
     *                              the same field, or a column is unknown.
     */
    public static function parseMapping(array $raw, array $headers): array
    {
        $validIndexes = [];
        foreach ($headers as $header) {
            $validIndexes[(int) $header['index']] = true;
        }

        $mapping = [];
        $errors = [];

        foreach ($raw as $column => $target) {
            // An explicitly empty dropdown means "ignore this column".
            if ($target === null || $target === '') {
                continue;
            }

            $column = (int) $column;

            if (! isset($validIndexes[$column])) {
                continue;
            }

            if (! is_string($target) || ! self::isTarget($target)) {
                $errors['mapping.'.$column] = "Unknown hotel field '".(is_string($target) ? $target : gettype($target))."'.";

                continue;
            }

            if (isset($mapping[$column])) {
                $errors['mapping.'.$column] = 'This column is mapped more than once.';

                continue;
            }

            $mapping[$column] = $target;
        }

        // Two different columns writing the same field would silently discard
        // one of them, so treat it as a mapping error rather than guessing.
        $byTarget = [];
        foreach ($mapping as $column => $target) {
            $byTarget[$target][] = $column;
        }
        foreach ($byTarget as $target => $columns) {
            if (count($columns) > 1) {
                foreach ($columns as $column) {
                    $errors['mapping.'.$column] = "Hotel field '{$target}' is mapped from more than one column.";
                }
            }
        }

        $mappedTargets = array_flip($mapping);
        foreach (self::requiredKeys() as $required) {
            if (! isset($mappedTargets[$required])) {
                $errors['mapping'][] = 'Map a column to '.self::TARGETS[$required]['label'].' before importing.';
            }
        }

        if ($errors !== []) {
            throw HotelImportException::withErrors(
                'The column mapping is incomplete or invalid.',
                $errors
            );
        }

        ksort($mapping);

        return $mapping;
    }

    /**
     * @return array<int, string>
     */
    public static function requiredKeys(): array
    {
        $keys = [];
        foreach (self::TARGETS as $key => $meta) {
            if ($meta['required']) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Guess a column -> target mapping from the detected header labels so the
     * administrator usually only has to confirm rather than pick from scratch.
     *
     * Two passes: exact alias match first (so "Phone 2" never steals "Phone"),
     * then a fuzzy longest-alias pass. Columns that match nothing stay unmapped.
     *
     * @param  array<int, string>  $headerLabels
     * @return array<int, string> column index => target key
     */
    public static function autoSuggest(array $headerLabels): array
    {
        $normalized = [];
        foreach ($headerLabels as $index => $label) {
            $normalized[$index] = self::normalizeLabel((string) $label);
        }

        $mapping = [];
        $taken = [];

        // Pass 1: exact alias matches.
        foreach ($normalized as $index => $label) {
            if ($label === '') {
                continue;
            }
            foreach (self::TARGETS as $key => $meta) {
                foreach ($meta['aliases'] as $alias) {
                    if (self::normalizeLabel($alias) === $label && ! isset($taken[$key])) {
                        $mapping[$index] = $key;
                        $taken[$key] = true;
                        break 2;
                    }
                }
            }
        }

        // Pass 2: fuzzy containment, longest alias wins.
        $candidates = [];
        foreach (self::TARGETS as $key => $meta) {
            foreach ($meta['aliases'] as $alias) {
                $candidates[] = ['key' => $key, 'alias' => self::normalizeLabel($alias), 'len' => mb_strlen($alias)];
            }
        }
        usort($candidates, fn ($a, $b) => $b['len'] <=> $a['len']);

        foreach ($normalized as $index => $label) {
            if ($label === '' || isset($mapping[$index])) {
                continue;
            }
            foreach ($candidates as $candidate) {
                if (isset($taken[$candidate['key']]) || $candidate['alias'] === '') {
                    continue;
                }
                if (str_contains($label, $candidate['alias'])) {
                    $mapping[$index] = $candidate['key'];
                    $taken[$candidate['key']] = true;
                    break;
                }
            }
        }

        ksort($mapping);

        return $mapping;
    }

    /**
     * Lowercase, strip accents and collapse punctuation so "Hôtel Nom" and
     * "hotel_nom" both reduce to "hotel nom".
     */
    private static function normalizeLabel(string $label): string
    {
        $ascii = Str::ascii($label);
        $ascii = preg_replace('/[^a-z0-9]+/i', ' ', $ascii) ?? '';
        $ascii = trim(mb_strtolower($ascii));

        return (string) preg_replace('/\s+/', ' ', $ascii);
    }
}
