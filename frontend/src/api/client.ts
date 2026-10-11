import axios from 'axios'
import type {
  Campaign, CampaignStats, Message, NewCampaign, Overview, Paginated, Sender,
} from './types'

const http = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? '/api',
  headers: { Accept: 'application/json' },
})

/**
 * Stateless API layer: one function per endpoint, no caching, no UI concerns.
 * Stores own state; pages own presentation.
 */
export const api = {
  overview: (hours = 24) =>
    http.get<{ data: Overview }>('/stats/overview', { params: { hours } }).then((r) => r.data.data),

  campaigns: (page = 1) =>
    http.get<Paginated<Campaign>>('/campaigns', { params: { page } }).then((r) => r.data),

  campaign: (id: number) =>
    http.get<{ data: Campaign }>(`/campaigns/${id}`).then((r) => r.data.data),

  createCampaign: (payload: NewCampaign) =>
    http.post<{ data: Campaign }>('/campaigns', payload).then((r) => r.data.data),

  launchCampaign: (id: number) =>
    http.post<{ data: Campaign; planned_recipients: number }>(`/campaigns/${id}/launch`).then((r) => r.data),

  cancelCampaign: (id: number) =>
    http.post<{ data: Campaign }>(`/campaigns/${id}/cancel`).then((r) => r.data.data),

  campaignStats: (id: number) =>
    http.get<{ data: CampaignStats }>(`/campaigns/${id}/stats`).then((r) => r.data.data),

  campaignMessages: (id: number, params: { page?: number; status?: string; channel?: string; per_page?: number }) =>
    http.get<Paginated<Message>>(`/campaigns/${id}/messages`, { params }).then((r) => r.data),

  message: (id: number) =>
    http.get<{ data: Message }>(`/messages/${id}`).then((r) => r.data.data),

  senders: () =>
    http.get<{ data: Sender[] }>('/senders').then((r) => r.data.data),

  toggleSender: (id: number, isActive: boolean) =>
    http.patch(`/senders/${id}`, { is_active: isActive }),

  tags: () =>
    http.get<{ data: { tag: string; total: number }[] }>('/contacts/tags').then((r) => r.data.data),
}

/** Laravel validation / domain error → readable text. */
export function errorText(error: unknown): string {
  if (axios.isAxiosError(error)) {
    const data = error.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined
    const first = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined
    return first ?? data?.message ?? error.message
  }
  return String(error)
}
