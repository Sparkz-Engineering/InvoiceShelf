import { client } from '../client'
import { API } from '../endpoints'
import type { DateRangeParams } from '@/scripts/types/api'
import type { BasReport, BasReportParams } from '@/scripts/types/domain/bas'
import type { ProjectReport, ProjectReportParams } from '@/scripts/types/domain/project-report'

export interface ReportParams extends DateRangeParams {
  report_type?: string
}

export interface SalesReportResponse {
  data: Array<{
    date: string
    total: number
    count: number
  }>
  total: number
  from_date: string
  to_date: string
}

export interface ProfitLossReportResponse {
  data: {
    income: Array<{
      label: string
      amount: number
    }>
    expenses: Array<{
      label: string
      amount: number
    }>
    net_profit: number
  }
  from_date: string
  to_date: string
}

export interface ExpenseReportResponse {
  data: Array<{
    category: string
    total: number
    count: number
  }>
  total: number
  from_date: string
  to_date: string
}

export interface TaxReportResponse {
  data: Array<{
    tax_name: string
    tax_amount: number
    invoice_count: number
  }>
  total: number
  from_date: string
  to_date: string
}

export const reportService = {
  /** Income and costs per project, matched by a custom field. */
  async getProjectReport(params: ProjectReportParams): Promise<ProjectReport> {
    const { data } = await client.get<{ data: ProjectReport }>('/api/v1/reports/projects', { params })
    return data.data
  },

  /** The Australian BAS worksheet for a period, with the rows behind it. */
  async getBasReport(params: BasReportParams): Promise<BasReport> {
    const { data } = await client.get<{ data: BasReport }>('/api/v1/reports/bas', { params })
    return data.data
  },

  async getSalesReport(params: ReportParams): Promise<SalesReportResponse> {
    const { data } = await client.get(API.DASHBOARD, {
      params: { ...params, report_type: 'sales' },
    })
    return data
  },

  async getProfitLossReport(params: ReportParams): Promise<ProfitLossReportResponse> {
    const { data } = await client.get(API.DASHBOARD, {
      params: { ...params, report_type: 'profit_loss' },
    })
    return data
  },

  async getExpenseReport(params: ReportParams): Promise<ExpenseReportResponse> {
    const { data } = await client.get(API.DASHBOARD, {
      params: { ...params, report_type: 'expenses' },
    })
    return data
  },

  async getTaxReport(params: ReportParams): Promise<TaxReportResponse> {
    const { data } = await client.get(API.DASHBOARD, {
      params: { ...params, report_type: 'tax' },
    })
    return data
  },
}
