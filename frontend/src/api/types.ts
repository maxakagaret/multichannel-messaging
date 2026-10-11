export type Channel = 'sms' | 'email' | 'whatsapp' | 'telegram'
export type MessageStatus = 'pending' | 'queued' | 'sent' | 'delivered' | 'read' | 'failed' | 'skipped'
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
  sent_at: string | null
  delivered_at: string | null
  read_at: string | null
  failed_at: string | null
  events?: MessageEvent[]
}

export interface Sender {
  id: number
  channel: Channel
  name: string
  is_active: boolean
  last_used_at: string | null
  messages_count: number
}

export interface Paginated<T> {
  data: T[]
  meta: { current_page: number; last_page: number; total: number; per_page?: number }
}
