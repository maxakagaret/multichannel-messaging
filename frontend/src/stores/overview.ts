import { defineStore } from 'pinia'
import { api } from '../api/client'
import type { Overview, Sender } from '../api/types'

export const useOverviewStore = defineStore('overview', {
  state: () => ({
    overview: null as Overview | null,
    senders: [] as Sender[],
    hours: 24,
  }),

  actions: {
    async fetchOverview() {
      this.overview = await api.overview(this.hours)
    },

    async fetchSenders() {
      this.senders = await api.senders()
    },

    async toggleSender(sender: Sender) {
      const next = !sender.is_active
      await api.toggleSender(sender.id, next)
      sender.is_active = next
    },
  },
})
