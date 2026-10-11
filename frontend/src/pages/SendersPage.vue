<template>
  <q-page padding>
    <div class="text-h5 q-mb-xs">Sender pool</div>
    <div class="text-grey-7 q-mb-md">
      Messenger traffic is spread across senders (least recently used first) and paced per sender.
      Deactivating a sender removes it from rotation immediately.
    </div>

    <q-list bordered separator class="rounded-borders">
      <q-item v-for="sender in store.senders" :key="sender.id">
        <q-item-section avatar><q-icon :name="channelIcon[sender.channel]" /></q-item-section>
        <q-item-section>
          <q-item-label>{{ sender.name }}</q-item-label>
          <q-item-label caption>
            {{ sender.channel }} · {{ sender.messages_count }} messages · last slot {{ dateTime(sender.last_used_at) }}
          </q-item-label>
        </q-item-section>
        <q-item-section side>
          <q-toggle :model-value="sender.is_active" label="active" @update:model-value="store.toggleSender(sender)" />
        </q-item-section>
      </q-item>
    </q-list>
  </q-page>
</template>

<script setup lang="ts">
import { usePolling } from '../composables/usePolling'
import { channelIcon, dateTime } from '../format'
import { useOverviewStore } from '../stores/overview'

const store = useOverviewStore()
usePolling(() => store.fetchSenders(), 10000)
</script>
