<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NavigationConfigResource;
use App\Services\NavigationConfigBuilder;
use Illuminate\Http\Request;

class AuthNavigationController extends Controller
{
    public function __construct(private readonly NavigationConfigBuilder $navigationConfigBuilder)
    {
    }

    public function show(Request $request)
    {
        return NavigationConfigResource::make(
            $this->navigationConfigBuilder->buildForRole($request->user()->role)
        );
    }
}
