<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SystemSessionResource;
use App\Models\User;

class AdminSessionController extends Controller
{
    public function index()
    {
        return SystemSessionResource::collection(
            User::query()->orderByDesc('is_online')->orderByDesc('last_login_at')->get()
        );
    }
}
