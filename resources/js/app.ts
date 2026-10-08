import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import { router } from './router'
import { initLocale } from './i18n'
import { bootstrap } from './theme/bootstrap'
import '../css/app.css'

/*
 * Before the first render, so the opening paint is already in the visitor's
 * language rather than a frame of English corrected a tick later. The server
 * chose it; this only adopts it.
 */
initLocale(bootstrap().locale.active)

/*
 * Broadcasting is set up lazily in services/broadcasting.ts rather than here,
 * because it is optional: a panel without Reverb configured must still work,
 * falling back to polling. Connecting at boot would make an unconfigured or
 * unreachable websocket server a startup failure.
 */
createApp(App).use(createPinia()).use(router).mount('#app')
