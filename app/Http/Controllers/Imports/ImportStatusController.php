<?php

namespace App\Http\Controllers\Imports;

use App\Http\Controllers\Controller;
use App\Http\Resources\ImportErrorResource;
use App\Http\Resources\ImportResource;
use App\Models\Import;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Lightweight endpoint polled by the import detail page: one read of the
 * import, one aggregate over its chunks and the latest few errors.
 */
class ImportStatusController extends Controller
{
    public const int ERROR_TAIL = 10;

    public function __invoke(Import $import): JsonResponse
    {
        Gate::authorize('view', $import);

        return response()->json([
            'import' => new ImportResource($import),
            'latest_errors' => ImportErrorResource::collection(
                $import->errors()->latest('id')->limit(self::ERROR_TAIL)->get(),
            ),
        ])->header('Cache-Control', 'no-store');
    }
}
