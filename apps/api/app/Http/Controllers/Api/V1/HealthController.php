<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController
{
    public function ready(): JsonResponse
    {
        try {
            DB::selectOne('select 1 as ready');
        } catch (Throwable) {
            return response()->json([
                'status' => 'unavailable',
                'service' => 'edtechlive-api',
                'checks' => ['database' => 'unavailable'],
            ], 503);
        }

        return response()->json([
            'status' => 'ok',
            'service' => 'edtechlive-api',
            'checks' => ['database' => 'ok'],
        ]);
    }
}
