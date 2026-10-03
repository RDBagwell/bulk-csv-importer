import { useEffect, useRef, useState } from 'react';
import { status } from '@/routes/imports';
import type {
    ImportErrorRow,
    ImportStatusResponse,
    ImportSummary,
} from '@/types';

const ACTIVE_INTERVAL_MS = 2000;
const HIDDEN_INTERVAL_MS = 10000;

/**
 * Polls the lightweight status endpoint while an import is active and
 * stops as soon as it reaches a final status. Polls less often while the
 * tab is hidden.
 */
export function useImportStatus(
    initial: ImportSummary,
    initialErrors: ImportErrorRow[],
) {
    const [state, setState] = useState<ImportStatusResponse>({
        import: initial,
        latest_errors: initialErrors,
    });
    const [failedPolls, setFailedPolls] = useState(0);
    const active = state.import.is_active;
    const id = state.import.id;
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    // Server-driven changes (e.g. after cancel/retry reloads the page props).
    useEffect(() => {
        setState({ import: initial, latest_errors: initialErrors });
    }, [initial, initialErrors]);

    useEffect(() => {
        if (!active) {
            return;
        }

        const controller = new AbortController();

        const poll = async () => {
            try {
                const response = await fetch(status(id).url, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    signal: controller.signal,
                });

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                setState((await response.json()) as ImportStatusResponse);
                setFailedPolls(0);
            } catch {
                if (controller.signal.aborted) {
                    return;
                }

                setFailedPolls((count) => count + 1);
            }

            timer.current = setTimeout(
                poll,
                document.hidden ? HIDDEN_INTERVAL_MS : ACTIVE_INTERVAL_MS,
            );
        };

        timer.current = setTimeout(poll, ACTIVE_INTERVAL_MS);

        return () => {
            controller.abort();

            if (timer.current) {
                clearTimeout(timer.current);
            }
        };
    }, [active, id]);

    return { ...state, connectionLost: failedPolls >= 3 };
}
