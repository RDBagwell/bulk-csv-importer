<?php

namespace App\Http\Controllers\Imports;

use App\Http\Controllers\Controller;
use App\Importing\ImportPipeline;
use App\Models\Import;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class CancelImportController extends Controller
{
    public function __invoke(Import $import, ImportPipeline $pipeline): RedirectResponse
    {
        Gate::authorize('cancel', $import);

        $cancelled = $pipeline->cancel($import);

        Inertia::flash('toast', $cancelled
            ? ['type' => 'success', 'message' => 'Import cancelled. Rows already written are kept.']
            : ['type' => 'error', 'message' => 'This import has already finished.']);

        return back();
    }
}
