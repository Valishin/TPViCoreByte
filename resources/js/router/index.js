import { createRouter, createWebHistory } from 'vue-router';
import { useSessionStore } from '@/stores/session';

const routes = [
    { path: '/', redirect: '/mesas' },
    { path: '/entrar', name: 'login', component: () => import('@/views/LoginView.vue'), meta: { public: true } },
    { path: '/mesas', name: 'floor', component: () => import('@/views/FloorView.vue') },
    { path: '/mesas/pedido/:orderId(\\d+)', name: 'order', component: () => import('@/views/OrderView.vue') },
    { path: '/estado', name: 'estado', component: () => import('@/views/EstadoView.vue'), meta: { public: true } },
    { path: '/:pathMatch(.*)*', redirect: '/mesas' },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

// Sin sesión del TPV → pantalla de PIN.
router.beforeEach(async (to) => {
    const session = useSessionStore();
    if (!session.checked) await session.check();
    if (to.meta.public) return true;
    if (!session.isLoggedIn) return { name: 'login', query: to.fullPath !== '/mesas' ? { redirect: to.fullPath } : {} };
    return true;
});

export default router;
