import { createApp } from 'vue';
import { createPinia } from 'pinia';

import App from './App.vue';
import router from './router';
import keyboard from './directives/keyboard';
import './composables/useLayout';
import { startKioskCursor } from './composables/useKioskCursor';
import './services/echo';

startKioskCursor();

createApp(App).use(createPinia()).use(router).directive('keyboard', keyboard).mount('#app');
