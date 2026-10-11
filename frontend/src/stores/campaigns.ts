import { defineStore } from 'pinia'
import { api } from '../api/client'
import type { Campaign, CampaignStats, Message, NewCampaign } from '../api/types'

export const useCampaignsStore = defineStore('campaigns', {
  state: () => ({
    list: [] as Campaign[],
    total: 0,
    loading: false,
    current: null as Campaign | null,
    stats: null as CampaignStats | null,
    messages: [] as Message[],
    messagesTotal: 0,
  }),

  actions: {
    async fetchList(page = 1) {
      this.loading = true
      try {
        const res = await api.campaigns(page)
        this.list = res.data
        this.total = res.meta.total
      } finally {
        this.loading = false
      }
    },

    async create(payload: NewCampaign) {
      const campaign = await api.createCampaign(payload)
      this.list.unshift(campaign)
      return campaign
    },

    async launch(id: number) {
      const res = await api.launchCampaign(id)
      this.replace(res.data)
      return res.planned_recipients
    },

    async cancel(id: number) {
      this.replace(await api.cancelCampaign(id))
    },

    /** Detail page: campaign + funnel, refreshed by polling while the campaign runs. */
    async fetchDetail(id: number) {
      const [campaign, stats] = await Promise.all([api.campaign(id), api.campaignStats(id)])
      this.current = campaign
      this.stats = stats
    },

    async fetchMessages(id: number, params: { page?: number; status?: string; channel?: string; per_page?: number }) {
      const res = await api.campaignMessages(id, params)
      this.messages = res.data
      this.messagesTotal = res.meta.total
    },

    replace(campaign: Campaign) {
      const i = this.list.findIndex((c) => c.id === campaign.id)
      if (i >= 0) this.list[i] = { ...this.list[i], ...campaign }
      if (this.current?.id === campaign.id) this.current = { ...this.current, ...campaign }
    },
  },
})
