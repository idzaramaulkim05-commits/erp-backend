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
        return ServiceRegistrationResource::make(
            $this->workflow->createServiceRegistration($request->validated(), $request->user())
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
