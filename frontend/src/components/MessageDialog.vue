<template>
  <q-dialog v-model="open">
    <q-card style="width: 560px; max-width: 95vw">
      <q-card-section v-if="message" class="row items-center">
        <q-icon :name="channelIcon[message.channel]" size="sm" class="q-mr-sm" />
        <div class="text-h6">Message #{{ message.id }}</div>
        <q-space />
        <StatusChip :status="message.status" />
      </q-card-section>

      <q-card-section v-if="message" class="q-pt-none">
        <div class="text-caption text-grey-7">
          {{ message.contact?.name }} · {{ message.recipient ?? 'no address' }} · sender: {{ message.sender ?? '—' }} ·
          attempts: {{ message.attempts }}
          <span v-if="message.fallback_of_id"> · fallback of #{{ message.fallback_of_id }}</span>
        </div>
        <q-banner v-if="message.error_code" dense class="bg-red-1 text-red-9 q-mt-sm">
          {{ message.error_code }} — {{ message.error_message }}
        </q-banner>
        <pre class="body q-mt-sm">{{ message.body }}</pre>

        <q-timeline color="primary" layout="dense" class="q-mt-md">
          <q-timeline-entry
            v-for="(event, i) in message.events"
            :key="i"
            :title="event.status"
            :subtitle="`${dateTime(event.occurred_at)} · ${event.source}`"
            :color="statusColor[event.status]"
          >
            <div v-if="event.payload" class="text-caption text-grey-8">{{ event.payload }}</div>
          </q-timeline-entry>
        </q-timeline>
      </q-card-section>

      <q-inner-loading :showing="!message" />
    </q-card>
  </q-dialog>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { api } from '../api/client'
import type { Message } from '../api/types'
import { channelIcon, dateTime, statusColor } from '../format'
import StatusChip from './StatusChip.vue'

const open = defineModel<boolean>({ required: true })
const props = defineProps<{ messageId: number | null }>()
const message = ref<Message | null>(null)

watch(
  () => [open.value, props.messageId] as const,
  async ([isOpen, id]) => {
    message.value = null
    if (isOpen && id) message.value = await api.message(id)
  },
)
</script>

<style scoped>
.body {
  white-space: pre-wrap;
  font-family: inherit;
  background: #f6f7f9;
  padding: 8px 12px;
  border-radius: 6px;
  margin: 0;
}
</style>
