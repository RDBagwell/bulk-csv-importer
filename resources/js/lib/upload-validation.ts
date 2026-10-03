import { formatBytes } from '@/lib/format';

const ALLOWED_EXTENSIONS = ['csv', 'txt'];

type FileLike = Pick<File, 'name' | 'size'>;

/**
 * Client-side checks before an upload starts. The server repeats them (and
 * sniffs the content and header row); this only saves the user a long
 * upload that would be rejected anyway.
 */
export function validateUpload(
    file: FileLike | null | undefined,
    maxBytes: number,
): string | null {
    if (!file) {
        return 'Choose a CSV file to upload.';
    }

    const extension = file.name.includes('.')
        ? file.name.split('.').pop()!.toLowerCase()
        : '';

    if (!ALLOWED_EXTENSIONS.includes(extension)) {
        return 'Only .csv files can be imported.';
    }

    if (file.size === 0) {
        return 'The file is empty.';
    }

    if (file.size > maxBytes) {
        return `The file is ${formatBytes(file.size)}; the limit is ${formatBytes(maxBytes)}.`;
    }

    return null;
}
