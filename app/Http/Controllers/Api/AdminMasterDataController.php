<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminUpdateMasterDataGroupRequest;
use App\Http\Resources\AdminMasterDataGroupResource;
use App\Models\AdminMasterDataGroup;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminMasterDataController extends Controller
{
    public function index()
    {
        return AdminMasterDataGroupResource::collection(AdminMasterDataGroup::query()->orderBy('label')->get());
    }

    public function update(AdminUpdateMasterDataGroupRequest $request, string $group)
    {
        $masterGroup = AdminMasterDataGroup::query()->findOrFail($group);

        $masterGroup->update([
            'label' => $request->input('label', $masterGroup->label),
            'items' => $request->input('items', []),
            'editable_fields' => $request->input('editable_fields', $masterGroup->editable_fields),
        ]);

        $actor = $request->user();
        AuditLog::query()->create([
            'id' => 'LOG-'.Str::upper(Str::random(8)),
            'timestamp' => now(),
            'actor_name' => $actor->name,
            'actor_role' => $actor->role,
            'action' => 'Update Master Data',
            'target' => $masterGroup->key,
            'details' => 'Kelompok master data diperbarui oleh superadmin.',
            'type' => 'info',
        ]);

        return AdminMasterDataGroupResource::make($masterGroup->fresh());
    }
}
