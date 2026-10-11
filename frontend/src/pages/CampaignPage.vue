<template>
  <q-page padding>
    <template v-if="store.current && store.stats">
      <div class="row items-center q-mb-sm">
        <q-btn flat round icon="arrow_back" to="/campaigns" />
        <div class="text-h5 q-ml-sm">{{ store.current.name }}</div>
        <q-badge class="q-ml-md" :label="store.current.status" />
        <q-space />
        <div class="text-caption text-grey-7">auto-refresh every 5s</div>
      </div>

      <div class="row q-col-gutter-md q-mb-md">
        <div class="col-12 col-md-4">
          <q-card flat bordered><q-card-section>
            <div class="text-caption text-grey-7">Recipients dispatched</div>
            <div class="text-h5">{{ store.stats.processed_recipients }} / {{ store.stats.planned_recipients }}</div>
            <q-linear-progress
              class="q-mt-sm"
              :value="store.stats.planned_recipients ? store.stats.processed_recipients / store.stats.planned_recipients : 0"
            />
            <div class="text-caption text-grey-7 q-mt-xs">daily cap: {{ store.current.daily_cap }}</div>
          </q-card-section></q-card>
        </div>
        <div class="col-12 col-md-4">
          <q-card flat bordered><q-card-section>
            <div class="text-caption text-grey-7">Fallback messages</div>
            <div class="text-h5">{{ store.stats.fallbacks }}</div>
          </q-card-section></q-card>
        </div>
        <div class="col-12 col-md-4">
          <q-card flat bordered><q-card-section>
            <div class="text-caption text-grey-7">Template</div>
            <div class="ellipsis-2-lines">{{ store.current.body }}</div>
          </q-card-section></q-card>
        </div>
      </div>

      <ChannelFunnel :channels="store.stats.channels" />

      <div class="row items-center q-mt-lg q-mb-sm q-gutter-sm">
        <div class="text-subtitle1">Messages</div>
        <q-space />
        <q-select v-model="filters.channel" :options="channelOptions" dense outlined clearable emit-value map-options label="Channel" style="min-width: 140px" />
        <q-select v-model="filters.status" :options="statusOptions" dense outlined clearable label="Status" style="min-width: 140px" />
      </div>

      <q-table
        flat
        bordered
        :rows="store.messages"
        :columns="columns"
        row-key="id"
        v-model:pagination="pagination"
        :rows-number="store.messagesTotal"
        @request="onRequest"
        @row-click="(_, row) => openMessage(row.id)"
      >
        <template #body-cell-channel="{ row }">
          <q-td><q-icon :name="channelIcon[row.channel as Channel]" /> {{ row.channel }}</q-td>
        </template>
        <template #body-cell-status="{ row }">
          <q-td><StatusChip :status="row.status" /></q-td>
        </template>
      </q-table>
    </template>
    <q-inner-loading :showing="!store.current" />

    <MessageDialog v-model="dialog" :message-id="selected" />
  </q-page>
</template>

<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import type { QTableColumn, QTableProps } from 'quasar'
import type { Channel, Message, MessageStatus } from '../api/types'
import ChannelFunnel from '../components/ChannelFunnel.vue'
import MessageDialog from '../components/MessageDialog.vue'
import StatusChip from '../components/StatusChip.vue'
import { usePolling } from '../composables/usePolling'
import { channelIcon, dateTime } from '../format'
import { useCampaignsStore } from '../stores/campaigns'

const props = defineProps<{ id: number }>()
const store = useCampaignsStore()

const filters = reactive<{ channel: Channel | null; status: MessageStatus | null }>({ channel: null, status: null })
const pagination = ref({ page: 1, rowsPerPage: 25, rowsNumber: 0 })
const dialog = ref(false)
const selected = ref<number | null>(null)

const channelOptions = ['whatsapp', 'telegram', 'sms', 'email'].map((c) => ({ label: c, value: c }))
const statusOptions: MessageStatus[] = ['pending', 'queued', 'sent', 'delivered', 'read', 'failed', 'skipped']

const columns: QTableColumn<Message>[] = [
  { name: 'id', label: '#', field: 'id', align: 'left' },
  { name: 'contact', label: 'Contact', field: (m) => m.contact?.name ?? '', align: 'left' },
  { name: 'channel', label: 'Channel', field: 'channel', align: 'left' },
  { name: 'status', label: 'Status', field: 'status', align: 'left' },
  { name: 'scheduled', label: 'Scheduled for', field: (m) => dateTime(m.scheduled_for), align: 'left' },
  { name: 'error', label: 'Error', field: (m) => m.error_code ?? '', align: 'left' },
]

async function loadMessages() {
  await store.fetchMessages(props.id, {
    page: pagination.value.page,
    per_page: pagination.value.rowsPerPage,
    channel: filters.channel ?? undefined,
    status: filters.status ?? undefined,
  })
  pagination.value.rowsNumber = store.messagesTotal
}

const onRequest: QTableProps['onRequest'] = ({ pagination: p }) => {
  pagination.value.page = p.page
  pagination.value.rowsPerPage = p.rowsPerPage
  void loadMessages()
}

function openMessage(id: number) {
  selected.value = id
  dialog.value = true
}

watch(filters, () => {
  pagination.value.page = 1
  void loadMessages()
})

usePolling(() => Promise.all([store.fetchDetail(props.id), loadMessages()]), 5000)
</script>
