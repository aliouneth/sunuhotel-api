<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\HotelImport;
use App\Models\HotelImportIssue;
use App\Services\AuditLogger;
use App\Services\HotelImport\HotelExcelReader;
use App\Services\HotelImport\HotelImporter;
use App\Services\HotelImport\HotelImportException;
use App\Services\HotelImport\HotelImportFieldMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Platform hotel Excel import.
 *
 * The flow is deliberately two-phase:
 *
 *   1. POST /platform/hotel-imports/upload  -> stage the workbook, auto-detect
 *      the header row and return the columns with samples so the administrator
 *      can choose the mapping.
 *   2. POST /platform/hotel-imports/run     -> import using that mapping.
 *
 * Keeping the file staged between the two calls means the workbook is parsed
 * twice (cheap, streaming) instead of being held in memory between requests.
 *
 * Every action is written to the audit trail (audit_logs + hotel_imports).
 */
class PlatformHotelImportController extends Controller
{
    public function __construct(
        private readonly HotelExcelReader $reader,
        private readonly HotelImporter $importer,
    ) {}

    /**
     * Import history - the audit view for this feature.
     */
    public function index(Request $request): JsonResponse
    {
        $imports = HotelImport::query()
            ->with('user:id,name,email')
            ->latest()
            ->paginate(min(100, max(1, (int) $request->integer('per_page', 20))))
            ->withQueryString();

        return response()->json([
            'data' => $imports->getCollection()->map(fn (HotelImport $import) => $this->present($import))->all(),
            'meta' => [
                'current_page' => $imports->currentPage(),
                'last_page' => $imports->lastPage(),
                'per_page' => $imports->perPage(),
                'total' => $imports->total(),
            ],
        ]);
    }

    /**
     * A single import with its duplicate/invalid row detail.
     */
    public function show(HotelImport $hotelImport): JsonResponse
    {
        $hotelImport->load('user:id,name,email');

        return response()->json([
            'data' => $this->present($hotelImport, detailed: true),
        ]);
    }

