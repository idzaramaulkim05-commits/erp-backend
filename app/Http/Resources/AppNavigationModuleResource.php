<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppNavigationModuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->module_key,
            'label' => $this->label,
            'description' => $this->description,
            'navigationHeadKey' => $this->navigation_head_key,
            'order' => (int) $this->sort_order,
            'routeTarget' => $this->route_target,
            'quickAction' => $this->quick_action,
            'viewFormats' => $this->view_formats ?? [],
            'isActive' => (bool) $this->is_active,
            'showInNavbar' => (bool) $this->show_in_navbar,
            'adminOnlyDashboard' => (bool) $this->admin_only_dashboard,
        ];
    }
}
