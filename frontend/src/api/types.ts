export type Channel = 'sms' | 'email' | 'whatsapp' | 'telegram'
export type MessageStatus = 'pending' | 'queued' | 'sent' | 'delivered' | 'read' | 'failed' | 'deleted' | 'skipped'
export type Priority = 'bulk' | 'urgent'
export type CampaignStatus = 'draft' | 'running' | 'completed' | 'cancelled'

export interface ChainEntry {
  channel: Channel
  fallback: boolean
}

export interface Campaign {
  id: number
  name: string
  body: string
  channels: ChainEntry[]
  audience: { tags: string[] } | null
  daily_cap: number
  priority: Priority
  ttl_minutes: number | null
  status: CampaignStatus
  starts_on: string | null
  launched_at: string | null
  completed_at: string | null
  recipients_count?: number
  messages_count?: number
}

export interface NewCampaign {
  name: string
  body: string
  channels: ChainEntry[]
  audience: { tags: string[] }
  daily_cap: number
  priority: Priority
  ttl_minutes: number | null
}

export interface ChannelStats {
  channel: Channel
  label: string
  total: number
  by_status: Record<MessageStatus, number>
  attempted: number
  delivery_rate: number | null
  read_rate: number | null
  failure_rate: number | null
  avg_delivery_seconds: number | null
}

export interface ErrorStat {
  error_code: string
  channel: Channel
  total: number
}

export interface Overview {
  since: string
  channels: ChannelStats[]
  top_errors: ErrorStat[]
  hourly: { hour: string; sent: number }[]
  contacts: number
  campaigns: Partial<Record<CampaignStatus, number>>
}

export interface CampaignStats {
  campaign_id: number
  planned_recipients: number
  processed_recipients: number
  channels: ChannelStats[]
  fallbacks: number
  top_errors: ErrorStat[]
}

export interface MessageEvent {
  status: MessageStatus
  source: 'dispatcher' | 'job' | 'webhook'
  payload: Record<string, unknown> | null
  occurred_at: string
}

export interface Message {
  id: number
  campaign_id: number | null
  contact?: { id: number; name: string }
  channel: Channel
  recipient: string | null
  body: string
  status: MessageStatus
  attempts: number
  sender?: string | null
  fallback_of_id: number | null
  error_code: string | null
  error_message: string | null
  scheduled_for: string | null
  expires_at: string | null
  sent_at: string | null
  delivered_at: string | null
  read_at: string | null
  failed_at: string | null
  deleted_at: string | null
  events?: MessageEvent[]
}

export interface WarmupState {
  profile: 'new' | 'after_quarantine'
  day: number
  days_total: number
  silent: boolean
  limit: number
  used: number
  pace: number
  day_ends_at: string
}

export interface Sender {
  id: number
  channel: Channel
  name: string
  is_active: boolean
  account_id: string | null
  linked: boolean
  last_used_at: string | null
  messages_count: number
  quarantined_until: string | null
  quarantines_count: number
  warmup: WarmupState | null
  warmup_starts_at: string | null
}

export interface LinkToken {
  token: string
  expires_at: string
  upload_url: string
}

export interface Paginated<T> {
  data: T[]
  meta: { current_page: number; last_page: number; total: number; per_page?: number }
}
