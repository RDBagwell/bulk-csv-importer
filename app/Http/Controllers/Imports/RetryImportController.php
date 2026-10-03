<?php

namespace App\Http\Controllers\Imports;

use App\Http\Controllers\Controller;
use App\Importing\ImportPipeline;
use App\Models\Import;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class RetryImportController extends Controller
{
    public function __invoke(Import $import, ImportPipeline $pipeline): RedirectResponse
    {
        Gate::authorize('retry', $import);

        if (! $pipeline->canRetry($import)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Only failed chunks of a failed import can be retried.']);

            return back();
        }

        $pipeline->retryFailedChunks($import);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Retrying the failed chunks.']);

        return back();
    }
}
