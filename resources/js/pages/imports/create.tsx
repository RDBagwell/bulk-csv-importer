import { Head, Link, useForm } from '@inertiajs/react';
import { FileText, UploadCloud } from 'lucide-react';
import type { DragEvent } from 'react';
import { useRef, useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { formatBytes } from '@/lib/format';
import { validateUpload } from '@/lib/upload-validation';
import { cn } from '@/lib/utils';
import { create, index, store } from '@/routes/imports';

type Props = {
    maxUploadBytes: number;
    definition: { label: string; columns: string[] };
};

export default function CreateImport({ maxUploadBytes, definition }: Props) {
    const form = useForm<{ file: File | null }>({ file: null });
    const [clientError, setClientError] = useState<string | null>(null);
    const [dragging, setDragging] = useState(false);
    const input = useRef<HTMLInputElement>(null);

    function choose(file: File | null | undefined) {
        const error = file ? validateUpload(file, maxUploadBytes) : null;
        setClientError(error);
        form.clearErrors();
        form.setData('file', error ? null : (file ?? null));
    }

    function onDrop(event: DragEvent<HTMLLabelElement>) {
        event.preventDefault();
        setDragging(false);
        choose(event.dataTransfer.files[0]);
    }

    function submit() {
        const error = validateUpload(form.data.file, maxUploadBytes);

        if (error) {
            setClientError(error);

            return;
        }

        form.post(store().url, { forceFormData: true });
    }

    const file = form.data.file;
    const percent = form.progress?.percentage ?? 0;

    return (
        <>
            <Head title="New import" />
            <div className="mx-auto flex w-full max-w-2xl flex-1 flex-col gap-6 p-4 md:p-6">
                <Heading
                    title="New import"
                    description={`${definition.label} CSV, up to ${formatBytes(maxUploadBytes)}.`}
                />

                <label
                    htmlFor="file"
                    onDragOver={(event) => {
                        event.preventDefault();
                        setDragging(true);
                    }}
                    onDragLeave={() => setDragging(false)}
                    onDrop={onDrop}
                    className={cn(
                        'flex cursor-pointer flex-col items-center justify-center gap-3 rounded-xl border-2 border-dashed p-10 text-center transition-colors',
                        dragging
                            ? 'border-primary bg-primary/5'
                            : 'hover:bg-muted/40',
                        form.processing && 'pointer-events-none opacity-60',
                    )}
                >
                    {file ? (
                        <>
                            <FileText className="size-8 text-muted-foreground" />
                            <span className="font-medium break-all">
                                {file.name}
                            </span>
                            <span className="text-sm text-muted-foreground">
                                {formatBytes(file.size)}
                            </span>
                        </>
                    ) : (
                        <>
                            <UploadCloud className="size-8 text-muted-foreground" />
                            <span className="font-medium">
                                Drop a CSV file here, or click to choose one
                            </span>
                            <span className="text-sm text-muted-foreground">
                                Maximum {formatBytes(maxUploadBytes)}
                            </span>
                        </>
                    )}
                    <input
                        ref={input}
                        id="file"
                        name="file"
                        type="file"
                        accept=".csv,text/csv"
                        className="sr-only"
                        onChange={(event) => choose(event.target.files?.[0])}
                    />
                </label>

                <InputError message={clientError ?? form.errors.file} />

                {form.processing && (
                    <div className="space-y-1">
                        <div
                            role="progressbar"
                            aria-label="Upload progress"
                            aria-valuemin={0}
                            aria-valuemax={100}
                            aria-valuenow={percent}
                            className="h-2 overflow-hidden rounded-full bg-muted"
                        >
                            <div
                                className="h-full bg-primary transition-[width]"
                                style={{ width: `${percent}%` }}
                            />
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {percent < 100
                                ? `Uploading… ${percent}%`
                                : 'Checking the header row…'}
                        </p>
                    </div>
                )}

                <div className="flex gap-3">
                    <Button
                        onClick={submit}
                        disabled={!file || form.processing}
                    >
                        Upload and import
                    </Button>
                    <Button variant="ghost" asChild>
                        <Link href={index()}>Cancel</Link>
                    </Button>
                </div>

                <div className="rounded-xl border p-4 text-sm">
                    <p className="mb-2 font-medium">Expected columns</p>
                    <p className="text-muted-foreground">
                        The first row must be a header with these columns, in
                        any order:
                    </p>
                    <div className="mt-2 flex flex-wrap gap-2">
                        {definition.columns.map((column) => (
                            <code
                                key={column}
                                className="rounded bg-muted px-1.5 py-0.5 text-xs"
                            >
                                {column}
                            </code>
                        ))}
                    </div>
                </div>
            </div>
        </>
    );
}

CreateImport.layout = {
    breadcrumbs: [
        { title: 'Imports', href: index() },
        { title: 'New import', href: create() },
    ],
};
