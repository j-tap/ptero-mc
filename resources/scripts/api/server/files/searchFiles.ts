import http from '@/api/http';

export interface FileSearchHit {
    path: string;
    snippet: string;
}

export interface FileSearchDebug {
    directory: string;
    paths_count: number;
    sample_paths?: string[];
    chunks_count: number;
    contents_fetched: number;
    matches_count: number;
    note?: string | null;
}

export interface FileSearchResponse {
    results: FileSearchHit[];
    debug?: FileSearchDebug;
}

export default (uuid: string, directory: string, q: string): Promise<FileSearchResponse> => {
    return http
        .get(`/api/client/servers/${uuid}/files/search`, {
            params: { directory: directory || '/', q: q.trim() },
        })
        .then(({ data }) => ({
            results: data.data ?? [],
            debug: data.meta?.debug,
        }));
};

export interface SearchStreamCallbacks {
    onResult: (hit: FileSearchHit) => void;
    onDone: (debug: FileSearchDebug | null) => void;
    onError: (err: unknown) => void;
}

export function searchFilesStream(
    uuid: string,
    directory: string,
    q: string,
    callbacks: SearchStreamCallbacks,
    signal?: AbortSignal
): void {
    const params = new URLSearchParams({
        stream: '1',
        directory: directory || '/',
        q: q.trim(),
    });
    const url = `/api/client/servers/${uuid}/files/search?${params.toString()}`;
    let buffer = '';
    let doneCalled = false;
    const onDoneOnce = (debug: FileSearchDebug | null) => {
        if (doneCalled) return;
        doneCalled = true;
        callbacks.onDone(debug);
    };

    fetch(url, { credentials: 'include', signal })
        .then((res) => {
            if (!res.ok) throw new Error(res.statusText);
            const reader = res.body?.getReader();
            if (!reader) throw new Error('No body');
            const decoder = new TextDecoder();
            return reader.read().then(function pump(chunk): Promise<void> {
                if (chunk.done) {
                    if (buffer.trim()) parseSSEBlock(buffer, callbacks, onDoneOnce);
                    if (!doneCalled) onDoneOnce(null);
                    return Promise.resolve();
                }
                buffer += decoder.decode(chunk.value, { stream: true });
                const parts = buffer.split('\n\n');
                buffer = parts.pop() ?? '';
                for (const block of parts) {
                    parseSSEBlock(block, callbacks, onDoneOnce);
                }
                return reader.read().then(pump);
            });
        })
        .catch((err) => {
            if (err?.name === 'AbortError') return;
            callbacks.onError(err);
        });
}

function parseSSEBlock(
    block: string,
    callbacks: SearchStreamCallbacks,
    onDoneOnce: (debug: FileSearchDebug | null) => void
): void {
    let event = '';
    let data = '';
    for (const line of block.split('\n')) {
        if (line.startsWith('event:')) event = line.slice(6).trim();
        else if (line.startsWith('data:')) data = line.slice(5).trim();
    }
    if (!data) return;
    try {
        const parsed = JSON.parse(data);
        if (event === 'result' && parsed.path != null) {
            callbacks.onResult({ path: parsed.path, snippet: parsed.snippet ?? '' });
        } else if (event === 'done') {
            onDoneOnce(parsed.debug ?? null);
        }
    } catch {
        // ignore parse errors
    }
}
