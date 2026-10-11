<template>
  <div>
    <div class="text-caption text-grey-7 q-mb-xs">
      Channels are tried in order. "Fallback" channels are used only if the previous one fails.
    </div>
    <q-list bordered separator class="rounded-borders">
      <q-item v-for="(entry, index) in model" :key="entry.channel">
        <q-item-section avatar>
          <q-icon :name="channelIcon[entry.channel]" />
        </q-item-section>
        <q-item-section>
          <q-item-label>{{ index + 1 }}. {{ labels[entry.channel] }}</q-item-label>
        </q-item-section>
        <q-item-section side>
          <q-toggle
            v-model="entry.fallback"
            :disable="index === 0"
            label="fallback"
            dense
          />
        </q-item-section>
        <q-item-section side>
          <div class="row no-wrap">
            <q-btn flat dense round icon="arrow_upward" :disable="index === 0" @click="move(index, -1)" />
            <q-btn flat dense round icon="arrow_downward" :disable="index === model.length - 1" @click="move(index, 1)" />
            <q-btn flat dense round icon="close" color="negative" :disable="model.length === 1" @click="remove(index)" />
          </div>
        </q-item-section>
      </q-item>
    </q-list>
    <div class="q-mt-sm row q-gutter-sm">
      <q-btn
        v-for="channel in available"
        :key="channel"
        outline
        dense
        size="sm"
        :icon="channelIcon[channel]"
        :label="`Add ${labels[channel]}`"
        @click="add(channel)"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import type { ChainEntry, Channel } from '../api/types'
import { channelIcon } from '../format'

const model = defineModel<ChainEntry[]>({ required: true })

const labels: Record<Channel, string> = { whatsapp: 'WhatsApp', telegram: 'Telegram', sms: 'SMS', email: 'Email' }
const all: Channel[] = ['whatsapp', 'telegram', 'sms', 'email']

const available = computed(() => all.filter((c) => !model.value.some((e) => e.channel === c)))

function add(channel: Channel) {
  model.value.push({ channel, fallback: model.value.length > 0 })
}

function remove(index: number) {
  model.value.splice(index, 1)
  if (model.value[0]) model.value[0].fallback = false
}

function move(index: number, delta: number) {
  const [item] = model.value.splice(index, 1)
  model.value.splice(index + delta, 0, item)
  if (model.value[0]) model.value[0].fallback = false
}
</script>
