import { createRouter, createWebHistory } from 'vue-router';

const routes = [
    { path: '/', name: 'home', component: () => import('@/views/HomeView.vue') },
    { path: '/estado', name: 'estado', component: () => import('@/views/EstadoView.vue') },
    { path: '/:pathMatch(.*)*', redirect: '/' },
];

export default createRouter({
    history: createWebHistory(),
    routes,
});
