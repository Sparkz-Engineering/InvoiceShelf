import type { Currency } from './currency'

/** The kinds of deduction recorded by hand. Mirrors `TaxDeduction::CATEGORIES`. */
export const TAX_DEDUCTION_CATEGORIES = [
  'home_office',
  'vehicle',
  'depreciation',
  'travel',
  'professional_fees',
  'education',
  'insurance',
  'phone_internet',
  'other',
] as const

export type TaxDeductionCategory = (typeof TAX_DEDUCTION_CATEGORIES)[number]

/** A deduction recorded by hand rather than through an expense. */
export interface TaxDeduction {
  id: number
  deduction_date: string
  category: TaxDeductionCategory
  description: string
  /** Minor units of the company's currency */
  amount: number
  notes: string | null
  creator?: { id: number; name: string } | null
  created_at: string
}

export interface TaxDeductionPayload {
  deduction_date: string
  category: TaxDeductionCategory
  description: string
  amount: number
  notes?: string | null
}

export interface DeductibleExpenseLine {
  expense_id: number
  date: string
  number: string | null
  supplier: string | null
  notes: string | null
  gross: number
  tax: number
  net: number
  business_use_percent: number
  deductible: number
  deduction_note: string | null
}

/** A financial year's deductions, as the deductions report returns them. */
export interface TaxDeductionsReport {
  from_date: string
  to_date: string
  currency: Currency | null
  totals: { expenses: number; other: number; total: number }
  expenses: Array<{
    expense_category_id: number
    name: string | null
    total: number
    lines: DeductibleExpenseLine[]
  }>
  other: Array<{
    category: TaxDeductionCategory
    total: number
    lines: Array<{ id: number; date: string; description: string; amount: number; notes: string | null }>
  }>
  not_deductible: { count: number; amount: number }
}
