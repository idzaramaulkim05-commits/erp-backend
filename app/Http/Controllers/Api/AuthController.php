<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(LoginRequest $request)
    {
        $user = User::query()->where('email', $request->string('email'))->first();

        if (! $user || ! Hash::check($request->string('password'), $user->password)) {
            $this->logAuthEvent(
                actorName: $user?->name ?? 'Unknown User',
                actorRole: $user?->role ?? 'guest',
                action: 'Login Failed',
                target: $request->string('email')->toString(),
                details: 'Percobaan login gagal.',
                type: 'warning',
            );

            abort(422, 'Email atau password tidak valid.');
        }

        abort_if(! $user->is_active, 403, 'Akun Anda sedang dinonaktifkan. Hubungi superadmin.');
        abort_if(
            ! Role::query()->where('key', $user->role)->where('is_active', true)->exists(),
            403,
            'Role akun Anda sedang dinonaktifkan. Hubungi superadmin.'
        );

        $token = $user->createToken('frontend')->plainTextToken;
        $user->update(['is_online' => true, 'last_login_at' => now()]);
        $this->logAuthEvent($user->name, $user->role, 'Login Success', $user->email, 'Pengguna berhasil masuk.', 'success');

        return response()->json([
            'token' => $token,
            'user' => UserResource::make($user),
        ]);
    }

    public function me(Request $request)
    {
        return UserResource::make($request->user());
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $payload = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'avatar' => ['nullable', 'string'],
            'avatar_file' => ['nullable', 'image', 'max:5120'], // max 5MB
            'current_password' => ['nullable', 'string'],
            'new_password' => ['nullable', 'string', 'min:6'],
        ]);

        if (isset($payload['name'])) {
            $user->name = $payload['name'];
        }

        if (array_key_exists('phone', $payload)) {
            $user->phone = $payload['phone'];
        }

        if ($request->hasFile('avatar_file')) {
            $path = $request->file('avatar_file')->store('avatars', 'public');
            $user->avatar = asset('storage/' . $path);
        } elseif (isset($payload['avatar'])) {
            $user->avatar = $payload['avatar'];
        }

        // Handle password change within profile update if provided
        if (!empty($payload['new_password'])) {
            abort_unless(
                !empty($payload['current_password']) && Hash::check($payload['current_password'], $user->password),
                422,
                'Password saat ini tidak cocok atau belum diisi.'
            );
            $user->password = $payload['new_password'];
        }

        $user->save();

        $this->logAuthEvent($user->name, $user->role, 'Updated Profile', $user->email, 'Pengguna memperbarui informasi profil akun.', 'info');

        return response()->json([
            'message' => 'Profil berhasil diperbarui.',
            'user' => UserResource::make($user->fresh()),
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();
        $user->currentAccessToken()?->delete();
        $user->update(['is_online' => false]);
        $this->logAuthEvent($user->name, $user->role, 'Logout', $user->email, 'Pengguna keluar dari sistem.', 'info');

        return response()->json(['message' => 'Logged out']);
    }

    public function changePassword(ChangePasswordRequest $request)
    {
        $user = $request->user();
        abort_unless(Hash::check($request->string('current_password'), $user->password), 422, 'Password saat ini tidak valid.');

        $user->forceFill([
            'password' => $request->string('password')->toString(),
        ])->save();

        $user->tokens()->delete();

        $this->logAuthEvent($user->name, $user->role, 'Password Changed', $user->email, 'Pengguna mengganti password.', 'success');

        return response()->json(['message' => 'Password berhasil diubah. Silakan login ulang.']);
    }

    public function forgotPassword(ForgotPasswordRequest $request)
    {
        $status = Password::sendResetLink($request->safe()->only('email'));

        $this->logAuthEvent(
            actorName: 'Password Broker',
            actorRole: 'system',
            action: 'Password Reset Requested',
            target: $request->string('email')->toString(),
            details: 'Permintaan reset password diterima.',
            type: $status === Password::RESET_LINK_SENT ? 'info' : 'warning',
        );

        return response()->json([
            'message' => __($status),
        ], $status === Password::RESET_LINK_SENT ? 200 : 422);
    }

    public function resetPassword(ResetPasswordRequest $request)
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => __($status)], 422);
        }

        $user = User::query()->where('email', $request->string('email'))->first();
        if ($user) {
            $user->tokens()->delete();
            $this->logAuthEvent($user->name, $user->role, 'Password Reset Completed', $user->email, 'Password berhasil direset.', 'success');
        }

        return response()->json(['message' => __($status)]);
    }

    private function logAuthEvent(
        string $actorName,
        string $actorRole,
        string $action,
        string $target,
        string $details,
        string $type
    ): void {
        AuditLog::query()->create([
            'id' => 'LOG-'.Str::upper(Str::random(8)),
            'timestamp' => now(),
            'actor_name' => $actorName,
            'actor_role' => $actorRole,
            'action' => $action,
            'target' => $target,
            'details' => $details,
            'type' => $type,
        ]);
    }
}