    /**
     * Stage an uploaded workbook and auto-detect its columns.
     */
    public function upload(Request $request): JsonResponse
    {
        $maxKb = (int) config('hotel_import.max_upload_kb', 20480);

        $request->validate([
            // Extension is checked here for a clean 422; the real "is this a
            // genuine workbook" test is the structural check in
            // HotelExcelReader::assertValidWorkbook (zip magic + workbook.xml),
            // which is stronger than a libmagic mime guess on OOXML files.
            'file' => [
                'required',
                'file',
                "max:{$maxKb}",
                function (string $attribute, $value, \Closure $fail): void {
                    if (strtolower((string) $value->getClientOriginalExtension()) !== 'xlsx') {
                        $fail('Only .xlsx Excel workbooks are accepted.');
                    }
                },
            ],
        ]);

        $file = $request->file('file');
        $originalName = (string) $file->getClientOriginalName();
        $token = (string) Str::uuid();

        $directory = $this->stagingDirectory();
        $path = $directory.DIRECTORY_SEPARATOR.$token.'.xlsx';

        try {
            // move() keeps the file off the PHP upload tmp dir immediately.
            $file->move($directory, $token.'.xlsx');

            $this->reader->assertValidWorkbook($path, $originalName);
            $detected = $this->reader->detect($path);
        } catch (HotelImportException $e) {
            if (is_file($path)) {
                @unlink($path);
            }

            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['file' => [$e->getMessage()]],
            ], 422);
        }

        $headers = [];
        foreach ($detected['headers'] as $position => $header) {
            $headers[] = [
                'index' => (int) $header['index'],
                'label' => (string) $header['label'],
                'sample' => (string) ($detected['samples'][$position] ?? ''),
            ];
        }

        $labels = array_map(fn (array $header): string => (string) $header['label'], $detected['headers']);

        $import = HotelImport::create([
            'token' => $token,
            'user_id' => auth('sanctum')->id(),
            'original_name' => $originalName,
            'stored_path' => $path,
            'status' => HotelImport::STATUS_STAGED,
            'total_rows' => $detected['total_rows'],
            'headers' => $detected['headers'],
            'ip' => $request->ip(),
        ]);

        AuditLogger::log('hotel_import.staged', $import, null, [
            'filename' => $import->original_name,
            'total_rows' => $detected['total_rows'],
            'columns' => count($detected['headers']),
            'truncated' => $detected['truncated'],
        ]);

        return response()->json([
            'message' => 'Columns detected. Review the mapping before importing.',
            'data' => [
                'token' => $import->token,
                'filename' => $import->original_name,
                'sheet' => $detected['sheet'],
                'header_row' => $detected['header_row'],
                'total_rows' => $detected['total_rows'],
                'truncated' => $detected['truncated'],
                'max_rows' => (int) config('hotel_import.max_rows', 20000),
                'headers' => $headers,
                'preview' => $detected['preview'],
                'targets' => HotelImportFieldMap::targets(),
                'required_targets' => HotelImportFieldMap::requiredKeys(),
                'suggested_mapping' => (object) HotelImportFieldMap::autoSuggest($labels),
            ],
        ]);
    }

    /**
     * Import the staged workbook using the administrator's column mapping.
     */
    public function run(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:64'],
            'mapping' => ['required', 'array', 'min:1'],
        ]);

        $import = HotelImport::query()->where('token', $validated['token'])->first();

        if (! $import) {
            return response()->json([
                'message' => 'This upload is no longer available. Please upload the file again.',
                'errors' => ['token' => ['Unknown upload token.']],
            ], 422);
        }

        if ($import->status !== HotelImport::STATUS_STAGED) {
            return response()->json([
                'message' => 'This file has already been imported.',
                'errors' => ['token' => ['Import already '.strtolower((string) $import->status).'. Upload the file again to run a new import.']],
            ], 422);
        }

        if (! $import->stored_path || ! is_file($import->stored_path)) {
            return response()->json([
                'message' => 'The staged file is missing or expired. Please upload it again.',
                'errors' => ['token' => ['Staged file no longer exists.']],
            ], 422);
        }

        $ttl = (int) config('hotel_import.staged_ttl_minutes', 240);
        if ($import->created_at && $import->created_at->lt(now()->subMinutes($ttl))) {
            @unlink($import->stored_path);

            return response()->json([
                'message' => "The staged file expired after {$ttl} minutes. Please upload it again.",
                'errors' => ['token' => ['Staged file expired.']],
            ], 422);
        }

        try {
            $mapping = HotelImportFieldMap::parseMapping(
                $validated['mapping'],
                (array) ($import->headers ?? [])
            );
        } catch (HotelImportException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        }

        try {
            $summary = $this->importer->import($import, $mapping);
        } catch (HotelImportException $e) {
            $import->forceFill([
                'status' => HotelImport::STATUS_FAILED,
                'mapping' => $mapping,
                'summary' => ['error' => $e->getMessage()],
            ])->save();

            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors() ?: ['file' => [$e->getMessage()]],
            ], 422);
        }

        // The workbook has been consumed; drop it from disk.
        @unlink($import->stored_path);

        $import->forceFill(['stored_path' => null])->save();

        AuditLogger::log('hotel_import.completed', $import, null, [
            'filename' => $import->original_name,
            'mapping' => $mapping,
            'created' => $summary['created'],
            'duplicates' => $summary['duplicates'],
            'skipped' => $summary['skipped'],
            'processed_rows' => $summary['processed_rows'],
            'status' => HotelImport::STATUS_COMPLETED,
        ]);

        return response()->json([
            'message' => $summary['created'] === 1
                ? '1 hotel imported and is awaiting approval.'
                : $summary['created'].' hotels imported and awaiting approval.',
            'data' => [
                'import' => $this->present($import->fresh(), detailed: true),
                'summary' => $summary,
            ],
        ]);
    }

    /**
     * Download a starter workbook listing every mappable column.
     */
    public function template(): StreamedResponse
    {
        $directory = $this->stagingDirectory();
        $path = $directory.DIRECTORY_SEPARATOR.'hotel-import-template-'.Str::random(8).'.xlsx';

        $header = array_map(fn (array $target): string => (string) $target['label'], HotelImportFieldMap::targets());

        $example = [
            'Palais Teranga', 'Palais Teranga SARL', 'palais-teranga',
            '12 Avenue Blaise Diagne', 'Dakar', 'SN',
            '+221 33 821 00 00', '+221 77 000 00 00', 'contact@teranga.example',
            'https://teranga.example', 'Beachfront hotel in the Plateau.', '',
            '4', 'XOF', 'Africa/Dakar', '18.5', '15:00', '11:00',
        ];

        $this->reader->writeTemplate($path, [$header, $example]);

        // streamDownload (not download()) so the temporary file is removed as
        // soon as it has been streamed, with no leftover on disk.
        return response()->streamDownload(function () use ($path): void {
            $handle = fopen($path, 'rb');

            if ($handle === false) {
                return;
            }

            while (! feof($handle)) {
                echo fread($handle, 8192);
                flush();
            }

            fclose($handle);
            @unlink($path);
        }, 'sunuhotel-hotel-import-template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(HotelImport $import, bool $detailed = false): array
    {
        $payload = [
            'id' => $import->id,
            'token' => $import->token,
            'filename' => $import->original_name,
            'status' => $import->status,
            'total_rows' => (int) $import->total_rows,
            'mapped_fields' => (int) $import->mapped_fields,
            'created_count' => (int) $import->created_count,
            'duplicate_count' => (int) $import->duplicate_count,
            'skipped_count' => (int) $import->skipped_count,
            'error_count' => (int) $import->error_count,
            'headers' => $import->headers,
            'mapping' => $import->mapping,
            'summary' => $import->summary,
            'ip' => $import->ip,
            'user' => $import->user ? [
                'id' => $import->user->id,
                'name' => $import->user->name,
                'email' => $import->user->email,
            ] : null,
            'created_at' => $import->created_at?->toIso8601String(),
        ];

        if ($detailed) {
            $payload['issues'] = $import->issues()
                ->orderBy('id')
                ->limit((int) config('hotel_import.max_reported_issues', 200))
                ->get()
                ->map(fn (HotelImportIssue $issue): array => [
                    'row_number' => $issue->row_number,
                    'kind' => $issue->kind,
                    'reason' => $issue->reason,
                    'data' => $issue->data,
                ])->all();
        }

        return $payload;
    }

    /**
     * Private (non-public) staging area for in-flight workbooks.
     */
    private function stagingDirectory(): string
    {
        $directory = rtrim(Storage::disk('local')->path(''), '/\\').DIRECTORY_SEPARATOR.'hotel-imports';

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        return $directory;
    }
}
