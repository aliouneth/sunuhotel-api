<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Hotel;
use App\Models\HotelImport;
use App\Models\HotelImportIssue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/**
 * Platform hotel Excel import: detection, mapping, validation, duplicates,
 * pending status and audit trail.
 */
class PlatformHotelImportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->platformAdmin()->create();
    }

    /**
     * Build a real .xlsx in memory so the tests exercise the same streaming
     * parser that production uses (no fake "xlsx" text files).
     *
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function xlsxFile(array $rows, string $name = 'hotels.xlsx'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'hotel-import-').'.xlsx';

        $writer = new Writer;
        $writer->openToFile($path);
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return new UploadedFile(
            $path,
            $name,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @return array<string, mixed> the upload response payload
     */
    private function upload(array $rows, ?User $as = null, string $name = 'hotels.xlsx'): array
    {
        $response = $this->actingAs($as ?? $this->admin(), 'sanctum')
            ->post('/api/v1/platform/hotel-imports/upload', ['file' => $this->xlsxFile($rows, $name)])
            ->assertOk();

        return $response->json('data');
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     * @return array<string, mixed> the run response payload
     */
    private function runImport(string $token, array $mapping, ?User $as = null): array
    {
        return $this->actingAs($as ?? $this->admin(), 'sanctum')
            ->postJson('/api/v1/platform/hotel-imports/run', [
                'token' => $token,
                'mapping' => $mapping,
            ])
            ->assertOk()
            ->json('data');
    }

    /* ---------------------------------------------------------------- auth */

    public function test_uploading_requires_authentication(): void
    {
        $this->post('/api/v1/platform/hotel-imports/upload', [
            'file' => $this->xlsxFile([['Hotel Name']]),
        ])->assertUnauthorized();
    }

    public function test_running_an_import_requires_authentication(): void
    {
        $this->postJson('/api/v1/platform/hotel-imports/run', [
            'token' => 'whatever',
            'mapping' => [0 => 'name'],
        ])->assertUnauthorized();
    }

    public function test_hotel_staff_cannot_use_the_import(): void
    {
        $staff = User::factory()->create();

        $this->actingAs($staff, 'sanctum')
            ->post('/api/v1/platform/hotel-imports/upload', [
                'file' => $this->xlsxFile([['Hotel Name'], ['Hotel Alpha']]),
            ])
            ->assertForbidden();

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/platform/hotel-imports/run', [
                'token' => 'whatever',
                'mapping' => [0 => 'name'],
            ])
            ->assertForbidden();
    }

    /* ------------------------------------------------- invalid excel format */

    public function test_it_rejects_csv_files(): void
    {
        $file = new UploadedFile(
            $this->tempFile("Hotel Name,City\nHotel Alpha,Dakar\n"),
            'hotels.csv',
            'text/csv',
            null,
            true
        );

        $this->actingAs($this->admin(), 'sanctum')
            ->post('/api/v1/platform/hotel-imports/upload', ['file' => $file])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_it_rejects_a_text_file_renamed_to_xlsx(): void
    {
        $file = new UploadedFile(
            $this->tempFile("Hotel Name,City\nHotel Alpha,Dakar\n"),
            'hotels.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        $this->actingAs($this->admin(), 'sanctum')
            ->post('/api/v1/platform/hotel-imports/upload', ['file' => $file])
            ->assertStatus(422)
            ->assertJsonFragment(['errors' => ['file' => ['This is not an Excel (.xlsx) file. CSV and text files cannot be imported.']]]);
    }

    public function test_it_rejects_a_legacy_xls_workbook(): void
    {
        $file = new UploadedFile(
            $this->tempFile("\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1legacy binary content"),
            'hotels.xlsx',
            'application/vnd.ms-excel',
            null,
            true
        );

        $this->actingAs($this->admin(), 'sanctum')
            ->post('/api/v1/platform/hotel-imports/upload', ['file' => $file])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'This looks like a legacy .xls workbook. Please re-save it as .xlsx before importing.']);
    }

    public function test_it_rejects_a_zip_that_is_not_a_workbook(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'not-a-workbook-').'.xlsx';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('readme.txt', 'hello');
        $zip->close();

        $file = new UploadedFile($path, 'hotels.xlsx', null, null, true);

        $this->actingAs($this->admin(), 'sanctum')
            ->post('/api/v1/platform/hotel-imports/upload', ['file' => $file])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'This is not a valid .xlsx workbook (xl/workbook.xml is missing).']);
    }

    public function test_it_rejects_a_workbook_with_no_header_row(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->post('/api/v1/platform/hotel-imports/upload', [
                'file' => $this->xlsxFile([['', '', '']]),
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'No column headers were found. The first row of the sheet must contain the column names.']);
    }

    /* ------------------------------------------------------- detection step */

    public function test_it_detects_every_column_with_a_sample_and_row_count(): void
    {
        $data = $this->upload([
            ['Hotel Name', 'Ville', 'Country', 'E-mail', 'Rate'],
            ['Hotel Alpha', 'Dakar', 'SN', 'alpha@example.com', '4'],
            ['Hotel Beta', 'Thies', 'SN', 'beta@example.com', '3'],
        ]);

        $this->assertSame(2, $data['total_rows']);
        $this->assertCount(5, $data['headers']);
        $this->assertSame('Hotel Name', $data['headers'][0]['label']);
        $this->assertSame('Hotel Alpha', $data['headers'][0]['sample']);
        $this->assertSame(1, $data['header_row']);

        // The detection response carries everything the mapping UI needs.
        $this->assertNotEmpty($data['targets']);
        $this->assertContains('name', array_column($data['targets'], 'key'));
        $this->assertSame(['name'], $data['required_targets']);

        // Auto-suggestion should already wire the obvious columns up.
        $this->assertSame('name', $data['suggested_mapping']['0']);
        $this->assertSame('city', $data['suggested_mapping']['1']);
        $this->assertSame('country', $data['suggested_mapping']['2']);
        $this->assertSame('email', $data['suggested_mapping']['3']);
    }

    public function test_detection_previews_the_first_rows(): void
    {
        $data = $this->upload([
            ['Hotel Name', 'City'],
            ['Hotel Alpha', 'Dakar'],
            ['Hotel Beta', 'Thies'],
        ]);

        $this->assertCount(2, $data['preview']);
        $this->assertSame(2, $data['preview'][0]['row_number']);
        $this->assertSame('Hotel Alpha', $data['preview'][0]['values']['0']);
        $this->assertSame('Dakar', $data['preview'][0]['values']['1']);
    }

    public function test_duplicate_headers_are_disambiguated_and_blank_headers_named(): void
    {
        $data = $this->upload([
            ['Hotel Name', 'Hotel Name', '', 'City'],
            ['Hotel Alpha', 'Hotel Gamma', 'x', 'Dakar'],
        ]);

        $labels = array_column($data['headers'], 'label');
        $this->assertSame(['Hotel Name', 'Hotel Name (2)', 'Column C', 'City'], $labels);
    }

    /* ---------------------------------------------------- mapping validation */

    public function test_import_requires_a_mapping_for_the_hotel_name(): void
    {
        $data = $this->upload([
            ['City', 'Country'],
            ['Dakar', 'SN'],
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/platform/hotel-imports/run', [
                'token' => $data['token'],
                'mapping' => [0 => 'city', 1 => 'country'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('mapping');

        $this->assertSame(0, Hotel::count());
    }

    public function test_mapping_rejects_two_columns_on_the_same_field(): void
    {
        $data = $this->upload([
            ['Hotel Name', 'Nom'],
            ['Hotel Alpha', 'Hotel Alpha'],
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/platform/hotel-imports/run', [
                'token' => $data['token'],
                'mapping' => [0 => 'name', 1 => 'name'],
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['mapping.1' => "Hotel field 'name' is mapped from more than one column."]);
    }

    public function test_mapping_rejects_an_unknown_hotel_field(): void
    {
        $data = $this->upload([['Hotel Name'], ['Hotel Alpha']]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/platform/hotel-imports/run', [
                'token' => $data['token'],
                'mapping' => [0 => 'nope'],
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['mapping.0' => "Unknown hotel field 'nope'."]);
    }

    public function test_status_is_not_mappable(): void
    {
        // A file must not be able to talk its way past approval.
        $data = $this->upload([['Hotel Name', 'Status'], ['Hotel Alpha', 'active']]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/platform/hotel-imports/run', [
                'token' => $data['token'],
                'mapping' => [0 => 'name', 1 => 'status'],
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['mapping.1' => "Unknown hotel field 'status'."]);
    }

    public function test_an_unknown_token_is_rejected(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/platform/hotel-imports/run', [
                'token' => 'e3b0c442-98fc-1c14-9afb-f4c8996fb924',
                'mapping' => [0 => 'name'],
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'This upload is no longer available. Please upload the file again.']);
    }

    /* -------------------------------------------------------------- import */

    public function test_it_imports_mapped_hotels_as_pending(): void
    {
        $admin = $this->admin();

        $data = $this->upload([
            ['Hotel Name', 'Address', 'City', 'Country', 'Phone', 'E-mail', 'Website', 'Description'],
            [
                'Palais Teranga', '12 Avenue Blaise Diagne', 'Dakar', 'SN',
                '+221 33 821 00 00', 'contact@teranga.example', 'teranga.example',
                'Beachfront hotel.',
            ],
        ], $admin);

        $payload = $this->runImport($data['token'], [
            0 => 'name', 1 => 'address', 2 => 'city', 3 => 'country',
            4 => 'phone', 5 => 'email', 6 => 'website', 7 => 'description',
        ]);

        $this->assertSame(1, $payload['summary']['created']);
        $this->assertSame(0, $payload['summary']['duplicates']);
        $this->assertSame('pending', $payload['summary']['status_after_import']);

        $hotel = Hotel::firstWhere('name', 'Palais Teranga');
        $this->assertNotNull($hotel);
        $this->assertSame('pending', $hotel->status, 'imported hotels must await approval');
        $this->assertSame('palais-teranga', $hotel->slug);
        $this->assertSame('12 Avenue Blaise Diagne', $hotel->address);
        $this->assertSame('Dakar', $hotel->city);
        $this->assertSame('SN', $hotel->country);
        $this->assertSame('+221 33 821 00 00', $hotel->phone);
        $this->assertSame('contact@teranga.example', $hotel->email);
        $this->assertSame('https://teranga.example', $hotel->website);
        $this->assertSame('Beachfront hotel.', $hotel->description);
        $this->assertSame($admin->id, $hotel->created_by);
        $this->assertNotSame('', (string) $hotel->uuid);
    }

    public function test_imported_hotels_are_not_publicly_visible_until_approved(): void
    {
        $data = $this->upload([['Hotel Name'], ['Palais Teranga']]);
        $this->runImport($data['token'], [0 => 'name']);

        $hotel = Hotel::firstWhere('name', 'Palais Teranga');

        // Not published: the public profile only serves active hotels.
        $this->getJson("/api/v1/hotels/{$hotel->slug}/public")->assertNotFound();

        // Approving through the standard workflow publishes it.
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/platform/hotels/{$hotel->id}/approve")
            ->assertOk();

        $this->assertSame('active', $hotel->fresh()->status);
        $this->getJson("/api/v1/hotels/{$hotel->slug}/public")->assertOk();
    }

    public function test_it_maps_every_supported_field(): void
    {
        $admin = $this->admin();

        $data = $this->upload([
            ['Hotel Name', 'Legal Name', 'Address', 'City', 'Country', 'Phone', 'Phone 2', 'E-mail', 'Website', 'Description', 'Other Services', 'Comment', 'Stars', 'Currency', 'Timezone', 'Tax Rate', 'Check In', 'Check Out'],
            [
                'Hotel Complet', 'Hotel Complet SARL', '1 Rue X', 'Saint-Louis', 'SN',
                '+221 33 1', '+221 77 2', 'full@example.com', 'https://full.example',
                'Full row', 'Spa, Airport shuttle', 'internal note', '5', 'XOF',
                'Africa/Dakar', '18,5', '14:00', '12:00',
            ],
        ], $admin);

        $this->runImport($data['token'], [
            0 => 'name', 1 => 'legal_name', 2 => 'address', 3 => 'city', 4 => 'country',
            5 => 'phone', 6 => 'phone_2', 7 => 'email', 8 => 'website', 9 => 'description',
            10 => 'other_services', 11 => 'comment', 12 => 'stars', 13 => 'currency',
            14 => 'timezone', 15 => 'tax_rate', 16 => 'check_in_time', 17 => 'check_out_time',
        ]);

        $hotel = Hotel::firstWhere('name', 'Hotel Complet');
        $this->assertNotNull($hotel);
        $this->assertSame('Hotel Complet SARL', $hotel->legal_name);
        $this->assertSame('+221 77 2', $hotel->phone_2);
        $this->assertSame('Spa, Airport shuttle', $hotel->other_services);
        $this->assertSame('internal note', $hotel->comment);
        $this->assertSame(5, (int) $hotel->stars);
        $this->assertSame('XOF', $hotel->currency);
        $this->assertSame('Africa/Dakar', $hotel->timezone);
        $this->assertSame(18.5, (float) $hotel->tax_rate);
        $this->assertSame('14:00', $hotel->check_in_time->format('H:i'));
        $this->assertSame('12:00', $hotel->check_out_time->format('H:i'));
    }

    public function test_unmapped_columns_are_ignored(): void
    {
        $data = $this->upload([
            ['Hotel Name', 'Internal Ref', 'City'],
            ['Hotel Alpha', 'REF-001', 'Dakar'],
        ]);

        $this->runImport($data['token'], [0 => 'name', 2 => 'city']);

        $hotel = Hotel::firstWhere('name', 'Hotel Alpha');
        $this->assertNotNull($hotel);
        $this->assertNull($hotel->legal_name);
    }

    public function test_blank_rows_are_skipped(): void
    {
        $data = $this->upload([
            ['Hotel Name', 'City'],
            ['Hotel Alpha', 'Dakar'],
            ['', ''],
            ['Hotel Beta', 'Thies'],
        ]);

        $payload = $this->runImport($data['token'], [0 => 'name', 1 => 'city']);

        $this->assertSame(2, $payload['summary']['created']);
        $this->assertSame(2, Hotel::count());
    }

    /* ---------------------------------------------------------- duplicates */

    public function test_a_duplicate_of_an_existing_hotel_is_flagged_but_still_imported(): void
    {
        Hotel::factory()->create(['name' => 'Hotel Alpha', 'slug' => 'hotel-alpha']);

        $data = $this->upload([['Hotel Name', 'City'], ['Hotel Alpha', 'Dakar']]);
        $payload = $this->runImport($data['token'], [0 => 'name', 1 => 'city']);

        $this->assertSame(1, $payload['summary']['created'], 'duplicates must still import');
        $this->assertSame(1, $payload['summary']['duplicates'], 'and be flagged');
        $this->assertSame(2, Hotel::count());

        $imported = Hotel::where('slug', '!=', 'hotel-alpha')->firstWhere('name', 'Hotel Alpha');
        $this->assertNotNull($imported);
        $this->assertSame('pending', $imported->status);
        $this->assertNotSame('hotel-alpha', $imported->slug, 'slug must stay unique');

        $import = HotelImport::firstWhere('token', $data['token']);

        $this->assertSame(1, HotelImportIssue::where('hotel_import_id', $import->id)
            ->where('kind', HotelImportIssue::KIND_DUPLICATE)
            ->count());
    }

    public function test_duplicates_inside_the_same_file_are_flagged(): void
    {
        $data = $this->upload([
            ['Hotel Name', 'City'],
            ['Hotel Alpha', 'Dakar'],
            ['Hotel Alpha', 'Dakar'],
        ]);

        $payload = $this->runImport($data['token'], [0 => 'name', 1 => 'city']);

        $this->assertSame(2, $payload['summary']['created']);
        $this->assertSame(1, $payload['summary']['duplicates']);
        $this->assertSame(2, Hotel::count());
        $this->assertSame(2, Hotel::pluck('slug')->unique()->count());
    }

    public function test_duplicate_rows_can_be_reviewed_in_the_issue_list(): void
    {
        Hotel::factory()->create(['name' => 'Hotel Alpha', 'slug' => 'hotel-alpha']);

        $data = $this->upload([['Hotel Name'], ['Hotel Alpha']]);
        $this->runImport($data['token'], [0 => 'name']);

        $import = HotelImport::firstWhere('token', $data['token']);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/v1/platform/hotel-imports/{$import->id}")
            ->assertOk();

        $issues = $response->json('data.issues');
        $this->assertCount(1, $issues);
        $this->assertSame('duplicate', $issues[0]['kind']);
        $this->assertStringContainsString('already exists', $issues[0]['reason']);
    }

    /* ------------------------------------------------------ row validation */

    public function test_invalid_rows_are_skipped_and_reported(): void
    {
        $data = $this->upload([
            ['Hotel Name', 'E-mail', 'Country', 'Stars'],
            ['Hotel Alpha', 'alpha@example.com', 'SN', '4'],
            ['Hotel Broken', 'not-an-email', 'SN', '4'],
        ]);

        $payload = $this->runImport($data['token'], [
            0 => 'name', 1 => 'email', 2 => 'country', 3 => 'stars',
        ]);

        $this->assertSame(1, $payload['summary']['created']);
        $this->assertSame(1, $payload['summary']['skipped']);
        $this->assertSame(1, Hotel::count());
        $this->assertNotEmpty($payload['summary']['error_samples']);
        $this->assertStringContainsString(
            'Invalid email address',
            $payload['summary']['error_samples'][0]['reasons'][0]
        );
        $this->assertSame('invalid', $payload['import']['issues'][0]['kind']);
    }

    public function test_rows_outside_the_star_range_are_skipped(): void
    {
        $data = $this->upload([
            ['Hotel Name', 'Stars'],
            ['Hotel Nine', '9'],
        ]);

        $payload = $this->runImport($data['token'], [0 => 'name', 1 => 'stars']);

        $this->assertSame(0, $payload['summary']['created']);
        $this->assertSame(1, $payload['summary']['skipped']);
    }

    public function test_a_row_without_a_name_is_skipped(): void
    {
        $data = $this->upload([
            ['Hotel Name', 'City'],
            ['', 'Dakar'],
        ]);

        $payload = $this->runImport($data['token'], [0 => 'name', 1 => 'city']);

        $this->assertSame(0, $payload['summary']['created']);
        $this->assertSame(1, $payload['summary']['skipped']);
        $this->assertStringContainsString('Hotel name is required', $payload['summary']['error_samples'][0]['reasons'][0]);
    }

    public function test_an_over_long_value_skips_the_row(): void
    {
        $data = $this->upload([
            ['Hotel Name'],
            [str_repeat('a', 121)],
        ]);

        $payload = $this->runImport($data['token'], [0 => 'name']);

        $this->assertSame(0, $payload['summary']['created']);
        $this->assertSame(1, $payload['summary']['skipped']);
    }

    /* --------------------------------------------------------- audit trail */

    public function test_every_action_is_written_to_the_audit_trail(): void
    {
        $admin = $this->admin();

        $data = $this->upload([['Hotel Name', 'City'], ['Hotel Alpha', 'Dakar']], $admin);
        $this->runImport($data['token'], [0 => 'name', 1 => 'city'], $admin);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'hotel_import.staged',
            'entity_type' => HotelImport::class,
            'user_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'hotel_import.completed',
            'entity_type' => HotelImport::class,
            'user_id' => $admin->id,
        ]);

        $staged = AuditLog::where('action', 'hotel_import.staged')->first();
        $this->assertSame('hotels.xlsx', $staged->context['filename']);
        $this->assertSame(1, $staged->context['total_rows']);

        $completed = AuditLog::where('action', 'hotel_import.completed')->first();
        $this->assertSame(1, $completed->context['created']);
        $this->assertSame(0, $completed->context['duplicates']);
        $this->assertSame('name', $completed->context['mapping']['0']);

        $import = HotelImport::firstWhere('token', $data['token']);
        $this->assertSame(HotelImport::STATUS_COMPLETED, $import->status);
        $this->assertSame(1, $import->created_count);
        $this->assertSame($admin->id, $import->user_id);
        $this->assertSame('hotels.xlsx', $import->original_name);
        $this->assertNull($import->stored_path, 'the workbook is removed once consumed');
    }

    public function test_the_import_history_is_listed(): void
    {
        $data = $this->upload([['Hotel Name'], ['Hotel Alpha']]);
        $this->runImport($data['token'], [0 => 'name']);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/platform/hotel-imports')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame(1, $response->json('data.0.created_count'));
        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_a_file_cannot_be_imported_twice(): void
    {
        $data = $this->upload([['Hotel Name'], ['Hotel Alpha']]);
        $this->runImport($data['token'], [0 => 'name']);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/platform/hotel-imports/run', [
                'token' => $data['token'],
                'mapping' => [0 => 'name'],
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'This file has already been imported.']);

        $this->assertSame(1, Hotel::count());
    }

    /* ------------------------------------------------------------ template */

    public function test_the_template_workbook_downloads(): void
    {
        $response = $this->actingAs($this->admin(), 'sanctum')
            ->get('/api/v1/platform/hotel-imports/template')
            ->assertOk();

        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            (string) $response->headers->get('content-type')
        );

        ob_start();
        $response->sendContent();
        $workbook = (string) ob_get_clean();

        // A real xlsx is a zip container: "PK" magic + the workbook part.
        $this->assertSame('PK', substr($workbook, 0, 2));

        $probe = tempnam(sys_get_temp_dir(), 'template-').'.xlsx';
        file_put_contents($probe, $workbook);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($probe) === true, 'the template must be a valid zip');
        $this->assertNotFalse($zip->locateName('xl/workbook.xml'), 'the template must contain xl/workbook.xml');
        $zip->close();
        @unlink($probe);
    }

    /* -------------------------------------------------------------- helper */

    private function tempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'hotel-import-raw-');
        file_put_contents($path, $contents);

        return $path;
    }
}
