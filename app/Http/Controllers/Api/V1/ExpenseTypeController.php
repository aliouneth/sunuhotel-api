<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ExpenseType;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ExpenseTypeController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('expenses.view');

        $types = ExpenseType::query()
            ->withCount('expenses')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $types]);
    }

    public function store(Request $request)
    {
        $this->authorize('expenses.manage');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:20'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $type = ExpenseType::create([
            'hotel_id' => $request->user()->hotel_id,
            'name' => $validated['name'],
            'key' => null,
            'color' => $validated['color'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        AuditLogger::critical($type, 'expense_type.created');

        return response()->json(['data' => $type], 201);
    }

    public function update(Request $request, ExpenseType $expenseType)
    {
        $this->authorize('expenses.manage');

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['name'])) {
            $validated['key'] = $expenseType->key ?? Str::slug($validated['name']);
        }

        $expenseType->update($validated);
        AuditLogger::critical($expenseType, 'expense_type.updated');

        return response()->json(['data' => $expenseType->fresh()]);
    }

    public function destroy(Request $request, ExpenseType $expenseType)
    {
        $this->authorize('expenses.manage');

        if ($expenseType->expenses()->exists()) {
            return response()->json([
                'message' => 'Expense type has expenses; reassign them first.',
                'error' => 'EXPENSE_TYPE_IN_USE',
            ], 422);
        }

        $expenseType->delete();
        AuditLogger::critical($expenseType, 'expense_type.deleted');

        return response()->json(['message' => 'Deleted.']);
    }
}