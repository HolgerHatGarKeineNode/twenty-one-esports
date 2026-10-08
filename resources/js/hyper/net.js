/**
 * The game page's requests (plan "Hyperbitcoinization", P2): JSON both ways, the session's CSRF token, and an
 * answer that never throws: `{ ok, status, data }`, status 0 when the network failed.
 */
export function createNet(csrf) {
    async function request(method, url, body) {
        try {
            const response = await fetch(url, {
                method,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(body === undefined ? {} : { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf }),
                },
                body: body === undefined ? undefined : JSON.stringify(body),
            });
            let data = null;
            try {
                data = await response.json();
            } catch {
                data = null;
            }

            return { ok: response.ok, status: response.status, data };
        } catch {
            return { ok: false, status: 0, data: null };
        }
    }

    return {
        get: (url) => request('GET', url),
        post: (url, body = {}) => request('POST', url, body),
    };
}
