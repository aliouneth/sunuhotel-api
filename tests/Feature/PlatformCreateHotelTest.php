<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class PlatformCreateHotelTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->platformAdmin()->create();
    }

    public function test_creating_a_hotel_requires_authentication(): void
    {
        $this->postJson('/api/v1/platform/hotels', ['name' => 'Nope'])->assertUnauthorized();
    }

    public function test_hotel_staff_cannot_create_hotels(): void
    {
        $staff = User::factory()->create();

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/platform/hotels', ['name' => 'Nope'])
            ->assertForbidden();
    }

    public function test_name_is_required(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/platform/hotels', ['city' => 'Dakar'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_admin_can_create_a_hotel_with_every_information_field(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/platform/hotels', [
            'name' => 'Palais Teranga',
            'legal_name' => 'Palais Teranga SARL',
            'slug' => 'palais-teranga',
            'address' => '12 Avenue Blaise Diagne',
            'city' => 'Dakar',
            'country' => 'SN',
            'phone' => '+221 33 821 00 00',
            'email' => 'contact@teranga.example',
            'website' => 'https://teranga.example',
            'currency' => 'XOF',
            'timezone' => 'Africa/Dakar',
            'tax_rate' => 18.5,
            'check_in_time' => '14:00',
            'check_out_time' => '12:00',
            'stars' => 4,
            'status' => 'active',
            'locale' => 'fr',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Palais Teranga')
            ->assertJsonPath('data.legal_name', 'Palais Teranga SARL')
            ->assertJsonPath('data.slug', 'palais-teranga')
            ->assertJsonPath('data.address', '12 Avenue Blaise Diagne')
            ->assertJsonPath('data.city', 'Dakar')
            ->assertJsonPath('data.country', 'SN')
            ->assertJsonPath('data.phone', '+221 33 821 00 00')
            ->assertJsonPath('data.email', 'contact@teranga.example')
            ->assertJsonPath('data.website', 'https://teranga.example')
            ->assertJsonPath('data.currency', 'XOF')
            ->assertJsonPath('data.timezone', 'Africa/Dakar')
            ->assertJsonPath('data.stars', 4)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.locale', 'fr')
            // Time fields must come back as HH:MM for <input type="time">.
            ->assertJsonPath('data.check_in_time', '14:00')
            ->assertJsonPath('data.check_out_time', '12:00');

        $hotel = Hotel::where('slug', 'palais-teranga')->first();
        $this->assertNotNull($hotel);
        $this->assertEqualsWithDelta(18.5, (float) $hotel->tax_rate, 0.001);
        $this->assertSame('14:00', $hotel->check_in_time->format('H:i'));
        $this->assertSame('fr', $hotel->settings['locale']);
        $this->assertSame($admin->id, $hotel->created_by);
        $this->assertNotNull($hotel->uuid);
    }

    public function test_currency_is_upper_cased(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/platform/hotels', ['name' => 'Lower', 'currency' => 'eur'])
            ->assertCreated()
            ->assertJsonPath('data.currency', 'EUR');
    }

    public function test_slug_is_derived_from_the_name_when_omitted(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/platform/hotels', ['name' => 'Hotel Beau Rivage'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'hotel-beau-rivage');
    }

    public function test_duplicate_names_still_produce_unique_slugs(): void
    {
        $admin = $this->admin();

        $first = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/platform/hotels', ['name' => 'Twin Towers'])
            ->assertCreated()
            ->json('data.slug');

        $second = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/platform/hotels', ['name' => 'Twin Towers'])
            ->assertCreated()
            ->json('data.slug');

        $this->assertSame('twin-towers', $first);
        $this->assertNotSame($first, $second);
        $this->assertStringStartsWith('twin-towers-', $second);
    }

    public function test_explicit_duplicate_slug_is_rejected(): void
    {
        $admin = $this->admin();
        Hotel::factory()->create(['slug' => 'taken-slug']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/platform/hotels', ['name' => 'Other', 'slug' => 'taken-slug'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');
    }

    public function test_invalid_field_values_are_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/platform/hotels', ['name' => 'X', 'country' => 'SENEGAL'])
            ->assertStatus(422)->assertJsonValidationErrors('country');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/platform/hotels', ['name' => 'X', 'email' => 'not-an-email'])
            ->assertStatus(422)->assertJsonValidationErrors('email');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/platform/hotels', ['name' => 'X', 'tax_rate' => 150])
            ->assertStatus(422)->assertJsonValidationErrors('tax_rate');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/platform/hotels', ['name' => 'X', 'stars' => 9])
            ->assertStatus(422)->assertJsonValidationErrors('stars');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/platform/hotels', ['name' => 'X', 'status' => 'ghost'])
            ->assertStatus(422)->assertJsonValidationErrors('status');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/platform/hotels', ['name' => 'X', 'timezone' => 'Mars/Olympus'])
            ->assertStatus(422)->assertJsonValidationErrors('timezone');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/platform/hotels', ['name' => 'X', 'check_in_time' => '25:99'])
            ->assertStatus(422)->assertJsonValidationErrors('check_in_time');
    }

    public function test_new_hotel_defaults_to_active_and_appears_in_the_listing(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/platform/hotels', ['name' => 'Fresh Hotel'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'active');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/hotels?search=Fresh')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.name', 'Fresh Hotel');
    }

    public function test_logo_and_photos_are_uploaded(): void
    {
        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/platform/hotels', [
                'name' => 'With Pictures',
                'logo' => UploadedFile::fake()->image('logo.jpg'),
                'images' => [
                    UploadedFile::fake()->image('a.jpg'),
                    UploadedFile::fake()->image('b.jpg'),
                ],
            ]);

        $response->assertCreated()->assertJsonCount(2, 'data.images');

        $hotel = Hotel::where('name', 'With Pictures')->first();
        $this->assertNotNull($hotel->logo_path);
        $this->assertCount(2, $hotel->hotelImages()->get());

        // Hotel::setLogo()/HotelImage::setImage() write straight into the
        // public/ directory, so assert on disk (then clean up after ourselves).
        $written = [public_path(ltrim((string) $hotel->logo_path, '/'))];
        foreach ($hotel->hotelImages as $img) {
            $written[] = public_path(ltrim((string) $img->image_path, '/'));
        }

        foreach ($written as $path) {
            $this->assertFileExists($path);
        }

        foreach ($written as $path) {
            @unlink($path);
        }
        @rmdir(dirname($written[0]));
    }

    public function test_created_hotel_tax_rate_can_be_edited_afterwards(): void
    {
        $admin = $this->admin();

        $created = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/platform/hotels', ['name' => 'Taxable', 'tax_rate' => 5])
            ->assertCreated()
            ->json('data');

        // Regression guard: the platform update path used to silently drop
        // tax_rate, so the edit form always reset it to 0.
        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/platform/hotels/'.$created['id'], ['tax_rate' => 20])
            ->assertOk()
            ->assertJsonPath('data.tax_rate', 20);

        $this->assertEqualsWithDelta(20.0, (float) Hotel::find($created['id'])->tax_rate, 0.001);
    }
}
