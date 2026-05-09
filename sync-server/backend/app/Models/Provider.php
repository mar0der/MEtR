<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Provider extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'display_name',
    ];

    public function providerAccounts()
    {
        return $this->hasMany(ProviderAccount::class);
    }

    public function modelPrices()
    {
        return $this->hasMany(ModelPrice::class);
    }
}
