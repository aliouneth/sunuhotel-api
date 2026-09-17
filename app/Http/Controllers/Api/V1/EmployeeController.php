<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('employees.view');

        $employees = Employee::query()
            ->withCount(['expenses as total_paid_cents' => function ($query) {
                $query->where('status', 'paid');
            }])
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->paginate(50);

        return response()->json(['data' => $employees]);
    }

    public function store(Request $request)
    {
        $this->authorize('employees.manage');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'position' => ['nullable', 'string', 'max:80'],
            'salary_cents' => ['nullable', 'integer', 'min:0'],
            'hire_date' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        $employee = Employee::create([
            'hotel_id' => $request->user()->hotel_id,
            'name' => $validated['name'],
            'position' => $validated['position'] ?? null,
            'salary_cents' => $validated['salary_cents'] ?? 0,
            'hire_date' => $validated['hire_date'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            'notes' => $validated['notes'] ?? null,
        ]);

        AuditLogger::critical($employee, 'employee.created');

        return response()->json(['data' => $employee], 201);
    }

    public function show(Request $request, Employee $employee)
    {
        $this->authorize('employees.view');

        $employee->loadCount(['expenses as total_paid_cents' => function ($query) {
            $query->where('status', 'paid');
        }]);

        return response()->json(['data' => $employee]);
    }

    public function update(Request $request, Employee $employee)
    {
        $this->authorize('employees.manage');

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'position' => ['nullable', 'string', 'max:80'],
            'salary_cents' => ['sometimes', 'integer', 'min:0'],
            'hire_date' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        $employee->update($validated);
        AuditLogger::critical($employee, 'employee.updated');

        return response()->json(['data' => $employee->fresh()]);
    }

    public function destroy(Request $request, Employee $employee)
    {
        $this->authorize('employees.manage');

        $employee->update(['is_active' => false]);
        AuditLogger::critical($employee, 'employee.deactivated');

        return response()->json(['message' => 'Employee deactivated.']);
    }

    /**
     * Register a salary payment. The payment is recorded as an expense linked
     * to the built-in 'salary' expense type, so payroll shows in hotel costs.
     */
    public function pay(Request $request, Employee $employee)
    {
        $this->authorize('expenses.manage');

        $validated = $request->validate([
            'period' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
            'amount_cents' => ['nullable', 'integer', 'min:1'],
            'paid_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $hotel = $request->user()->hotel;

        $period = $validated['period'] ?? null;
        $incurredOn = $period !== null
            ? \Carbon\Carbon::createFromFormat('Y-m', $period)->endOfMonth()
            : now();

        $expense = \DB::transaction(function () use ($employee, $hotel, $validated, $incurredOn) {
            $type = $hotel->salaryExpenseType();

            $expense = \App\Models\Expense::create([
                'hotel_id' => $hotel->id,
                'expense_type_id' => $type->id,
                'employee_id' => $employee->id,
                'description' => 'Salaire — '.$employee->name.($validated['period'] ?? null ? ' — '.$validated['period'] : ''),
                'amount_cents' => $validated['amount_cents'] ?? $employee->salary_cents,
                'incurred_on' => $incurredOn->toDateString(),
                'paid_on' => $validated['paid_on'] ?? now()->toDateString(),
                'status' => 'paid',
                'created_by_name' => auth('sanctum')->user()->name,
                'notes' => $validated['notes'] ?? null,
            ]);

            AuditLogger::critical($expense, 'employee.salary_paid', ['employee_id' => $employee->id]);

            return $expense;
        });

        return response()->json([
            'data' => $expense->load(['expenseType', 'employee']),
        ], 201);
    }
}