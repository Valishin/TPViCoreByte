import { createRouter, createWebHistory } from 'vue-router';
import { useSessionStore } from '@/stores/session';

const routes = [
    { path: '/', name: 'home', component: { render: () => null } }, // → session.home
    { path: '/entrar', name: 'login', component: () => import('@/views/LoginView.vue'), meta: { public: true } },
    { path: '/mesas', name: 'floor', component: () => import('@/views/FloorView.vue') },
    { path: '/mesas/pedido/:orderId(\\d+)', name: 'order', component: () => import('@/views/OrderView.vue') },
    { path: '/mesas/pedido/:orderId(\\d+)/cobrar', name: 'checkout', component: () => import('@/views/CheckoutView.vue') },
    { path: '/caja', name: 'cash', component: () => import('@/views/CashView.vue') },
    { path: '/pedidos', name: 'takeaway', component: () => import('@/views/TakeawayBoardView.vue') },
    { path: '/pedidos/nuevo', name: 'takeaway-new', component: () => import('@/views/NewTakeawayView.vue') },
    { path: '/pedidos/:orderId(\\d+)', name: 'takeaway-order', component: () => import('@/views/OrderView.vue') },
    { path: '/pedidos/:orderId(\\d+)/cobrar', name: 'takeaway-checkout', component: () => import('@/views/CheckoutView.vue') },
    { path: '/repartos', name: 'my-deliveries', component: () => import('@/views/MyDeliveriesView.vue'), meta: { courier: true } },
    { path: '/repartidores', name: 'couriers', component: () => import('@/views/CouriersView.vue') },
    { path: '/estado', name: 'estado', component: () => import('@/views/EstadoView.vue'), meta: { public: true } },
    { path: '/:pathMatch(.*)*', redirect: '/' },
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
    if (!session.isLoggedIn) return { name: 'login', query: to.name !== 'home' ? { redirect: to.fullPath } : {} };
    if (to.name === 'home') return session.home;
    // El repartidor solo ve sus repartos; el resto del personal, todo menos eso.
    if (session.isCourier !== !!to.meta.courier) return session.home;
    return true;
});

export default router;
