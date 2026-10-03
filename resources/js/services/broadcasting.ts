import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { bootstrap } from '../theme/bootstrap'

/**
 * The broadcasting connection, when one is configured.
 *
 * Reverb is optional. A panel is perfectly usable without realtime updates, and
 * an operator who has not set up a websocket server should not be shown a
 * broken page, so everything here degrades to "unavailable" rather than
 * throwing, and callers fall back to polling.
 */

type EchoInstance = Echo<'reverb'>

let echo: EchoInstance | null = null
let initialised = false

function connection(): EchoInstance | null {
    if (initialised) {
        return echo
    }

    initialised = true

    /*
     * Null when the server did not configure broadcasting, which is a
     * supported state: the caller falls back to polling rather than
     * attempting a connection that cannot succeed.
     */
    const settings = bootstrap().broadcasting

    if (settings === null) {
        return null
    }

    try {
        // Echo looks for Pusher on the window object.
        ;(window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher

        echo = new Echo({
            broadcaster: 'reverb',
            key: settings.key,
            wsHost: settings.host || window.location.hostname,
            wsPort: settings.port,
            wssPort: settings.port,
            forceTLS: settings.scheme === 'https',
            enabledTransports: ['ws', 'wss'],
        })
    } catch {
        echo = null
    }

    return echo
}

interface SubscribeOptions {
    /** Called when no connection could be established, or it dropped. */
    onUnavailable?: () => void
    /** Called once the socket is connected. */
    onConnected?: () => void
}

/**
 * Listen to an event on a public channel.
 *
 * Returns a function that tears the subscription down, or null when
 * broadcasting is not available so the caller can fall back.
 *
 * Only public channels are exposed by this helper. Anything carrying account or
 * character detail must use a private or presence channel, which is authorised
 * server-side; a public channel is readable by anyone who knows its name.
 */
export function subscribeToPublicChannel<T>(
    channel: string,
    event: string,
    handler: (payload: T) => void,
    options: SubscribeOptions = {},
): (() => void) | null {
    const instance = connection()

    if (instance === null) {
        options.onUnavailable?.()

        return null
    }

    try {
        instance.channel(channel).listen(event, handler)

        const socket = instance.connector.pusher

        socket.connection.bind('connected', () => options.onConnected?.())
        socket.connection.bind('unavailable', () => options.onUnavailable?.())
        socket.connection.bind('failed', () => options.onUnavailable?.())
        socket.connection.bind('disconnected', () => options.onUnavailable?.())

        return () => {
            instance.leaveChannel(`${channel}`)
        }
    } catch {
        options.onUnavailable?.()

        return null
    }
}
