<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryItem extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'code',
        'name',
        'category',
        'brand',
        'model',
        'stock_available',
        'stock_in_use',
        'stock_reserved',
        'min_threshold',
        'unit',
        'unit_price',
        'location_rack',
    ];

    protected function casts(): array
    {
        return [
            'stock_available' => 'integer',
            'stock_in_use' => 'integer',
            'stock_reserved' => 'integer',
            'min_threshold' => 'integer',
            'unit_price' => 'integer',
        ];
    }

    public function serials()
    {
        return $this->hasMany(InventorySerial::class);
    }
}
