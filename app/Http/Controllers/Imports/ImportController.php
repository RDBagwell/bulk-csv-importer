<?php

namespace App\Http\Controllers\Imports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Imports\StoreImportRequest;
use App\Http\Resources\ImportErrorResource;
use App\Http\Resources\ImportResource;
use App\Importing\Definitions\Column;
use App\Importing\Definitions\DefinitionRegistry;
use App\Importing\ImportPipeline;
use App\Models\Import;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ImportController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Import::class);

        $imports = $request->user()->imports()
            ->with('user:id,name')
            ->latest('id')
            ->paginate(20);

        return Inertia::render('imports/index', [
            'imports' => ImportResource::collection($imports),
        ]);
    }

    public function create(DefinitionRegistry $definitions): Response
    {
        Gate::authorize('create', Import::class);

        $definition = $definitions->default();

        return Inertia::render('imports/create', [
            'maxUploadBytes' => (int) config('importer.max_upload_kb') * 1024,
            'definition' => [
                'label' => $definition->label(),
                'columns' => array_map(fn (Column $column) => $column->header, $definition->columns()),
            ],
        ]);
    }

    public function store(StoreImportRequest $request, ImportPipeline $pipeline): RedirectResponse
    {
        $disk = Storage::disk(config('importer.disk'));
        $storedName = Str::uuid()->toString().'.csv';

        // A rename where possible: no second copy of a large upload.
        $request->file('file')->move($disk->path(''), $storedName);

        $import = $pipeline->create(
            $request->user(),
            $request->definition(),
            $request->headerMapping(),
            $storedName,
            $request->displayFilename(),
        );

        $pipeline->dispatch($import);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Upload received. The import has started.']);

        return to_route('imports.show', $import);
    }

    public function show(Import $import): Response
    {
        Gate::authorize('view', $import);

        $import->load('user:id,name');

        return Inertia::render('imports/show', [
            'import' => (new ImportResource($import))->resolve(),
            'latestErrors' => ImportErrorResource::collection(
                $import->errors()->latest('id')->limit(ImportStatusController::ERROR_TAIL)->get(),
            )->resolve(),
        ]);
    }
}
