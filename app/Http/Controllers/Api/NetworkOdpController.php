<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NetworkOdpResource;
use App\Models\NetworkOdp;

class NetworkOdpController extends Controller
{
    public function index()
    {
        return NetworkOdpResource::collection(NetworkOdp::query()->with('ports')->orderBy('id')->get());
    }
}
