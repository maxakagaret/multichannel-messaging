<template>
  <q-layout view="hHh lpR fFf">
    <q-header elevated class="bg-primary text-white">
      <q-toolbar>
        <q-btn flat dense round icon="menu" aria-label="Menu" @click="drawer = !drawer" />
        <q-toolbar-title>Multichannel Messaging</q-toolbar-title>
        <q-badge color="amber-8" text-color="black" label="fake providers" />
      </q-toolbar>
    </q-header>

    <q-drawer v-model="drawer" show-if-above bordered :width="220">
      <q-list padding>
        <q-item v-for="item in nav" :key="item.to" :to="item.to" exact clickable v-ripple>
          <q-item-section avatar><q-icon :name="item.icon" /></q-item-section>
          <q-item-section>{{ item.label }}</q-item-section>
        </q-item>
      </q-list>
    </q-drawer>

    <q-page-container>
      <router-view />
    </q-page-container>
  </q-layout>
</template>

<script setup lang="ts">
import { ref } from 'vue'

const drawer = ref(true)
const nav = [
  { to: '/', label: 'Dashboard', icon: 'insights' },
  { to: '/campaigns', label: 'Campaigns', icon: 'campaign' },
  { to: '/senders', label: 'Sender pool', icon: 'dns' },
]
</script>
