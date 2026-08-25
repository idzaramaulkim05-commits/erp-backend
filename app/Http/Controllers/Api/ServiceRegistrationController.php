<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\NocApproveServiceRegistrationRequest;
use App\Http\Requests\ServiceRegistrationDecisionRequest;
use App\Http\Requests\StoreServiceRegistrationRequest;
use App\Http\Resources\ServiceRegistrationResource;
use App\Models\ServiceRegistration;
use App\Services\WorkflowService;

class ServiceRegistrationController extends Controller
{
    public function __construct(private readonly WorkflowService $workflow) {}

    public function index()
    {
        return ServiceRegistrationResource::collection(
            ServiceRegistration::query()->with(['requestedBy'])->latest()->get()
        );
    }

    public function store(StoreServiceRegistrationRequest $request)
    {
        $payload = $request->validated();

        if ($request->hasFile('house_photo')) {
            $payload['house_photo'] = $request->file('house_photo')->store('service-registrations/house-photos', 'public');
        }

        return ServiceRegistrationResource::make(
            $this->workflow->createServiceRegistration($payload, $request->user())
        );
    }

    public function show(ServiceRegistration $serviceRegistration)
    {
        return ServiceRegistrationResource::make($serviceRegistration->load(['requestedBy']));
    }

    public function submit(ServiceRegistration $serviceRegistration)
    {
        return ServiceRegistrationResource::make(
            $this->workflow->submitServiceRegistration($serviceRegistration, $this->user())
        );
    }

    public function validateRegistration(ServiceRegistration $serviceRegistration)
    {
        $payload = request()->validate([
            'is_valid' => ['required', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        return ServiceRegistrationResource::make(
            $this->workflow->validateServiceRegistration($serviceRegistration, $payload, $this->user())
        );
    }

    public function survey(ServiceRegistration $serviceRegistration)
    {
        $payload = request()->validate([
            'result' => ['required', 'string', 'in:layak,tidak_layak'],
            'notes' => ['nullable', 'string'],
            'odp_id' => ['nullable', 'string', 'exists:network_odps,id'],
            'odp_port_candidate' => ['nullable', 'integer', 'min:1'],
            'path_available' => ['nullable', 'boolean'],
            'odp_available' => ['nullable', 'boolean'],
            'recommended_team' => ['nullable', 'string', 'max:255'],
            'required_materials' => ['required_if:result,layak', 'array', 'min:1'],
            'required_materials.*.itemName' => ['required_with:required_materials', 'string', 'min:1'],
            'required_materials.*.quantity' => ['required_with:required_materials', 'integer', 'min:1'],
            'required_materials.*.unit' => ['required_with:required_materials', 'string', 'min:1'],
        ]);

        return ServiceRegistrationResource::make(
            $this->workflow->surveyServiceRegistration($serviceRegistration, $payload, $this->user())
        );
    }

    public function financeApprove(ServiceRegistrationDecisionRequest $request, ServiceRegistration $serviceRegistration)
    {
        return ServiceRegistrationResource::make(
            $this->workflow->financeApproveServiceRegistration($serviceRegistration, $request->user(), $request->string('notes')->toString() ?: null)
        );
    }

    public function financeReject(ServiceRegistrationDecisionRequest $request, ServiceRegistration $serviceRegistration)
    {
        return ServiceRegistrationResource::make(
            $this->workflow->financeRejectServiceRegistration($serviceRegistration, $request->user(), $request->string('notes')->toString() ?: null)
        );
    }

    public function generatePppoe(ServiceRegistration $serviceRegistration)
    {
        return ServiceRegistrationResource::make(
            $this->workflow->generateServiceRegistrationPppoe($serviceRegistration, $this->user())
        );
    }

    public function nocApprove(NocApproveServiceRegistrationRequest $request, ServiceRegistration $serviceRegistration)
    {
        return ServiceRegistrationResource::make(
            $this->workflow->nocApproveServiceRegistration(
                $serviceRegistration,
                $request->user(),
                $request->string('notes')->toString() ?: null,
                $request->integer('odp_port_candidate') ?: null,
            )
        );
    }

    public function nocReject(ServiceRegistrationDecisionRequest $request, ServiceRegistration $serviceRegistration)
    {
        return ServiceRegistrationResource::make(
            $this->workflow->nocRejectServiceRegistration($serviceRegistration, $request->user(), $request->string('notes')->toString() ?: null)
        );
    }

    public function createWorkOrder(ServiceRegistration $serviceRegistration)
    {
        return ServiceRegistrationResource::make(
            $this->workflow->createInstallationWorkOrderFromRegistration($serviceRegistration, $this->user())
        );
    }

    private function user()
    {
        return request()->user();
    }
}
