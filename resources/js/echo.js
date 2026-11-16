import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

/**
 * Connection settings come from the build (VITE_REVERB_*) when present, and
 * otherwise from the page itself: the key from a <meta> tag and the host,
 * port and scheme from the current URL (the Docker image proxies /app to Reverb).
 */
const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.content;
const secure = (import.meta.env.VITE_REVERB_SCHEME ?? window.location.protocol.replace(':', '')) === 'https';
const port = import.meta.env.VITE_REVERB_PORT ?? (window.location.port || (secure ? 443 : 80));

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY ?? meta('reverb-key'),
    wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
    wsPort: port,
    wssPort: port,
    forceTLS: secure,
    enabledTransports: ['ws', 'wss'],
});
