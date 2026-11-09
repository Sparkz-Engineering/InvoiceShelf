import { calcTaxAmount } from '@/scripts/features/shared/document-form/use-document-calculations'
import type { ExpenseTax, TaxType } from '@/scripts/types/domain/tax'
import type { ExpenseTaxTreatment } from '@/scripts/types/domain/expense'

/**
 * The arithmetic behind the expense form's "Includes / Excludes / No tax"
 * choice.
 *
 * The server stores one figure, the gross that left the account, with the
 * tax rows beside it. What the user types depends on the treatment: the gross
 * when tax is included (or there is none), the net when tax is added on top.
 * These helpers convert between the two and work each tax out with the same
 * maths the invoice and bill forms use.
 */

export const TAX_TREATMENTS: ExpenseTaxTreatment[] = ['included', 'excluded', 'none']

export function isTaxTreatment(value: unknown): value is ExpenseTaxTreatment {
  return typeof value === 'string' && (TAX_TREATMENTS as string[]).includes(value)
}

/** The treatment a stored expense was entered with. */
export function treatmentOf(taxIncluded: boolean, taxes: ExpenseTax[]): ExpenseTaxTreatment {
  if (!taxes.length) {
    return 'none'
  }

  return taxIncluded ? 'included' : 'excluded'
}

export function taxTotalOf(taxes: Pick<ExpenseTax, 'amount'>[]): number {
  return taxes.reduce((total, tax) => total + (tax.amount || 0), 0)
}

/** One tax worked out on the figure the user typed. */
export function calculateExpenseTax(
  tax: Pick<ExpenseTax, 'calculation_type' | 'fixed_amount' | 'percent'>,
  enteredAmount: number,
  taxIncluded: boolean,
): number {
  return calcTaxAmount(
    enteredAmount,
    tax.percent,
    tax.fixed_amount,
    tax.calculation_type,
    taxIncluded,
  )
}

/** A tax row for the form, raised from a tax type. */
export function expenseTaxFromType(
  taxType: TaxType,
  enteredAmount: number,
  taxIncluded: boolean,
): ExpenseTax {
  const tax: ExpenseTax = {
    tax_type_id: taxType.id,
    amount: 0,
    name: taxType.name,
    percent: taxType.percent,
    calculation_type: taxType.calculation_type,
    fixed_amount: taxType.fixed_amount,
    compound_tax: taxType.compound_tax,
    type: taxType.type,
    tax_type: taxType,
  }

  tax.amount = calculateExpenseTax(tax, enteredAmount, taxIncluded)

  return tax
}

/** The gross to store, from the figure typed and the taxes. */
export function grossFromEntered(
  enteredAmount: number,
  taxes: Pick<ExpenseTax, 'amount'>[],
  treatment: ExpenseTaxTreatment,
): number {
  return treatment === 'excluded'
    ? enteredAmount + taxTotalOf(taxes)
    : enteredAmount
}

/** The figure to show in the amount field for a stored gross. */
export function enteredFromGross(
  gross: number,
  taxes: Pick<ExpenseTax, 'amount'>[],
  treatment: ExpenseTaxTreatment,
): number {
  return treatment === 'excluded' ? gross - taxTotalOf(taxes) : gross
}
