import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

let echo;

export function getEcho() {
    if (echo) {
        return echo;
    }

    window.Pusher = Pusher;

    const scheme = import.meta.env.VITE_REVERB_SCHEME ?? (window.location.protocol === 'https:' ? 'https' : 'http');
    const port = Number(import.meta.env.VITE_REVERB_PORT ?? 8080);

    echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: window.location.hostname,
        wsPort: port,
        wssPort: port,
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
    });

    return echo;
}
