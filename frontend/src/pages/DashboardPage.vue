<template>
  <q-page padding>
    <div class="row items-center q-mb-md">
      <div class="text-h5">Delivery overview</div>
      <q-space />
      <q-btn-toggle
        v-model="store.hours"
        dense
        unelevated
        toggle-color="primary"
        :options="[
          { label: '24h', value: 24 },
          { label: '7d', value: 168 },
          { label: '14d', value: 336 },
        ]"
        @update:model-value="store.fetchOverview()"
      />
    </div>

    <template v-if="store.overview">
      <div class="row q-col-gutter-md q-mb-md">
        <div class="col-6 col-md-3">
          <q-card flat bordered><q-card-section>
            <div class="text-caption text-grey-7">Contacts</div>
            <div class="text-h5">{{ store.overview.contacts }}</div>
          </q-card-section></q-card>
        </div>
        <div v-for="status in ['running', 'completed', 'draft']" :key="status" class="col-6 col-md-3">
          <q-card flat bordered><q-card-section>
            <div class="text-caption text-grey-7">Campaigns {{ status }}</div>
            <div class="text-h5">{{ store.overview.campaigns[status as CampaignStatus] ?? 0 }}</div>
          </q-card-section></q-card>
        </div>
      </div>

      <ChannelFunnel :channels="store.overview.channels" />

      <div class="row q-col-gutter-md q-mt-md">
        <div class="col-12 col-md-8">
          <q-card flat bordered>
            <q-card-section class="text-subtitle1">Messages sent per hour</q-card-section>
            <q-card-section>
              <div class="hourly">
                <div
                  v-for="point in store.overview.hourly"
                  :key="point.hour"
                  class="bar bg-primary"
                  :style="{ height: `${(point.sent / maxHourly) * 100}%` }"
                >
                  <q-tooltip>{{ dateTime(point.hour) }}: {{ point.sent }}</q-tooltip>
                </div>
              </div>
            </q-card-section>
          </q-card>
        </div>
        <div class="col-12 col-md-4">
          <q-card flat bordered>
            <q-card-section class="text-subtitle1">Top errors</q-card-section>
            <q-list dense separator>
              <q-item v-for="error in store.overview.top_errors" :key="error.error_code + error.channel">
                <q-item-section avatar><q-icon :name="channelIcon[error.channel]" /></q-item-section>
                <q-item-section>{{ error.error_code }}</q-item-section>
                <q-item-section side>{{ error.total }}</q-item-section>
              </q-item>
              <q-item v-if="!store.overview.top_errors.length"><q-item-section>None 🎉</q-item-section></q-item>
            </q-list>
          </q-card>
        </div>
      </div>
    </template>
    <q-inner-loading :showing="!store.overview" />
  </q-page>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import type { CampaignStatus } from '../api/types'
import ChannelFunnel from '../components/ChannelFunnel.vue'
import { usePolling } from '../composables/usePolling'
import { channelIcon, dateTime } from '../format'
import { useOverviewStore } from '../stores/overview'

const store = useOverviewStore()
usePolling(() => store.fetchOverview(), 5000)

const maxHourly = computed(() => Math.max(1, ...(store.overview?.hourly.map((p) => p.sent) ?? [1])))
</script>

<style scoped>
.hourly {
  display: flex;
  align-items: flex-end;
  gap: 2px;
  height: 160px;
}
.bar {
  flex: 1;
  min-height: 1px;
  border-radius: 2px 2px 0 0;
}
</style>
