<template>
  <q-dialog v-model="open" persistent>
    <q-card style="width: 640px; max-width: 95vw">
      <q-card-section class="text-h6">New campaign</q-card-section>

      <q-form @submit.prevent="submit">
        <q-card-section class="q-gutter-md">
          <q-input v-model="form.name" label="Name" outlined dense :rules="[(v) => !!v || 'Required']" />
          <q-input
            v-model="form.body"
            label="Message"
            type="textarea"
            outlined
            autogrow
            hint="Variables: {{name}}, {{first_name}}. **bold** is rendered per channel."
            :rules="[(v) => !!v || 'Required']"
          />
          <ChannelChainEditor v-model="form.channels" />
          <q-select
            v-model="form.audience.tags"
            :options="tagOptions"
            label="Audience tags (empty = everybody with consent)"
            multiple
            use-chips
            outlined
            dense
            emit-value
            map-options
          />
          <q-input
            v-model.number="form.daily_cap"
            type="number"
            label="Daily cap per campaign"
            hint="Recipients are spread over days so no day exceeds this number."
            outlined
            dense
            :rules="[(v) => v >= 1 || 'At least 1']"
          />
        </q-card-section>

        <q-card-actions align="right">
          <q-btn flat label="Cancel" v-close-popup />
          <q-btn color="primary" type="submit" label="Create draft" :loading="saving" />
        </q-card-actions>
      </q-form>
    </q-card>
  </q-dialog>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useQuasar } from 'quasar'
import { api, errorText } from '../api/client'
import type { NewCampaign } from '../api/types'
import { useCampaignsStore } from '../stores/campaigns'
import ChannelChainEditor from './ChannelChainEditor.vue'

const open = defineModel<boolean>({ required: true })
const store = useCampaignsStore()
const $q = useQuasar()

const saving = ref(false)
const tagOptions = ref<{ label: string; value: string }[]>([])

const form = reactive<NewCampaign>({
  name: '',
  body: 'Hi {{first_name}}! **-20%** on your next visit this week.',
  channels: [
    { channel: 'whatsapp', fallback: false },
    { channel: 'sms', fallback: true },
  ],
  audience: { tags: [] },
  daily_cap: 50,
})

onMounted(async () => {
  tagOptions.value = (await api.tags()).map((t) => ({ label: `${t.tag} (${t.total})`, value: t.tag }))
})

async function submit() {
  saving.value = true
  try {
    await store.create(form)
    $q.notify({ type: 'positive', message: 'Draft created' })
    open.value = false
  } catch (e) {
    $q.notify({ type: 'negative', message: errorText(e) })
  } finally {
    saving.value = false
  }
}
</script>
