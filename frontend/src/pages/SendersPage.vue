<template>
  <q-page padding>
    <div class="text-h5 q-mb-xs">Sender pool</div>
    <div class="text-grey-7 q-mb-md">
      Every message gets the earliest slot any available sender can offer: channel throttle, sending window,
      and the sender's warm-up day (daily limit, pace, silent days). Quarantined senders get no slots.
    </div>

    <q-list bordered separator class="rounded-borders">
      <q-item v-for="sender in store.senders" :key="sender.id">
        <q-item-section avatar><q-icon :name="channelIcon[sender.channel]" /></q-item-section>
        <q-item-section>
          <q-item-label>
            {{ sender.name }}
            <span v-if="sender.account_id" class="text-grey-7">· {{ sender.account_id }}</span>
            <q-badge v-if="!sender.linked" class="q-ml-sm" color="grey-6" label="not linked" />
            <q-badge v-if="sender.quarantined_until" class="q-ml-sm" color="red-6">
              quarantined until {{ dateTime(sender.quarantined_until) }}
            </q-badge>
          </q-item-label>
          <q-item-label caption>
            {{ sender.channel }} · {{ sender.messages_count }} messages · last slot {{ dateTime(sender.last_used_at) }}
            <template v-if="sender.quarantines_count"> · {{ sender.quarantines_count }} quarantine(s) so far</template>
          </q-item-label>

          <q-item-label v-if="sender.warmup" caption class="q-mt-xs">
            <q-badge :color="sender.warmup.silent ? 'orange-7' : 'blue-grey-5'" class="q-mr-sm">
              warm-up · {{ sender.warmup.profile.replace('_', ' ') }} · day {{ sender.warmup.day }}/{{ sender.warmup.days_total }}
            </q-badge>
            <template v-if="sender.warmup.silent">silent day: no sends until {{ dateTime(sender.warmup.day_ends_at) }}</template>
            <template v-else>
              {{ sender.warmup.used }} / {{ sender.warmup.limit }} today · pace ×{{ sender.warmup.pace }}
              <q-linear-progress
                class="q-mt-xs"
                style="max-width: 240px"
                :value="sender.warmup.limit ? sender.warmup.used / sender.warmup.limit : 0"
              />
            </template>
          </q-item-label>
          <q-item-label v-else-if="sender.warmup_starts_at" caption class="q-mt-xs">
            warm-up after quarantine starts {{ dateTime(sender.warmup_starts_at) }}
          </q-item-label>
        </q-item-section>

        <q-item-section side>
          <div class="row items-center q-gutter-sm">
            <q-btn v-if="!sender.linked" flat dense icon="link" label="Link" @click="link(sender)" />
            <q-btn v-else flat dense icon="link_off" label="Unlink" @click="store.unlinkSender(sender)" />
            <q-toggle :model-value="sender.is_active" label="active" @update:model-value="store.toggleSender(sender)" />
          </div>
        </q-item-section>
      </q-item>
    </q-list>

    <q-dialog v-model="tokenOpen">
      <q-card v-if="token" style="width: 560px; max-width: 95vw">
        <q-card-section class="text-h6">Link an account</q-card-section>
        <q-card-section class="q-gutter-sm">
          <div>
            Hand this one-time link to the tool that holds the account credentials. It works once and expires at
            {{ dateTime(token.expires_at) }}; a new link cancels this one.
          </div>
          <q-input :model-value="token.upload_url" readonly outlined dense label="Upload URL" />
          <pre class="q-pa-sm bg-grey-2 rounded-borders text-caption" style="white-space: pre-wrap">{{ curlExample }}</pre>
        </q-card-section>
        <q-card-actions align="right"><q-btn flat label="Close" v-close-popup /></q-card-actions>
      </q-card>
    </q-dialog>
  </q-page>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useQuasar } from 'quasar'
import { api, errorText } from '../api/client'
import type { LinkToken, Sender } from '../api/types'
import { usePolling } from '../composables/usePolling'
import { channelIcon, dateTime } from '../format'
import { useOverviewStore } from '../stores/overview'

const store = useOverviewStore()
const $q = useQuasar()
usePolling(() => store.fetchSenders(), 10000)

const token = ref<LinkToken | null>(null)
const tokenOpen = ref(false)

const curlExample = computed(() =>
  token.value
    ? `curl -X POST '${token.value.upload_url}' \\\n  -H 'Content-Type: application/json' \\\n  -d '{"credentials": {"account": "+15550001234", "keys": {"session": "..."}}}'`
    : '',
)

async function link(sender: Sender) {
  try {
    token.value = await api.issueLinkToken(sender.id)
    tokenOpen.value = true
  } catch (e) {
    $q.notify({ type: 'negative', message: errorText(e) })
  }
}
</script>
