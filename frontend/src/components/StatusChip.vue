<template>
  <q-chip dense square :color="statusColor[status]" text-color="white" :label="status" />
</template>

<script setup lang="ts">
import type { MessageStatus } from '../api/types'
import { statusColor } from '../format'

defineProps<{ status: MessageStatus }>()
</script>
