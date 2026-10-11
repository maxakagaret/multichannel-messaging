<template>
  <q-page padding>
    <div class="row items-center q-mb-md">
      <div class="text-h5">Campaigns</div>
      <q-space />
      <q-btn color="primary" icon="add" label="New campaign" @click="creating = true" />
    </div>

    <q-table
      flat
      bordered
      :rows="store.list"
      :columns="columns"
      row-key="id"
      :loading="store.loading"
      :pagination="{ rowsPerPage: 20 }"
      hide-pagination
      @row-click="(_, row) => $router.push(`/campaigns/${row.id}`)"
    >
      <template #body-cell-channels="{ row }">
        <q-td>
          <span v-for="(entry, i) in row.channels" :key="entry.channel">
            <q-icon :name="channelIcon[entry.channel as Channel]" :class="{ 'text-grey-5': entry.fallback }" />
            <q-tooltip>{{ entry.channel }}{{ entry.fallback ? ' (fallback)' : '' }}</q-tooltip>
            <span v-if="Number(i) < row.channels.length - 1" class="text-grey-5"> → </span>
          </span>
        </q-td>
      </template>
      <template #body-cell-status="{ row }">
        <q-td><q-badge :color="campaignColor[row.status as CampaignStatus]" :label="row.status" /></q-td>
      </template>
      <template #body-cell-actions="{ row }">
        <q-td class="text-right" @click.stop>
          <q-btn v-if="row.status === 'draft'" dense flat color="primary" icon="rocket_launch" label="Launch" @click="launch(row.id)" />
          <q-btn v-if="row.status === 'running'" dense flat color="negative" icon="stop" label="Cancel" @click="cancel(row.id)" />
        </q-td>
      </template>
    </q-table>

    <CreateCampaignDialog v-if="creating" v-model="creating" />
  </q-page>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useQuasar, type QTableColumn } from 'quasar'
import { errorText } from '../api/client'
import type { Campaign, CampaignStatus, Channel } from '../api/types'
import CreateCampaignDialog from '../components/CreateCampaignDialog.vue'
import { channelIcon } from '../format'
import { useCampaignsStore } from '../stores/campaigns'

const store = useCampaignsStore()
const $q = useQuasar()
const creating = ref(false)

const campaignColor: Record<CampaignStatus, string> = {
  draft: 'grey-6', running: 'blue-6', completed: 'teal-6', cancelled: 'orange-8',
}

const columns: QTableColumn<Campaign>[] = [
  { name: 'name', label: 'Name', field: 'name', align: 'left' },
  { name: 'channels', label: 'Channel chain', field: 'channels', align: 'left' },
  { name: 'audience', label: 'Audience', field: (c) => c.audience?.tags?.join(', ') || 'everybody', align: 'left' },
  { name: 'cap', label: 'Daily cap', field: 'daily_cap' },
  { name: 'recipients', label: 'Recipients', field: 'recipients_count' },
  { name: 'messages', label: 'Messages', field: 'messages_count' },
  { name: 'status', label: 'Status', field: 'status' },
  { name: 'actions', label: '', field: 'id' },
]

onMounted(() => store.fetchList())

async function launch(id: number) {
  try {
    const planned = await store.launch(id)
    $q.notify({ type: 'positive', message: `Launched: ${planned} recipients planned` })
    await store.fetchList()
  } catch (e) {
    $q.notify({ type: 'negative', message: errorText(e) })
  }
}

function cancel(id: number) {
  $q.dialog({ title: 'Cancel campaign?', message: 'Queued messages will be skipped.', cancel: true }).onOk(async () => {
    try {
      await store.cancel(id)
    } catch (e) {
      $q.notify({ type: 'negative', message: errorText(e) })
    }
  })
}
</script>
