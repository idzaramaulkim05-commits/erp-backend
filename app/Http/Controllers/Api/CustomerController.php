<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerStatusRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\WorkflowService;
class CustomerController extends Controller
{
    public function __construct(private readonly WorkflowService $workflow) {}

    public function index()
    {
        return CustomerResource::collection(Customer::query()->with('assignedTechnician')->latest()->get());
    }

    public function store(StoreCustomerRequest $request)
    {
        return CustomerResource::make($this->workflow->registerCustomer($request->validated(), $request->user()));
    }

    public function updateStatus(UpdateCustomerStatusRequest $request, Customer $customer)
    {
        return CustomerResource::make(
            $this->workflow->updateCustomerStatus($customer, $request->string('status')->toString(), $request->user(), $request->string('notes')->toString() ?: null)
        );
    }

    public function recordPayment(Customer $customer)
    {
        $payload = request()->validate([
            'notes' => ['nullable', 'string'],
            'paid_at' => ['nullable', 'date'],
            'payment_channel' => ['nullable', 'string'],
        ]);

        return CustomerResource::make(
            $this->workflow->recordCustomerPayment(
                $customer,
                request()->user(),
                $payload['notes'] ?? null,
                $payload['paid_at'] ?? null,
                $payload['payment_channel'] ?? null
            )
        );
    }
}
