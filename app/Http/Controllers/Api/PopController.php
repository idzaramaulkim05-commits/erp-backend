<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NetworkPopResource;
use App\Http\Resources\PopDeviceResource;
use App\Models\NetworkPop;
use App\Models\PopDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class PopController extends Controller
{
    public function index(Request $request)
    {
        $query = NetworkPop::query()->with('devices')->latest();

        if ($request->filled('region')) {
            $query->where('region', $request->query('region'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%");
            });
        }

        return NetworkPopResource::collection($query->get());
    }

    public function show(NetworkPop $pop)
    {
        $pop->load(['devices' => fn ($q) => $q->latest()]);
        return NetworkPopResource::make($pop);
    }

    public function store(Request $request)
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:64', 'unique:network_pops,code'],
            'region' => ['required', 'string', 'max:128'],
            'cluster_code' => ['nullable', 'string', 'max:32'],
            'address' => ['required', 'string'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'pic_name' => ['nullable', 'string', 'max:128'],
            'pic_phone' => ['nullable', 'string', 'max:32'],
            'power_backup_info' => ['nullable', 'string'],
            'rack_capacity' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:active,maintenance,inactive'],
            'notes' => ['nullable', 'string'],
        ]);

        $id = 'POP-' . strtoupper(Str::slug($payload['region'] ?? 'HUB', '')) . '-' . sprintf('%02d', NetworkPop::query()->count() + 1);
        while (NetworkPop::query()->where('id', $id)->exists()) {
            $id = 'POP-' . strtoupper(Str::slug($payload['region'] ?? 'HUB', '')) . '-' . sprintf('%02d', random_int(10, 99));
        }

        $pop = NetworkPop::query()->create([
            'id' => $id,
            'name' => $payload['name'],
            'code' => strtoupper($payload['code']),
            'region' => $payload['region'],
            'cluster_code' => $payload['cluster_code'] ?? null,
            'address' => $payload['address'],
            'latitude' => $payload['latitude'] ?? null,
            'longitude' => $payload['longitude'] ?? null,
            'pic_name' => $payload['pic_name'] ?? $request->user()->name,
            'pic_phone' => $payload['pic_phone'] ?? null,
            'power_backup_info' => $payload['power_backup_info'] ?? null,
            'rack_capacity' => $payload['rack_capacity'] ?? '24U (Terpakai 0U)',
            'status' => $payload['status'] ?? 'active',
            'notes' => $payload['notes'] ?? null,
        ]);

        return NetworkPopResource::make($pop->load('devices'));
    }

    public function update(Request $request, NetworkPop $pop)
    {
        $payload = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:64', 'unique:network_pops,code,' . $pop->id],
            'region' => ['sometimes', 'string', 'max:128'],
            'cluster_code' => ['nullable', 'string', 'max:32'],
            'address' => ['sometimes', 'string'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'pic_name' => ['nullable', 'string', 'max:128'],
            'pic_phone' => ['nullable', 'string', 'max:32'],
            'power_backup_info' => ['nullable', 'string'],
            'rack_capacity' => ['nullable', 'string'],
            'status' => ['sometimes', 'string', 'in:active,maintenance,inactive'],
            'notes' => ['nullable', 'string'],
        ]);

        $pop->update($payload);

        return NetworkPopResource::make($pop->load('devices'));
    }

    public function destroy(NetworkPop $pop)
    {
        $pop->delete();
        return response()->json(['message' => 'POP berhasil dihapus.']);
    }

    public function storeDevice(Request $request, NetworkPop $pop)
    {
        $payload = $request->validate([
            'category' => ['required', 'string'],
            'brand' => ['required', 'string'],
            'model' => ['required', 'string'],
            'serial_number' => ['nullable', 'string'],
            'mac_address' => ['nullable', 'string'],
            'ip_management' => ['nullable', 'string'],
            'rack_position' => ['nullable', 'string'],
            'power_source' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:active,backup,maintenance,faulty,decommissioned'],
            'installed_by' => ['nullable', 'string'],
            'specifications' => ['nullable', 'array'],
            'notes' => ['nullable', 'string'],
        ]);

        $deviceId = 'DEV-' . str_replace('POP-', '', $pop->id) . '-' . sprintf('%02d', $pop->devices()->count() + 1);
        while (PopDevice::query()->where('id', $deviceId)->exists()) {
            $deviceId = 'DEV-' . str_replace('POP-', '', $pop->id) . '-' . sprintf('%02d', random_int(20, 99));
        }

        $device = $pop->devices()->create([
            'id' => $deviceId,
            'category' => $payload['category'],
            'brand' => $payload['brand'],
            'model' => $payload['model'],
            'serial_number' => $payload['serial_number'] ?? null,
            'mac_address' => $payload['mac_address'] ?? null,
            'ip_management' => $payload['ip_management'] ?? null,
            'rack_position' => $payload['rack_position'] ?? null,
            'power_source' => $payload['power_source'] ?? null,
            'status' => $payload['status'] ?? 'active',
            'installed_at' => Carbon::now(),
            'installed_by' => $payload['installed_by'] ?? $request->user()->name,
            'last_checked_at' => Carbon::now(),
            'specifications' => $payload['specifications'] ?? [],
            'notes' => $payload['notes'] ?? null,
        ]);

        return PopDeviceResource::make($device);
    }

    public function updateDevice(Request $request, NetworkPop $pop, PopDevice $device)
    {
        abort_unless($device->network_pop_id === $pop->id, 404, 'Device tidak ditemukan di POP ini.');

        $payload = $request->validate([
            'category' => ['sometimes', 'string'],
            'brand' => ['sometimes', 'string'],
            'model' => ['sometimes', 'string'],
            'serial_number' => ['nullable', 'string'],
            'mac_address' => ['nullable', 'string'],
            'ip_management' => ['nullable', 'string'],
            'rack_position' => ['nullable', 'string'],
            'power_source' => ['nullable', 'string'],
            'status' => ['sometimes', 'string', 'in:active,backup,maintenance,faulty,decommissioned'],
            'specifications' => ['nullable', 'array'],
            'notes' => ['nullable', 'string'],
        ]);

        $device->update($payload);

        return PopDeviceResource::make($device);
    }

    public function destroyDevice(NetworkPop $pop, PopDevice $device)
    {
        abort_unless($device->network_pop_id === $pop->id, 404, 'Device tidak ditemukan di POP ini.');
        $device->delete();
        return response()->json(['message' => 'Perangkat berhasil dihapus dari POP.']);
    }
}
