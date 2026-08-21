<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskStatusRequest;
use App\Http\Resources\TaskResource;
use App\Models\InterDivisionTask;
use App\Services\WorkflowService;

class TaskController extends Controller
{
    public function __construct(private readonly WorkflowService $workflow) {}

    public function index()
    {
        return TaskResource::collection(InterDivisionTask::query()->orderBy('created_at', 'desc')->get());
    }

    public function store(StoreTaskRequest $request)
    {
        return TaskResource::make($this->workflow->createTask($request->validated(), $request->user()));
    }

    public function updateStatus(UpdateTaskStatusRequest $request, InterDivisionTask $task)
    {
        return TaskResource::make(
            $this->workflow->updateTaskStatus($task, $request->string('status')->toString(), $request->user(), $request->string('resolution_notes')->toString() ?: null)
        );
    }
}
