<?php

namespace App\Services;

use App\Models\AppNavigationModule;
use App\Models\NavigationHead;
use App\Models\RoleModuleMapping;
use Illuminate\Support\Collection;

class NavigationConfigBuilder
{
    public function buildForRole(string $role): array
    {
        $heads = NavigationHead::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $modules = AppNavigationModule::query()
            ->where('is_active', true)
            ->where('show_in_navbar', true)
            ->orderBy('sort_order')
            ->get();

        if ($role === 'superadmin') {
            $allowedModuleKeys = $modules->pluck('module_key')->values()->all();
            $resolvedModules = $modules;
        } else {
            $mappingByModule = RoleModuleMapping::query()
                ->where('role', $role)
                ->where('is_visible', true)
                ->get()
                ->keyBy('module_key');

            $allowedModuleKeys = $mappingByModule->keys()->values()->all();
            $resolvedModules = $modules
                ->filter(fn (AppNavigationModule $module) => $mappingByModule->has($module->module_key))
                ->map(function (AppNavigationModule $module) use ($mappingByModule) {
                    $override = $mappingByModule->get($module->module_key)?->order_override;
                    if ($override !== null) {
                        $module->setAttribute('sort_order', $override);
                    }

                    return $module;
                })
                ->sortBy('sort_order')
                ->values();
        }

        $resolvedHeadKeys = collect($resolvedModules)->pluck('navigation_head_key')->unique()->values();
        $resolvedHeads = $heads
            ->filter(fn (NavigationHead $head) => $resolvedHeadKeys->contains($head->key))
            ->values();

        return [
            'role' => $role,
            'heads' => $resolvedHeads,
            'modules' => $resolvedModules instanceof Collection ? $resolvedModules->values() : collect($resolvedModules)->values(),
            'allowedModuleKeys' => $allowedModuleKeys,
        ];
    }
}
