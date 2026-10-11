import { createRouter, createWebHistory } from 'vue-router'

export const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/', name: 'dashboard', component: () => import('./pages/DashboardPage.vue') },
    { path: '/campaigns', name: 'campaigns', component: () => import('./pages/CampaignsPage.vue') },
    { path: '/campaigns/:id', name: 'campaign', component: () => import('./pages/CampaignPage.vue'), props: (r) => ({ id: Number(r.params.id) }) },
    { path: '/senders', name: 'senders', component: () => import('./pages/SendersPage.vue') },
  ],
})
