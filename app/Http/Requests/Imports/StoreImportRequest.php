<?php

namespace App\Http\Requests\Imports;

use App\Importing\Csv\HeaderMapping;
use App\Importing\Csv\HeaderValidator;
use App\Importing\Definitions\DefinitionRegistry;
use App\Importing\Definitions\ImportDefinition;
use App\Models\Import;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;
use LogicException;

class StoreImportRequest extends FormRequest
{
    private ?HeaderMapping $mapping = null;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Import::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                // Client-side name and sniffed content must both look like CSV.
                'extensions:csv,txt',
                'mimetypes:text/plain,text/csv,application/csv',
                'max:'.config('importer.max_upload_kb'),
            ],
        ];
    }

    /**
     * Validates the header row before anything is stored or queued.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var UploadedFile $file */
                $file = $this->file('file');
                $this->mapping = app(HeaderValidator::class)->validate($file->getRealPath(), $this->definition());

                if (! $this->mapping->isValid()) {
                    $validator->errors()->add('file', 'The header row is not valid. '.implode(' ', $this->mapping->problems()));
                }
            },
        ];
    }

    public function definition(): ImportDefinition
    {
        return app(DefinitionRegistry::class)->default();
    }

    public function headerMapping(): HeaderMapping
    {
        return $this->mapping ?? throw new LogicException('The request has not been validated.');
    }

    /**
     * The client's file name, reduced to something safe to store and display.
     */
    public function displayFilename(): string
    {
        /** @var UploadedFile $file */
        $file = $this->file('file');
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', basename($file->getClientOriginalName())) ?? '';

        return mb_strimwidth($name !== '' ? $name : 'upload.csv', 0, 200, '…');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.extensions' => 'The file must be a .csv file.',
            'file.mimetypes' => 'The file does not look like CSV text.',
            'file.max' => 'The file may not be larger than :max kilobytes.',
            'file.uploaded' => 'The file failed to upload. It may be larger than the server allows.',
        ];
    }
}
