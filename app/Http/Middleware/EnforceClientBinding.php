<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Client;

class EnforceClientBinding
{
    public function handle(Request $request, Closure $next)
    {
        $clientId = $request->header('X-Client-Id');
        $secret = $request->header('X-Client-Secret');

        if (!$clientId || !$secret) {
            return $this->deny('Client tidak dikenal', 401);
        }

        $client = Client::find($clientId);

        if (!$client || $client->revoked) {
            return $this->deny('Client tidak valid', 401);
        }

        // Cek binding lebih dulu supaya client tanpa binding tidak
        // memicu Hash::check (bcrypt, mahal) sebelum ditolak.
        $binding = DB::table('oauth_client_bindings')->where('client_id', $clientId)->first();

        if (!$binding) {
            return $this->deny('Client belum terdaftar', 401);
        }

        if (!Hash::check($secret, (string) $client->secret)) {
            return $this->deny('Client tidak valid', 401);
        }

        // Web: satu client untuk satu domain. Origin wajib ada dan harus sama persis.
        if ($binding->platform === 'web') {
            $allowed = rtrim((string) $binding->allowed_origin, '/');
            $origin = rtrim((string) $request->header('Origin'), '/');

            if ($allowed === '' || $origin !== $allowed) {
                return $this->deny('Client tidak boleh dipakai dari sini', 403);
            }
        }

        // Android / iOS: Origin berisi nama app yang terdaftar, dicek sama seperti
        // client id dan secret. Browser tidak bisa mengirim Origin berupa nama
        // app (bukan URL), jadi request dari browser tetap tertolak.
        // if ($binding->platform === 'mobile') {
        if (in_array($binding->platform, ['android', 'ios'], true)) {
            $allowed = trim((string) $binding->allowed_origin);
            $origin = trim((string) $request->header('Origin'));

            if ($allowed === '' || !hash_equals($allowed, $origin)) {
                return $this->deny('Client tidak boleh dipakai dari sini', 403);
            }
        }

        return $next($request);
    }

    private function deny(string $message, int $code)
    {
        return response()->json([
            'meta' => ['message' => $message, 'status' => false, 'code' => $code],
            'data' => [],
        ], $code);
    }
}