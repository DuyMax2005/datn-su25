<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'customer_name',
        'email',
        'phone',
        'wallet',
    ];

    protected $dates = ['deleted_at'];

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }
}