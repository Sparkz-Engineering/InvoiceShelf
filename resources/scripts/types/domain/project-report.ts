import type { Currency } from './currency'

export interface ProjectReportRow {
  /** The answer trimmed and lower-cased; '' for records with no answer */
  key: string
  /** The answer as first seen, or null for records with no answer */
  project: string | null
  income: number
  costs: number
  profit: number
  invoices: Array<{
    invoice_id: number
    invoice_number: string
    date: string
    type: string
    customer: string | null
    amount: number
  }>
  expenses: Array<{
    expense_id: number
    number: string | null
    date: string
    category: string | null
    notes: string | null
    amount: number
  }>
}

/** Income and costs per project, as the project report returns them. */
export interface ProjectReport {
  from_date: string
  to_date: string
  currency: Currency | null
  fields: {
    invoice: Array<{ id: number; label: string }>
    expense: Array<{ id: number; label: string }>
  }
  invoice_field_id: number | null
  expense_field_id: number | null
  projects: ProjectReportRow[]
  totals: { income: number; costs: number; profit: number }
}

export interface ProjectReportParams {
  from_date: string
  to_date: string
  invoice_field_id?: number | null
  expense_field_id?: number | null
}
