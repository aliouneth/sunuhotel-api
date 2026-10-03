<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contract for the dedicated hotel edit page at /platform/hotels/[id].
 *
 * The page fetches the record from GET /platform/hotels/{hotel} to seed its
 * form, then saves with a partial multipart POST carrying _method=PUT so PHP
 * can populate $_FILES. These tests pin both halves of that contract, plus the
 * "only send what changed" rule the page relies on to avoid clobbering fields
 * the admin never touched.
 */
class PlatformHotelEditPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->platformAdmin()->create();
    }

    public function test_the_show_endpoint_returns_every_field_the_form_needs(): void
    {
        $admin = $this->admin();
        $hotel = Hotel::factory()->create([
            'name' => 'Seed Source',
            'legal_name' => 'Seed SARL',
            'stars' => 4,
            'other_services' => 'Spa',
            'comment' => 'internal',
            'check_in_time' => '13:30',
            'check_out_time' => '10:15',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/hotels/'.$hotel->id)
            ->assertOk()
            // The form seeds from this payload, so every editable input must
            // be present or it renders blank and silently overwrites on save.
            ->assertJsonPath('data.id', $hotel->id)
            ->assertJsonPath('data.name', 'Seed Source')
            ->assertJsonPath('data.legal_name', 'Seed SARL')
            ->assertJsonPath('data.stars', 4)
            ->assertJsonPath('data.other_services', 'Spa')
            ->assertJsonPath('data.comment', 'internal')
            ->assertJsonPath('data.check_in_time', '13:30')
            ->assertJsonPath('data.check_out_time', '10:15')
            ->assertJsonPath('data.currency', $hotel->currency)
            ->assertJsonPath('data.timezone', $hotel->timezone)
            ->assertJsonPath('data.status', 'active');
    }

    public function test_the_show_endpoint_returns_the_database_default_times(): void
    {
        // hotels.check_in_time / check_out_time are NOT NULL with DB defaults,
        // so the form always receives concrete times and never has to guess.
        $admin = $this->admin();
        $hotel = Hotel::factory()->create();

        $payload = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/hotels/'.$hotel->id)
            ->assertOk()
            ->json('data');

        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', (string) $payload['check_in_time']);
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', (string) $payload['check_out_time']);
        $this->assertSame(
            $hotel->fresh()->check_in_time->format('H:i'),
            $payload['check_in_time']
        );
    }

    public function test_a_partial_update_only_touches_the_submitted_fields(): void
    {
        // The page diffs the form against its baseline and posts only what
        // changed. Every other stored value must come back untouched.
        $admin = $this->admin();
        $hotel = Hotel::factory()->create([
            'other_services' => 'Keep me',
            'description' => 'Original description',
            'comment' => 'Original comment',
            'tax_rate' => 7.25,
            'check_in_time' => '13:30',
            'check_out_time' => '10:15',
            'stars' => 2,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/platform/hotels/'.$hotel->id, ['city' => 'Saint-Louis'])
            ->assertOk()
            ->assertJsonPath('data.city', 'Saint-Louis');

        $fresh = $hotel->fresh();
        $this->assertSame('Saint-Louis', $fresh->city);
        $this->assertSame('Keep me', $fresh->other_services);
        $this->assertSame('Original description', $fresh->description);
        $this->assertSame('Original comment', $fresh->comment);
        $this->assertSame(7.25, (float) $fresh->tax_rate);
        $this->assertSame('13:30', $fresh->check_in_time->format('H:i'));
        $this->assertSame('10:15', $fresh->check_out_time->format('H:i'));
        $this->assertSame(2, (int) $fresh->stars);
    }

    public function test_the_page_save_method_spoofing_is_accepted(): void
    {
        // The browser cannot send a multipart PUT, so the page POSTs with
        // _method=PUT. Laravel must re-dispatch it as a real update.
        $admin = $this->admin();
        $hotel = Hotel::factory()->create(['city' => 'Dakar']);

        $this->actingAs($admin, 'sanctum')
            ->post('/api/v1/platform/hotels/'.$hotel->id, [
                '_method' => 'PUT',
                'city' => 'Thies',
                'other_services' => 'Spa, Gym',
            ], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertSame('Thies', $hotel->fresh()->city);
        $this->assertSame('Spa, Gym', $hotel->fresh()->other_services);
    }

    public function test_stars_and_check_in_out_times_are_editable(): void
    {
        // The old edit modal omitted these three inputs entirely.
        $admin = $this->admin();
        $hotel = Hotel::factory()->create(['stars' => 1, 'check_in_time' => '12:00']);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/platform/hotels/'.$hotel->id, [
                'stars' => 5,
                'check_in_time' => '14:00',
                'check_out_time' => '11:30',
            ])
            ->assertOk()
            ->assertJsonPath('data.stars', 5)
            ->assertJsonPath('data.check_in_time', '14:00')
            ->assertJsonPath('data.check_out_time', '11:30');

        $fresh = $hotel->fresh();
        $this->assertSame(5, (int) $fresh->stars);
        $this->assertSame('14:00', $fresh->check_in_time->format('H:i'));
        $this->assertSame('11:30', $fresh->check_out_time->format('H:i'));
    }

    public function test_a_duplicate_slug_reports_a_field_scoped_validation_error(): void
    {
        // The page maps errors.slug onto the slug input, so the response has to
        // be keyed by field name rather than a flat message.
        $admin = $this->admin();
        Hotel::factory()->create(['slug' => 'taken-slug']);
        $hotel = Hotel::factory()->create(['slug' => 'my-slug']);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/platform/hotels/'.$hotel->id, ['slug' => 'taken-slug'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');

        $this->assertSame('my-slug', $hotel->fresh()->slug);
    }

    public function test_keeping_the_same_slug_on_the_same_hotel_is_allowed(): void
    {
        // The unique rule must ignore the record being edited, otherwise the
        // page cannot save a hotel whose slug it did not touch.
        $admin = $this->admin();
        $hotel = Hotel::factory()->create(['slug' => 'unchanged']);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/platform/hotels/'.$hotel->id, [
                'slug' => 'unchanged',
                'city' => 'Ziguinchor',
            ])
            ->assertOk()
            ->assertJsonPath('data.slug', 'unchanged');

        $this->assertSame('Ziguinchor', $hotel->fresh()->city);
    }

    public function test_clearing_a_text_field_persists_null(): void
    {
        $admin = $this->admin();
        $hotel = Hotel::factory()->create(['other_services' => 'Spa']);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/platform/hotels/'.$hotel->id, ['other_services' => ''])
            ->assertOk()
            ->assertJsonPath('data.other_services', null);

        $this->assertNull($hotel->fresh()->other_services);
    }

    public function test_the_update_response_reflects_the_saved_state(): void
    {
        // The page re-seeds its form from the response, so the response must
        // come back normalised rather than echoing raw input.
        $admin = $this->admin();
        $hotel = Hotel::factory()->create(['currency' => 'XOF']);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/platform/hotels/'.$hotel->id, [
                'country' => 'sn',
                'tax_rate' => '18.5',
            ])
            ->assertOk()
            ->assertJsonPath('data.country', 'sn')
            ->assertJsonPath('data.tax_rate', 18.5);
    }

    public function test_a_non_admin_cannot_open_the_hotel_for_editing(): void
    {
        $hotel = Hotel::factory()->create();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/platform/hotels/'.$hotel->id)
            ->assertForbidden();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->putJson('/api/v1/platform/hotels/'.$hotel->id, ['city' => 'Hijacked'])
            ->assertForbidden();

        $this->assertNotSame('Hijacked', $hotel->fresh()->city);
    }

    public function test_a_tenant_owner_cannot_edit_some_other_hotel(): void
    {
        $mine = Hotel::factory()->create();
        $other = Hotel::factory()->create(['city' => 'Original']);
        $user = User::factory()->create(['hotel_id' => $mine->id]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/platform/hotels/'.$other->id, ['city' => 'Hijacked'])
            ->assertForbidden();

        $this->assertSame('Original', $other->fresh()->city);
    }
}
