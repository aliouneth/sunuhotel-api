<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    use BelongsToHotel, HasFactory;

    public const STATUSES = ['pending', 'paid', 'cancelled'];

    protected $fillable = [
        'hotel_id', 'expense_type_id', 'employee_id', 'description', 'amount_cents',
        'incurred_on', 'paid_on', 'status', 'created_by_name', 'notes',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'incurred_on' => 'date',
        'paid_on' => 'date',
    ];

    public function expenseType()
    {
        return $this->belongsTo(ExpenseType::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}