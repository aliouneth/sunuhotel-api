<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Expense;
use App\Models\ExpenseType;
use App\Models\Hotel;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\HotelContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EmployeeExpenseTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(string $role = 'owner'): array
    {
        Role::seedPolicies();

        $hotel = Hotel::factory()->create();
        $user = User::factory()->create(['hotel_id' => $hotel->id]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($hotel->id);
        $user->syncRoles([Role::query()->where('name', $role)->first()]);
        HotelContext::clear();

        return [$hotel, $user];
    }

    public function test_owner_can_crud_employee_and_destroy_deactivates(): void
    {
        [$hotel, $user] = $this->makeTenant();

        $create = $this->actingAs($user, 'sanctum')->postJson('/api/v1/employees', [
            'name' => 'Moussa Sall',
            'position' => 'Receptionniste',
            'salary_cents' => 120000,
            'hire_date' => '2025-01-15',
        ]);

        $create->assertCreated()
            ->assertJson(['data' => ['name' => 'Moussa Sall', 'salary_cents' => 120000]]);

        $employeeId = $create->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/employees/{$employeeId}", ['salary_cents' => 130000])
            ->assertOk()
            ->assertJson(['data' => ['salary_cents' => 130000]]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/employees/{$employeeId}")
            ->assertOk();

        $this->assertDatabaseHas('employees', ['id' => $employeeId, 'is_active' => false]);
    }

    public function test_pay_salary_creates_paid_expense_on_salary_type(): void
    {
        [$hotel, $user] = $this->makeTenant();
        $employee = Employee::factory()->create([
            'hotel_id' => $hotel->id,
            'name' => 'Awa Diop',
            'salary_cents' => 150000,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/employees/{$employee->id}/pay", ['period' => '2026-09']);

        $response->assertCreated();
        $data = $response->json('data');
        $this->assertSame(150000, $data['amount_cents']);
        $this->assertSame('paid', $data['status']);
        $this->assertStringStartsWith('2026-09-30', $data['incurred_on']);
        $this->assertSame('salary', $data['expense_type']['key']);
        $this->assertSame($employee->id, $data['employee']['id']);

        $this->assertDatabaseHas('expenses', [
            'hotel_id' => $hotel->id,
            'employee_id' => $employee->id,
            'amount_cents' => 150000,
            'status' => 'paid',
        ]);
    }

    public function test_expense_crud_and_summary_scope(): void
    {
        [$hotel, $user] = $this->makeTenant();
        $type = ExpenseType::create([
            'hotel_id' => $hotel->id,
            'name' => 'Électricité',
        ]);

        $create = $this->actingAs($user, 'sanctum')->postJson('/api/v1/expenses', [
            'expense_type_id' => $type->id,
            'description' => 'Facture électricité',
            'amount_cents' => 45000,
            'incurred_on' => now()->toDateString(),
            'paid_on' => now()->toDateString(),
        ]);

        $create->assertCreated()->assertJson(['data' => ['amount_cents' => 45000]]);
        $expenseId = $create->json('data.id');

        // Pending expense stays out of totals but is counted as pending.
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/expenses', [
            'description' => 'En attente de règlement',
            'amount_cents' => 1000,
            'incurred_on' => now()->toDateString(),
            'status' => 'pending',
        ])->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/expenses/summary')
            ->assertOk()
            ->assertJson([
                'data' => [
                    'this_month_cents' => 46000,
                    'pending_count' => 1,
                    'this_month_count' => 2,
                ],
            ]);

        // Summary honors filters just like the list.
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/expenses/summary?expense_type_id='.$type->id)
            ->assertOk()
            ->assertJson([
                'data' => [
                    'this_month_cents' => 45000,
                    'pending_count' => 0,
                    'this_month_count' => 1,
                ],
            ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/expenses/summary?status=pending')
            ->assertOk()
            ->assertJson(['data' => ['this_month_cents' => 1000]]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/expenses?expense_type_id='.$type->id)
            ->assertOk()
            ->assertJson(['data' => ['total' => 1]]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/expenses/{$expenseId}", ['amount_cents' => 49000])
            ->assertOk()
            ->assertJson(['data' => ['amount_cents' => 49000]]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/expenses/{$expenseId}")
            ->assertOk();

        $this->assertDatabaseMissing('expenses', ['id' => $expenseId]);
    }

    public function test_expense_rejects_foreign_tenant_references(): void
    {
        [$hotelA, $userA] = $this->makeTenant();
        [$hotelB, $userB] = $this->makeTenant();

        $foreignType = ExpenseType::create(['hotel_id' => $hotelA->id, 'name' => 'A - Eau']);
        $foreignEmployee = Employee::factory()->create(['hotel_id' => $hotelA->id]);

        // User B cannot reference hotel A's expense type or employee (404 via ensureOwned).
        $this->actingAs($userB, 'sanctum')
            ->postJson('/api/v1/expenses', [
                'expense_type_id' => $foreignType->id,
                'description' => 'Cheat',
                'amount_cents' => 1,
                'incurred_on' => now()->toDateString(),
            ])
            ->assertNotFound();

        $this->actingAs($userB, 'sanctum')
            ->postJson('/api/v1/expenses', [
                'employee_id' => $foreignEmployee->id,
                'description' => 'Cheat',
                'amount_cents' => 1,
                'incurred_on' => now()->toDateString(),
            ])
            ->assertNotFound();
    }

    public function test_month_filter_clamps_out_of_range_end_date(): void
    {
        [$hotel, $user] = $this->makeTenant();
        $employee = Employee::factory()->create([
            'hotel_id' => $hotel->id,
            'salary_cents' => 50000,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/employees/{$employee->id}/pay", ['period' => '2026-09'])
            ->assertCreated();

        // 2026-09 has 30 days; an out-of-range end date must not zero the results.
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/expenses?from=2026-09-01&to=2026-09-31')
            ->assertOk()
            ->assertJson(['data' => ['total' => 1]]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/expenses/summary?from=2026-09-01&to=2026-09-31')
            ->assertOk()
            ->assertJson(['data' => ['this_month_cents' => 50000]]);
    }

    public function test_expense_type_delete_blocked_when_in_use(): void
    {
        [$hotel, $user] = $this->makeTenant();
        $type = ExpenseType::create(['hotel_id' => $hotel->id, 'name' => 'Eau']);

        Expense::create([
            'hotel_id' => $hotel->id,
            'expense_type_id' => $type->id,
            'description' => 'Facture',
            'amount_cents' => 500,
            'incurred_on' => now()->toDateString(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/expense-types/{$type->id}")
            ->assertStatus(422)
            ->assertJson(['error' => 'EXPENSE_TYPE_IN_USE']);

        $this->assertDatabaseHas('expense_types', ['id' => $type->id]);
    }

    public function test_front_desk_can_view_but_not_manage_employees(): void
    {
        [$hotel, $user] = $this->makeTenant('front-desk');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/employees')
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/employees', ['name' => 'Interdit'])
            ->assertForbidden();
    }
}