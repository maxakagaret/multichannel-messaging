import type { Channel, MessageStatus } from './api/types'

export const channelIcon: Record<Channel, string> = {
  sms: 'sms',
  email: 'mail',
  whatsapp: 'chat',
  telegram: 'send',
}

export const statusColor: Record<MessageStatus, string> = {
  pending: 'grey-6',
  queued: 'blue-grey-5',
  sent: 'blue-6',
  delivered: 'teal-6',
  read: 'green-7',
  failed: 'red-6',
  skipped: 'orange-7',
}

export const percent = (value: number | null) => (value === null ? '—' : `${(value * 100).toFixed(1)}%`)

export const dateTime = (iso: string | null) => (iso ? new Date(iso).toLocaleString() : '—')

export const seconds = (value: number | null) => (value === null ? '—' : value < 60 ? `${value}s` : `${Math.round(value / 60)}m`)
