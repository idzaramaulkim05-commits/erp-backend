<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InventoryItemResource;
use App\Models\InventoryItem;

class InventoryController extends Controller
{
    public function index()
    {
        return InventoryItemResource::collection(InventoryItem::query()->with('serials')->orderBy('name')->get());
    }
}
