import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

// Live updates need a Reverb server. Hosts that can't run one (e.g. Vercel)
// build without VITE_REVERB_APP_KEY; the app then simply polls for changes.
if (import.meta.env.VITE_REVERB_APP_KEY) {
    // Connect to Reverb on whatever host served the page, so players on other
    // devices (phones on the same Wi-Fi) reach it too.
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST && import.meta.env.VITE_REVERB_HOST !== '127.0.0.1'
            ? import.meta.env.VITE_REVERB_HOST
            : window.location.hostname,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}
