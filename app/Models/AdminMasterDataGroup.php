<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminMasterDataGroup extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = 'key';

    protected $fillable = [
        'key',
        'label',
        'items',
        'editable_fields',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'editable_fields' => 'array',
        ];
    }
}
