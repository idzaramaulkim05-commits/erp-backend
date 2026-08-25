<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\HelpdeskCloseTicketRequest;
use App\Http\Requests\LeadApprovalRequest;
use App\Http\Requests\NocCloseRequest;
use App\Http\Requests\StoreTicketRequest;
use App\Http\Requests\TicketActionRequest;
use App\Http\Resources\TicketResource;
use App\Models\TroubleTicket;
use App\Services\WorkflowService;
class TicketController extends Controller
{
    public function __construct(private readonly WorkflowService $workflow) {}

    public function index()
    {
        return TicketResource::collection(TroubleTicket::query()->latest()->get());
    }

    public function store(StoreTicketRequest $request)
    {
        return TicketResource::make($this->workflow->createTicket($request->validated(), $request->user()));
    }

    public function remoteResolve(TicketActionRequest $request, TroubleTicket $ticket)
    {
        return TicketResource::make($this->workflow->resolveTicketRemotely($ticket, $request->user(), $request->string('notes')->toString()));
    }

    public function escalate(TicketActionRequest $request, TroubleTicket $ticket)
    {
        return TicketResource::make($this->workflow->escalateTicket(
            $ticket,
            $request->user(),
            $request->string('notes')->toString(),
            [
                'requires_replacement_request' => $request->boolean('requires_replacement_request'),
                'replacement_items' => $request->validated('replacement_items') ?? [],
            ],
        ));
    }

    public function leadApprove(LeadApprovalRequest $request, TroubleTicket $ticket)
    {
        return TicketResource::make($this->workflow->leadApprove($ticket, $request->validated(), $request->user()));
    }

    public function nocClose(NocCloseRequest $request, TroubleTicket $ticket)
    {
        return TicketResource::make($this->workflow->nocClose($ticket, $request->validated(), $request->user()));
    }

    public function helpdeskClose(HelpdeskCloseTicketRequest $request, TroubleTicket $ticket)
    {
        return TicketResource::make($this->workflow->helpdeskCloseTicket($ticket, $request->validated(), $request->user()));
    }
}
