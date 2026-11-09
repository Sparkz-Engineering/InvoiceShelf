import type { Customer } from './customer'
import type { User } from './user'
import type { Company } from './company'
import type { Currency } from './currency'
import type { PaymentMethod } from './payment'
import type { CustomFieldValue } from './custom-field'
import type { ExpenseTax } from './tax'
import type { PurchaseRecord } from './purchase'

/**
 * How the amount on an expense was typed: with tax inside it, with tax added
 * on top, or with no tax at all.
 */
export type ExpenseTaxTreatment = 'included' | 'excluded' | 'none'

export interface ExpenseCategory {
  id: number
  name: string
  description: string | null
  /** Whether a new expense under this heading starts as deductible */
  tax_deductible?: boolean
  /** The treatment a new expense starts with; null follows the company default */
  default_tax_treatment?: ExpenseTaxTreatment | null
  company_id: number
  amount: number | null
  formatted_created_at: string
  company?: Company
}

export interface ReceiptUrl {
  url: string
  type: string
}

export interface ReceiptMeta {
  id: number
  uuid: string
  name: string
  file_name: string
  mime_type: string
  size: number
  disk: string
  collection_name: string
}

export interface Expense {
  id: number
  expense_date: string
  expense_number: string | null
  amount: number
  notes: string | null
  supplier_id?: number | null
  customer_id: number | null
  attachment_receipt_url: ReceiptUrl | null
  attachment_receipt: string | null
  attachment_receipt_meta: ReceiptMeta | null
  company_id: number
  expense_category_id: number | null
  creator_id: number
  formatted_expense_date: string
  formatted_created_at: string
  exchange_rate: number
  currency_id: number
  base_amount: number
  payment_method_id: number | null
  /** Whether the amount was typed with tax inside it. `amount` is always the gross. */
  tax_included: boolean
  /** Share of the spend that was for the business, 0 to 100 */
  business_use_percent: number
  tax_deductible: boolean
  deduction_note: string | null
  /** Tax on the receipt, in the expense's currency */
  tax_total: number
  /** The amount without tax */
  net_amount: number
  /** The business share of the tax, which can be claimed back */
  claimable_tax: number
  /** The business share of the net amount, or 0 when not deductible */
  deductible_amount: number
  customer?: Customer
  supplier?: PurchaseRecord | null
  expense_category?: ExpenseCategory
  creator?: User
  fields?: CustomFieldValue[]
  company?: Company
  currency?: Currency
  payment_method?: PaymentMethod
  taxes?: ExpenseTax[]
}

export interface CreateExpensePayload {
  expense_date: string
  amount: number
  expense_category_id?: number | null
  supplier_id?: number | null
  customer_id?: number | null
  payment_method_id?: number | null
  notes?: string | null
  exchange_rate?: number
  currency_id?: number
  tax_included?: boolean
  business_use_percent?: number
  tax_deductible?: boolean
  deduction_note?: string | null
  customFields?: CustomFieldValue[]
  fields?: CustomFieldValue[]
  taxes?: Array<Pick<ExpenseTax, 'tax_type_id' | 'amount'>>
}
