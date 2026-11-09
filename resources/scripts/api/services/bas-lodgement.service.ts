import { client } from '../client'
import type {
  BasLodgement,
  CreateBasLodgementPayload,
} from '@/scripts/types/domain/bas'

/** BAS statements marked as lodged, with the figures they were lodged with. */
export const basLodgementService = {
  async list(params?: { from_date?: string; to_date?: string }): Promise<BasLodgement[]> {
    const { data } = await client.get<{ data: BasLodgement[] }>('/api/v1/bas-lodgements', { params })
    return data.data
  },

  async create(payload: CreateBasLodgementPayload): Promise<BasLodgement> {
    const { data } = await client.post<{ data: BasLodgement }>('/api/v1/bas-lodgements', payload)
    return data.data
  },

  async delete(id: number): Promise<{ success: boolean }> {
    const { data } = await client.delete(`/api/v1/bas-lodgements/${id}`)
    return data
  },
}
