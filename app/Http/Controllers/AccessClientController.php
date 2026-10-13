<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;

class AccessClientController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = DB::table('oauth_clients as c')
            ->join('oauth_client_bindings as b', 'b.client_id', '=', 'c.id')
            ->where('c.revoked', false)
            ->orderByDesc('c.created_at')
            ->get([
                'c.id',
                'c.name',
                'b.platform',
                'b.allowed_origin',
                'c.created_at',
            ]);

        return $this->respond('Daftar frontend', $rows);
    }

    public function store(Request $request, ClientRepository $clients): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            // 'platform' => ['required', 'in:web,mobile'],
            'platform' => ['required', 'in:web,android,ios'],
            // Web: URL domain (Origin dari browser).
            // Android / iOS: nama app tanpa spasi, mis. "auroapp-android" (dikirim sebagai header Origin).
            'allowed_origin' => [
                'required',
                'string',
                'max:255',
                $request->input('platform') === 'web' ? 'url' : 'regex:/^[A-Za-z0-9._-]+$/',
                'unique:oauth_client_bindings,allowed_origin',
            ],
        ]);

        // Header Origin dari browser tidak punya slash di akhir.
        $origin = $data['platform'] === 'web'
            ? rtrim($data['allowed_origin'], '/')
            : trim($data['allowed_origin']);

        [$client, $plainSecret] = DB::transaction(function () use ($clients, $data, $origin) {
            $client = $clients->createPasswordGrantClient($data['name'], 'users');
            $plainSecret = $this->assignNewSecret($client);

            DB::table('oauth_client_bindings')->insert([
                'client_id' => $client->id,
                'platform' => $data['platform'],
                'allowed_origin' => $origin,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [$client, $plainSecret];
        });

        return $this->credentials(
            $client,
            $plainSecret,
            'Frontend dibuat. Simpan secret sekarang.',
            201,
            // $data['platform'] === 'mobile' ? $origin : null
            in_array($data['platform'], ['android', 'ios'], true) ? $origin : null
        );
    }

    public function revoke(string $id): JsonResponse
    {
        // Hanya client frontend (punya binding). Personal access client
        // milik Passport tidak boleh ikut tercabut lewat endpoint ini.
        abort_unless(
            DB::table('oauth_client_bindings')->where('client_id', $id)->exists(),
            404,
            'Frontend tidak ditemukan'
        );

        $client = Client::findOrFail($id);

        DB::transaction(function () use ($client, $id) {
            $tokenIds = $client->tokens()->pluck('id');

            $client->tokens()->update(['revoked' => true]);
            DB::table('oauth_refresh_tokens')
                ->whereIn('access_token_id', $tokenIds)
                ->update(['revoked' => true]);

            $client->forceFill(['revoked' => true])->save();
            DB::table('oauth_client_bindings')->where('client_id', $id)->delete();
        });

        return $this->respond('Frontend dicabut');
    }

    public function regenerateSecret(string $id): JsonResponse
    {
        abort_unless(
            DB::table('oauth_client_bindings')->where('client_id', $id)->exists(),
            404,
            'Frontend tidak ditemukan'
        );

        $client = Client::findOrFail($id);
        $plainSecret = $this->assignNewSecret($client);

        $binding = DB::table('oauth_client_bindings')->where('client_id', $id)->first();
        // $appOrigin = $binding->platform === 'mobile' ? $binding->allowed_origin : null;
        $appOrigin = in_array($binding->platform, ['android', 'ios'], true)
            ? $binding->allowed_origin
            : null;

        return $this->credentials(
            $client,
            $plainSecret,
            'Secret baru dibuat. Secret lama tidak berlaku lagi.',
            200,
            $appOrigin
        );
    }

    /**
     * Buat secret sendiri lalu simpan lewat model, jadi hasilnya mengikuti
     * cara Passport menyimpan secret (hash) tanpa bergantung pada $client->plainSecret.
     */
    private function assignNewSecret(Client $client): string
    {
        $plain = Str::random(40);
        $client->forceFill(['secret' => $plain])->save();

        return $plain;
    }

    private function credentials(
        Client $client,
        string $plainSecret,
        string $message,
        int $code = 200,
        ?string $appOrigin = null
    ): JsonResponse {
        // $data = [
        //     'AUROPAY_URL' => config('app.url'),
        //     'AUROPAY_CLIENT_ID' => $client->id,
        //     'AUROPAY_CLIENT_SECRET' => $plainSecret,
        // ];
        $data = [
            'AUROAPP_URL' => config('app.url'),
            'AUROAPP_CLIENT_ID' => $client->id,
            'AUROAPP_CLIENT_SECRET' => $plainSecret,
        ];

        // Khusus android / ios: nilai yang harus dipasang di .env Flutter.
        if ($appOrigin !== null) {
            // $data['AUROPAY_APP_ORIGIN'] = $appOrigin;
            $data['AUROAPP_APP_ORIGIN'] = $appOrigin;
        }

        return $this->respond($message, $data, $code)->header('Cache-Control', 'no-store');
    }

    private function respond(string $message, mixed $data = null, int $code = 200): JsonResponse
    {
        return response()->json([
            'meta' => ['code' => $code, 'status' => true, 'message' => $message],
            'data' => $data,
        ], $code);
    }
}