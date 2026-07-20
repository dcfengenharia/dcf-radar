import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: process.env.MIX_REVERB_APP_KEY,
    wsHost: process.env.MIX_REVERB_HOST,
    wsPort: process.env.MIX_REVERB_PORT || 8080,
    wssPort: process.env.MIX_REVERB_PORT || 8080,
    forceTLS: (process.env.MIX_REVERB_SCHEME || 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});
