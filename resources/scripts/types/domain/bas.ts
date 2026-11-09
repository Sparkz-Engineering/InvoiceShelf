import type { Currency } from './currency'

/** How GST is counted: when money moves, or by document date. */
export type BasBasis = 'cash' | 'accrual'

/** How the PAYG instalment is worked out, if at all. */
export type BasPaygMethod = 'none' | 'amount' | 'rate'

/** One BAS label: the exact figure and the whole dollars the form takes. */
export interface BasFigure {
  cents: number
  dollars: number
}

export interface BasSalesLine {
  payment_id?: number
  payment_number?: string
  payment_date?: string
  invoice_id: number
  invoice_number: string
  date?: string
  type?: string
  customer: string | null
  amount: number
  tax: number
}

export interface BasPurchaseLine {
  expense_id?: number
  bill_id?: number
  bill_number?: string
  credit_id?: number
  refund_id?: number
  payment_id?: number
  payment_number?: string
  number?: string | null
  date: string
  category?: string | null
  supplier?: string | null
  amount: number
  tax: number
  claimable: number
  business_use_percent?: number
  has_receipt?: boolean
}

export interface BasUnallocatedPayment {
  payment_id: number
  payment_number: string
  date: string
  customer: string | null
  amount: number
}

export interface BasCheck {
  key: 'receipt_missing' | 'unallocated_payments' | 'expenses_without_tax' | 'supplier_credits' | string
  severity: 'warning' | 'info'
  count: number
  amount: number
  items: Array<BasPurchaseLine | BasUnallocatedPayment | BasLodgementChange>
}

export interface BasReport {
  from_date: string
  to_date: string
  basis: BasBasis
  currency: Currency | null
  labels: { G1: BasFigure; '1A': BasFigure; '1B': BasFigure }
  payg: {
    method: BasPaygMethod
    T1: number | null
    T2: number | null
    T7: number | null
    T11: number | null
    '5A': number
  }
  totals: { '8A': number; '8B': number; '9': number }
  sales: {
    total: number
    tax: number
    lines: BasSalesLine[]
    unallocated: BasUnallocatedPayment[]
  }
  purchases: {
    total: number
    tax: number
    claimable: number
    private_use: number
    expenses: BasPurchaseLine[]
    bills: BasPurchaseLine[]
    refunds: BasPurchaseLine[]
  }
  checks: BasCheck[]
  /** The lodgement recorded for exactly this period, if there is one */
  lodgement: BasReportLodgement | null
}

/** A GST label that no longer matches what was lodged, in whole dollars. */
export interface BasLodgementChange {
  label: string
  lodged: number
  current: number
}

export interface BasReportLodgement {
  id: number
  lodged_on: string
  reference: string | null
  notes: string | null
  figures: Pick<BasReport, 'basis' | 'labels' | 'payg' | 'totals'>
  changes: BasLodgementChange[]
}

/** A statement marked as lodged, as the lodgements list returns it. */
export interface BasLodgement {
  id: number
  from_date: string
  to_date: string
  basis: BasBasis
  figures: Pick<BasReport, 'basis' | 'labels' | 'payg' | 'totals'>
  lodged_on: string
  reference: string | null
  notes: string | null
  creator?: { id: number; name: string } | null
  created_at: string
}

export interface CreateBasLodgementPayload extends BasReportParams {
  lodged_on: string
  reference?: string | null
  notes?: string | null
}

export interface BasReportParams {
  from_date: string
  to_date: string
  basis?: BasBasis
  payg_method?: BasPaygMethod
  payg_amount?: number
  payg_rate?: number
}
