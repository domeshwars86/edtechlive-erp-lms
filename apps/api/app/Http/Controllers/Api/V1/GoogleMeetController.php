<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Models\GoogleMeetConnection;
use App\Models\OrganizationMembership;
use App\Models\Teacher;
use App\Services\OrganizationAccess;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GoogleMeetController
{
    private const MEET_SCOPE = 'https://www.googleapis.com/auth/meetings.space.created';

    public function __construct(private readonly OrganizationAccess $access) {}

    public function status(Request $request, int $organization): JsonResponse
    {
        $this->authorizeOrganizer($request, $organization);
        $connection = $this->connection($request, $organization);

        return response()->json([
            'configured' => $this->configured(),
            'connected' => $connection !== null,
            'google_email' => $connection?->google_email,
            'connected_at' => $connection?->connected_at?->toISOString(),
        ]);
    }

    public function connect(Request $request, int $organization): JsonResponse
    {
        $this->authorizeOrganizer($request, $organization);
        abort_unless($this->configured(), 503, 'Google Meet connection is not configured by the application operator. You can still paste a meeting link.');

        $state = Str::random(64);
        DB::table('google_meet_oauth_states')->where('expires_at', '<=', now())->delete();
        DB::table('google_meet_oauth_states')->insert([
            'organization_id' => $organization,
            'user_id' => $request->user()->id,
            'state_hash' => hash('sha256', $state),
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $query = http_build_query([
            'client_id' => config('services.google_meet.client_id'),
            'redirect_uri' => config('services.google_meet.redirect_uri'),
            'response_type' => 'code',
            'scope' => 'openid email '.self::MEET_SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);

        return response()->json(['authorization_url' => 'https://accounts.google.com/o/oauth2/v2/auth?'.$query]);
    }

    public function callback(Request $request): RedirectResponse
    {
        $state = $request->query('state');
        if (! is_string($state) || strlen($state) !== 64) {
            return $this->frontendResult('error');
        }

        $pending = DB::transaction(function () use ($state): ?object {
            $record = DB::table('google_meet_oauth_states')->where('state_hash', hash('sha256', $state))
                ->where('expires_at', '>', now())->lockForUpdate()->first();
            if ($record !== null) {
                DB::table('google_meet_oauth_states')->where('id', $record->id)->delete();
            }

            return $record;
        });
        if ($pending === null) {
            return $this->frontendResult('error');
        }

        $code = $request->query('code');
        if ($request->filled('error') || ! is_string($code) || strlen($code) > 2048 || ! $this->configured()) {
            return $this->frontendResult('error');
        }

        $membership = OrganizationMembership::query()->with('organization')
            ->where('organization_id', $pending->organization_id)
            ->where('user_id', $pending->user_id)
            ->where('status', 'active')->first();
        if (! $membership || $membership->organization?->status !== 'active') {
            return $this->frontendResult('error');
        }

        try {
            $tokenResponse = Http::asForm()->acceptJson()->timeout(12)->post('https://oauth2.googleapis.com/token', [
                'code' => $code,
                'client_id' => config('services.google_meet.client_id'),
                'client_secret' => config('services.google_meet.client_secret'),
                'redirect_uri' => config('services.google_meet.redirect_uri'),
                'grant_type' => 'authorization_code',
            ]);
        } catch (ConnectionException) {
            return $this->frontendResult('error');
        }

        if (! $tokenResponse->successful() || ! is_string($tokenResponse->json('access_token'))) {
            return $this->frontendResult('error');
        }

        $tokens = $tokenResponse->json();
        $connection = GoogleMeetConnection::query()->firstOrNew([
            'organization_id' => $pending->organization_id,
            'user_id' => $pending->user_id,
        ]);
        $connection->access_token = $tokens['access_token'];
        $connection->refresh_token = $tokens['refresh_token'] ?? $connection->refresh_token;
        $connection->token_expires_at = now()->addSeconds(max((int) ($tokens['expires_in'] ?? 3600), 60));
        $connection->connected_at = now();

        try {
            $emailResponse = Http::withToken($tokens['access_token'])->acceptJson()->timeout(8)
                ->get('https://openidconnect.googleapis.com/v1/userinfo');
            $connection->google_email = $emailResponse->successful() ? $emailResponse->json('email') : $connection->google_email;
        } catch (ConnectionException) {
            // Room creation remains available even if the optional organizer email lookup is unavailable.
        }
        $connection->save();

        $workspaceRole = $membership->role === OrganizationRole::IndependentEducator
            ? 'educator'
            : $membership->role->value;

        return $this->frontendResult('connected', $workspaceRole);
    }

    public function createSpace(Request $request, int $organization): JsonResponse
    {
        $this->authorizeOrganizer($request, $organization);
        $connection = $this->connection($request, $organization);
        abort_unless($connection !== null, 409, 'Connect a Google account before creating a Meet link.');

        $accessToken = $this->accessToken($connection);
        try {
            $response = Http::withToken($accessToken)->acceptJson()->asJson()->timeout(12)
                ->post('https://meet.googleapis.com/v2/spaces', new \stdClass);

            if ($response->status() === 401 && $connection->refresh_token) {
                $accessToken = $this->refreshAccessToken($connection);
                $response = Http::withToken($accessToken)->acceptJson()->asJson()->timeout(12)
                    ->post('https://meet.googleapis.com/v2/spaces', new \stdClass);
            }
        } catch (ConnectionException) {
            abort(502, 'Google Meet could not be reached. Try again or use a meeting link created manually.');
        }

        if (! $response->successful() || ! is_string($response->json('meetingUri'))) {
            abort(502, 'Google Meet could not create a room. Reconnect the account or use a meeting link created manually.');
        }

        return response()->json(['meeting_url' => $response->json('meetingUri')], 201);
    }

    public function disconnect(Request $request, int $organization): JsonResponse
    {
        $this->authorizeOrganizer($request, $organization);
        $connection = $this->connection($request, $organization);
        if ($connection !== null) {
            $token = $connection->refresh_token ?: $connection->access_token;
            try {
                Http::asForm()->timeout(8)->post('https://oauth2.googleapis.com/revoke', ['token' => $token]);
            } catch (ConnectionException) {
                // Local credentials are still removed even when Google's revocation endpoint is unavailable.
            }
            $connection->delete();
        }

        return response()->json(['connected' => false]);
    }

    private function accessToken(GoogleMeetConnection $connection): string
    {
        if ($connection->token_expires_at?->isAfter(now()->addSeconds(60))) {
            return $connection->access_token;
        }

        abort_unless($connection->refresh_token, 409, 'Reconnect Google Meet to renew this account connection.');

        return $this->refreshAccessToken($connection);
    }

    private function refreshAccessToken(GoogleMeetConnection $connection): string
    {
        try {
            $response = Http::asForm()->acceptJson()->timeout(12)->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('services.google_meet.client_id'),
                'client_secret' => config('services.google_meet.client_secret'),
                'refresh_token' => $connection->refresh_token,
                'grant_type' => 'refresh_token',
            ]);
        } catch (ConnectionException) {
            abort(409, 'Google Meet could not be reached. Try again or reconnect this account.');
        }

        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            abort(409, 'Reconnect Google Meet to renew this account connection.');
        }

        $connection->access_token = $response->json('access_token');
        $connection->token_expires_at = now()->addSeconds(max((int) $response->json('expires_in', 3600), 60));
        $connection->save();

        return $connection->access_token;
    }

    private function connection(Request $request, int $organization): ?GoogleMeetConnection
    {
        return GoogleMeetConnection::query()->where('organization_id', $organization)
            ->where('user_id', $request->user()->id)->first();
    }

    private function authorizeOrganizer(Request $request, int $organization): void
    {
        $membership = $this->access->membership($request, $organization);
        abort_unless(in_array($membership->role, [
            OrganizationRole::Admin,
            OrganizationRole::Management,
            OrganizationRole::IndependentEducator,
            OrganizationRole::Teacher,
        ], true), 403);
        if ($membership->role === OrganizationRole::Teacher) {
            Teacher::query()->where('organization_id', $organization)
                ->where('user_id', $request->user()->id)->where('status', 'active')->firstOrFail();
        }
    }

    private function configured(): bool
    {
        return filled(config('services.google_meet.client_id'))
            && filled(config('services.google_meet.client_secret'))
            && filled(config('services.google_meet.redirect_uri'));
    }

    private function frontendResult(string $result, ?string $workspaceRole = null): RedirectResponse
    {
        $path = $workspaceRole ? '/workspace/'.$workspaceRole : '/';

        return redirect()->away(rtrim((string) config('services.google_meet.frontend_url'), '/').$path.'?google_meet='.$result);
    }
}
