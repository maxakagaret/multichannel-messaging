<template>
  <div class="row q-col-gutter-md">
    <div v-for="row in channels" :key="row.channel" class="col-12 col-md-6 col-lg-3">
      <q-card flat bordered>
        <q-card-section class="row items-center no-wrap">
          <q-icon :name="channelIcon[row.channel]" size="sm" class="q-mr-sm" />
          <div class="text-subtitle1">{{ row.label }}</div>
          <q-space />
          <div class="text-caption text-grey-7">{{ row.total }} msgs</div>
        </q-card-section>

        <!-- Stacked status bar: share of each status in this channel -->
        <div class="funnel-bar q-mx-md">
          <div
            v-for="segment in segments(row)"
            :key="segment.status"
            :class="`bg-${statusColor[segment.status]}`"
            :style="{ width: `${segment.share}%` }"
          >
            <q-tooltip>{{ segment.status }}: {{ segment.count }}</q-tooltip>
          </div>
        </div>

        <q-card-section class="row text-center q-pt-sm">
          <div class="col">
            <div class="text-h6">{{ percent(row.delivery_rate) }}</div>
            <div class="text-caption text-grey-7">delivered</div>
          </div>
          <div class="col">
            <div class="text-h6">{{ percent(row.read_rate) }}</div>
            <div class="text-caption text-grey-7">read</div>
          </div>
          <div class="col">
            <div class="text-h6 text-red-7">{{ percent(row.failure_rate) }}</div>
            <div class="text-caption text-grey-7">failed</div>
          </div>
          <div class="col">
            <div class="text-h6">{{ seconds(row.avg_delivery_seconds) }}</div>
            <div class="text-caption text-grey-7">to deliver</div>
          </div>
        </q-card-section>
      </q-card>
    </div>
    <div v-if="!channels.length" class="col-12 text-grey-7">No messages yet.</div>
  </div>
</template>

<script setup lang="ts">
import type { ChannelStats, MessageStatus } from '../api/types'
import { channelIcon, percent, seconds, statusColor } from '../format'

defineProps<{ channels: ChannelStats[] }>()

const order: MessageStatus[] = ['read', 'delivered', 'sent', 'queued', 'pending', 'failed', 'skipped']

function segments(row: ChannelStats) {
  return order
    .map((status) => ({ status, count: row.by_status[status] ?? 0 }))
    .filter((s) => s.count > 0)
    .map((s) => ({ ...s, share: (s.count / Math.max(1, row.total)) * 100 }))
}
</script>

<style scoped>
.funnel-bar {
  display: flex;
  height: 10px;
  border-radius: 5px;
  overflow: hidden;
  background: #eee;
}
</style>
