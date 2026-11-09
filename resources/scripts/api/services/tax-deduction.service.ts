import { client } from '../client'
import type {
  TaxDeduction,
  TaxDeductionPayload,
  TaxDeductionsReport,
} from '@/scripts/types/domain/tax-deduction'

/** Deductions recorded by hand, and a year's deductions as a whole. */
export const taxDeductionService = {
  async list(params?: { from_date?: string; to_date?: string }): Promise<TaxDeduction[]> {
    const { data } = await client.get<{ data: TaxDeduction[] }>('/api/v1/tax-deductions', { params })
    return data.data
  },

  async create(payload: TaxDeductionPayload): Promise<TaxDeduction> {
    const { data } = await client.post<{ data: TaxDeduction }>('/api/v1/tax-deductions', payload)
    return data.data
  },

  async update(id: number, payload: TaxDeductionPayload): Promise<TaxDeduction> {
    const { data } = await client.put<{ data: TaxDeduction }>(`/api/v1/tax-deductions/${id}`, payload)
    return data.data
  },

  async delete(id: number): Promise<{ success: boolean }> {
    const { data } = await client.delete(`/api/v1/tax-deductions/${id}`)
    return data
  },

  /** Deductible expenses and recorded deductions for a period. */
  async report(params: { from_date: string; to_date: string }): Promise<TaxDeductionsReport> {
    const { data } = await client.get<{ data: TaxDeductionsReport }>('/api/v1/reports/tax-deductions', { params })
    return data.data
  },
}
