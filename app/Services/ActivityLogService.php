<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class ActivityLogService
{
    /**
     * Record an activity log entry.
     *
     * @param string $level INFO|WARNING|ERROR
     * @param string $activity
     * @param string|null $detail
     * @param string|null $username
     * @return ActivityLog
     */
    public static function log(string $level, string $activity, ?string $detail = null, ?string $username = null): ActivityLog
    {
        $resolvedUser = $username 
            ?? (session('username') ?? (Auth::check() ? Auth::user()->username : 'System'));

        $ip = Request::ip() ?? '-';

        return ActivityLog::create([
            'level' => strtoupper($level),
            'username' => $resolvedUser,
            'activity' => $activity,
            'detail' => $detail,
            'ip_address' => $ip,
            'created_at' => now(),
        ]);
    }
}
