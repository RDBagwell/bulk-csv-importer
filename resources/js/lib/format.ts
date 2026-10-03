const numberFormat = new Intl.NumberFormat('en-US');

export function formatNumber(value: number): string {
    return numberFormat.format(Math.round(value));
}

export function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    const units = ['KB', 'MB', 'GB', 'TB'];
    let value = bytes / 1024;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${value.toFixed(value < 10 ? 1 : 0)} ${units[unit]}`;
}

/** "45s", "3m 05s", "2h 01m". */
export function formatDuration(seconds: number): string {
    const total = Math.max(0, Math.round(seconds));

    if (total < 60) {
        return `${total}s`;
    }

    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const secs = total % 60;

    return hours > 0
        ? `${hours}h ${String(minutes).padStart(2, '0')}m`
        : `${minutes}m ${String(secs).padStart(2, '0')}s`;
}
