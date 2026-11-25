<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\PlatformOperatorAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformSystemReadinessController
{
    public function __invoke(Request $request, PlatformOperatorAccess $platformAccess): JsonResponse
    {
        abort_unless($platformAccess->allows($request->user()), 403);

        $mailer = (string) config('mail.default', 'log');
        $mailIsLocal = in_array($mailer, ['log', 'array', ''], true);
        $mailHasTransport = $mailer === 'smtp'
            ? filled(config('mail.mailers.smtp.host')) && filled(config('mail.mailers.smtp.username'))
            : ! $mailIsLocal;

        $googleReady = filled(config('services.google_meet.client_id'))
            && filled(config('services.google_meet.client_secret'))
            && filled(config('services.google_meet.redirect_uri'));
        $privateDisk = config('filesystems.disks.private', []);
        $privateStorageReady = ($privateDisk['driver'] ?? 'local') === 's3'
            && filled($privateDisk['bucket'] ?? null)
            && filled($privateDisk['key'] ?? null)
            && filled($privateDisk['secret'] ?? null);
        $pushReady = (bool) config('services.expo_push.enabled')
            && filled(config('services.expo_push.project_id'));
        $aiReady = config('services.ai.driver') === 'ollama'
            && filled(config('services.ai.model'));

        return response()->json([
            'data' => [
                'services' => [
                    $this->service(
                        'email',
                        'Email delivery',
                        $mailIsLocal ? 'local_only' : ($mailHasTransport ? 'configured' : 'setup_required'),
                        $mailIsLocal
                            ? 'The current mail transport is for local or test use; messages will not reach an inbox.'
                            : ($mailHasTransport
                                ? 'A delivery transport is selected. Delivery has not been tested from this screen.'
                                : 'A delivery transport is selected, but its required connection settings are incomplete.'),
                    ),
                    $this->service(
                        'google_meet',
                        'Google Meet',
                        $googleReady ? 'configured' : 'optional',
                        $googleReady
                            ? 'OAuth settings are present. Live consent and Meet API access have not been verified.'
                            : 'Optional room creation is not configured. Staff can still use manual HTTPS meeting links.',
                    ),
                    $this->service(
                        'private_storage',
                        'Private file storage',
                        $privateStorageReady ? 'configured' : 'local_only',
                        $privateStorageReady
                            ? 'Private object storage settings are present. Bucket access and durability have not been tested.'
                            : 'Private files use local application storage; production needs a configured durable private disk.',
                    ),
                    $this->service(
                        'razorpay_test',
                        'Razorpay Test Mode',
                        (bool) config('services.razorpay.enabled') ? 'configured' : 'optional',
                        (bool) config('services.razorpay.enabled')
                            ? 'Test Mode checkout settings are present. No provider transaction is initiated here.'
                            : 'Test checkout is disabled or incomplete. Live charges are not supported.',
                    ),
                    $this->service(
                        'expo_push',
                        'Expo push notifications',
                        $pushReady ? 'configured' : 'optional',
                        $pushReady
                            ? 'Push delivery is enabled with a project ID. Provider delivery has not been tested from this screen.'
                            : 'Push delivery is optional and is not fully configured.',
                    ),
                    $this->service(
                        'ai',
                        'Local AI assistance',
                        $aiReady ? 'configured' : 'optional',
                        $aiReady
                            ? 'An Ollama model is selected. The local provider is not probed from this screen.'
                            : 'AI assistance is optional and is not enabled with an Ollama model.',
                    ),
                ],
                'security_note' => 'This view reports configuration signals only. It never returns credentials and does not change provider settings.',
            ],
        ]);
    }

    /** @return array{key: string, name: string, status: string, detail: string} */
    private function service(string $key, string $name, string $status, string $detail): array
    {
        return compact('key', 'name', 'status', 'detail');
    }
}
