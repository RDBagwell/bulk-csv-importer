import { describe, expect, it } from 'vite-plus/test';
import { validateUpload } from '@/lib/upload-validation';

const MB = 1024 * 1024;

describe('validateUpload', () => {
    it('requires a file', () => {
        expect(validateUpload(null, MB)).toBe('Choose a CSV file to upload.');
    });

    it.each(['data.csv', 'DATA.CSV', 'export.txt'])('accepts %s', (name) => {
        expect(validateUpload({ name, size: 10 }, MB)).toBeNull();
    });

    it.each(['data.xlsx', 'data', 'archive.csv.zip'])('rejects %s', (name) => {
        expect(validateUpload({ name, size: 10 }, MB)).toBe(
            'Only .csv files can be imported.',
        );
    });

    it('rejects empty files', () => {
        expect(validateUpload({ name: 'a.csv', size: 0 }, MB)).toBe(
            'The file is empty.',
        );
    });

    it('rejects files over the limit with a readable message', () => {
        expect(
            validateUpload(
                { name: 'big.csv', size: 1.5 * 1024 * MB },
                1024 * MB,
            ),
        ).toBe('The file is 1.5 GB; the limit is 1.0 GB.');
    });

    it('accepts a file exactly at the limit', () => {
        expect(validateUpload({ name: 'a.csv', size: MB }, MB)).toBeNull();
    });
});
