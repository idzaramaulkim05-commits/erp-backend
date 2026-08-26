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

    public function update(ServiceRegistration $serviceRegistration)
    {
        $payload = request()->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'nik' => ['sometimes', 'required', 'string', 'max:32'],
            'gender' => ['sometimes', 'required', 'string', 'max:32'],
            'phone' => ['sometimes', 'required', 'string', 'max:32'],
            'address' => ['sometimes', 'required', 'string'],
            'region' => ['sometimes', 'required', 'string', 'max:255'],
            'package_plan' => ['sometimes', 'required', 'string', 'max:255'],
            'monthly_fee' => ['sometimes', 'required', 'numeric', 'min:0'],
            'installation_fee' => ['nullable', 'numeric', 'min:0'],
            'odp_id' => ['nullable', 'string'],
            'share_location_url' => ['nullable', 'string', 'max:500'],
            'house_photo' => ['nullable'],
            'resubmit' => ['nullable', 'boolean'],
        ]);

        if (request()->hasFile('house_photo')) {
            $payload['house_photo'] = request()->file('house_photo')->store('service-registrations/house-photos', 'public');
        }

        $resubmit = request()->boolean('resubmit', false);

        return ServiceRegistrationResource::make(
            $this->workflow->updateServiceRegistration($serviceRegistration, $payload, $this->user(), $resubmit)
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
            'odp_id' => ['nullable', 'string'],
            'odp_port_candidate' => ['nullable', 'integer', 'min:1'],
            'path_available' => ['nullable', 'boolean'],
            'odp_available' => ['nullable', 'boolean'],
            'recommended_team' => ['nullable', 'string', 'max:255'],
            'required_materials' => ['nullable', 'array'],
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
