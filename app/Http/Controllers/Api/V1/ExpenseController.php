<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('expenses.view');

        $expenses = Expense::query()
            ->with(['expenseType:id,name,color', 'employee:id,name'])
            ->when($request->filled('expense_type_id'), fn ($q) => $q->where('expense_type_id', $request->integer('expense_type_id')))
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->integer('employee_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('incurred_on', '>=', $request->string('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('incurred_on', '<=', $this->normalizeTo($request->string('to')->toString())))
            ->when($request->filled('search'), fn ($q) => $q->where('description', 'like', '%'.$request->string('search').'%'))
            ->orderByDesc('incurred_on')
            ->orderByDesc('id')
            ->paginate(50);

        return response()->json(['data' => $expenses]);
    }

    /**
     * Lightweight totals (only non-cancelled rows) for the expense dashboard.
     * Honors the same filters as index().
     */
    public function summary(Request $request)
    {
        $this->authorize('expenses.view');

        $query = Expense::query()->where('status', '!=', 'cancelled')
            ->when($request->filled('expense_type_id'), fn ($q) => $q->where('expense_type_id', $request->integer('expense_type_id')))
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->integer('employee_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('incurred_on', '>=', $request->string('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('incurred_on', '<=', $this->normalizeTo($request->string('to')->toString())));

        $thisMonth = (clone $query)->whereYear('incurred_on', now()->year)->whereMonth('incurred_on', now()->month);
        $thisYear = (clone $query)->whereYear('incurred_on', now()->year);

        return response()->json([
            'data' => [
                'this_month_cents' => (int) $thisMonth->sum('amount_cents'),
                'this_year_cents' => (int) $thisYear->sum('amount_cents'),
                'total_cents' => (int) (clone $query)->sum('amount_cents'),
                'pending_count' => (int) (clone $query)->where('status', 'pending')->count(),
                'this_month_count' => (int) $thisMonth->count(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('expenses.manage');

        $validated = $request->validate([
            'expense_type_id' => ['nullable', 'exists:expense_types,id'],
            'employee_id' => ['nullable', 'exists:employees,id'],
            'description' => ['required', 'string', 'max:200'],
            'amount_cents' => ['required', 'integer', 'min:1'],
            'incurred_on' => ['required', 'date'],
            'paid_on' => ['nullable', 'date'],
            'status' => ['sometimes', 'in:pending,paid,cancelled'],
            'notes' => ['nullable', 'string'],
        ]);

        $hotel = $request->user()->hotel;
        $this->ensureOwned($hotel, $validated);

        $expense = Expense::create([
            'hotel_id' => $request->user()->hotel_id,
            'expense_type_id' => $validated['expense_type_id'] ?? null,
            'employee_id' => $validated['employee_id'] ?? null,
            'description' => $validated['description'],
            'amount_cents' => $validated['amount_cents'],
            'incurred_on' => $validated['incurred_on'],
            'paid_on' => $validated['paid_on'] ?? null,
            'status' => $validated['status'] ?? 'paid',
            'created_by_name' => auth('sanctum')->user()->name,
            'notes' => $validated['notes'] ?? null,
        ]);

        AuditLogger::critical($expense, 'expense.created', [
            'amount_cents' => $expense->amount_cents,
        ]);

        return response()->json([
            'data' => $expense->load(['expenseType', 'employee']),
        ], 201);
    }

    public function show(Request $request, Expense $expense)
    {
        $this->authorize('expenses.view');

        return response()->json(['data' => $expense->load(['expenseType', 'employee'])]);
    }

    public function update(Request $request, Expense $expense)
    {
        $this->authorize('expenses.manage');

        $validated = $request->validate([
            'expense_type_id' => ['nullable', 'exists:expense_types,id'],
            'employee_id' => ['nullable', 'exists:employees,id'],
            'description' => ['sometimes', 'string', 'max:200'],
            'amount_cents' => ['sometimes', 'integer', 'min:1'],
            'incurred_on' => ['sometimes', 'date'],
            'paid_on' => ['nullable', 'date'],
            'status' => ['sometimes', 'in:pending,paid,cancelled'],
            'notes' => ['nullable', 'string'],
        ]);

        $this->ensureOwned($request->user()->hotel, $validated);

        $expense->update($validated);
        AuditLogger::critical($expense, 'expense.updated');

        return response()->json([
            'data' => $expense->fresh(['expenseType', 'employee']),
        ]);
    }

    private function ensureOwned(\App\Models\Hotel $hotel, array $validated): void
    {
        if (! empty($validated['expense_type_id'])) {
            $hotel->expenseTypes()->findOrFail($validated['expense_type_id']);
        }
        if (! empty($validated['employee_id'])) {
            $hotel->employees()->findOrFail($validated['employee_id']);
        }
    }

    /**
     * Clamp an out-of-range day (e.g. 2026-09-31) to the real last day of its
     * month so month range filters never silently match zero rows.
     */
    private function normalizeTo(string $to): string
    {
        if (preg_match('/^(\d{4}-\d{2})-(\d{2})$/', $to, $m)) {
            $month = \Carbon\Carbon::createFromFormat('Y-m', $m[1]);
            $last = $month->endOfMonth()->format('d');
            if ((int) $m[2] > (int) $last) {
                return $m[1].'-'.$last;
            }
        }

        return $to;
    }

    public function destroy(Request $request, Expense $expense)
    {
        $this->authorize('expenses.manage');

        $expense->delete();
        AuditLogger::critical($expense, 'expense.deleted');

        return response()->json(['message' => 'Deleted.']);
    }
}