import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import { router } from './router'
import '../css/app.css'

/*
 * Broadcasting is set up lazily in services/broadcasting.ts rather than here,
 * because it is optional: a panel without Reverb configured must still work,
 * falling back to polling. Connecting at boot would make an unconfigured or
 * unreachable websocket server a startup failure.
 */
createApp(App).use(createPinia()).use(router).mount('#app')
