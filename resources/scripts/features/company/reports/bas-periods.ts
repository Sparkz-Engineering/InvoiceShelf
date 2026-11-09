import { addMonths, endOfMonth, format, parseISO, subDays } from 'date-fns'

/**
 * The periods a BAS is lodged for: the quarters of each financial year, and
 * the year itself.
 *
 * The financial year follows the company's `fiscal_year` setting ('7-6' for
 * July to June, as in Australia), resolved the way the server resolves it
 * (`ReportingPeriod::fiscalYear`): a year starts on the first of its opening
 * month.
 */

const FMT = 'yyyy-MM-dd'

export interface BasPeriod {
  key: string
  /** 1 to 4 for a quarter, 0 for the whole year */
  quarter: number
  from: string
  to: string
}

export interface FinancialYear {
  key: string
  /** "2026–27", or "2026" when the year is a calendar year */
  label: string
  periods: BasPeriod[]
}

/** The month a financial year opens in, from a setting such as '7-6'. */
export function openingMonth(setting?: string | null): number {
  const month = Number(String(setting ?? '').split('-')[0])

  return Number.isInteger(month) && month >= 1 && month <= 12 ? month : 1
}

/** The first day of the financial year a date falls in. */
export function financialYearStart(opening: number, date: Date): Date {
  const year =
    date.getMonth() + 1 >= opening ? date.getFullYear() : date.getFullYear() - 1

  return new Date(year, opening - 1, 1)
}

function yearLabel(start: Date, opening: number): string {
  const year = start.getFullYear()

  return opening === 1
    ? String(year)
    : `${year}–${String(year + 1).slice(-2)}`
}

function financialYear(start: Date, opening: number): FinancialYear {
  const key = format(start, FMT)
  const quarters = [1, 2, 3, 4].map((quarter) => {
    const from = addMonths(start, (quarter - 1) * 3)

    return {
      key: `${key}:q${quarter}`,
      quarter,
      from: format(from, FMT),
      to: format(endOfMonth(addMonths(from, 2)), FMT),
    }
  })

  return {
    key,
    label: yearLabel(start, opening),
    periods: [
      ...quarters,
      {
        key: `${key}:year`,
        quarter: 0,
        from: format(start, FMT),
        to: format(endOfMonth(addMonths(start, 11)), FMT),
      },
    ],
  }
}

/** This financial year and the few before it, newest first. */
export function financialYears(
  opening: number,
  count = 4,
  now: Date = new Date(),
): FinancialYear[] {
  const current = financialYearStart(opening, now)

  return Array.from({ length: count }, (_, index) =>
    financialYear(addMonths(current, -12 * index), opening),
  )
}

/**
 * The quarter most recently finished: the one a BAS is usually being
 * prepared for.
 */
export function lastFinishedQuarter(
  opening: number,
  now: Date = new Date(),
): BasPeriod {
  const start = financialYearStart(opening, now)
  const monthsIn =
    (now.getFullYear() - start.getFullYear()) * 12 +
    now.getMonth() -
    start.getMonth()
  const currentQuarterStart = addMonths(start, Math.floor(monthsIn / 3) * 3)
  const dayBefore = subDays(currentQuarterStart, 1)
  const year = financialYear(financialYearStart(opening, dayBefore), opening)

  return year.periods.find(
    (period) =>
      period.quarter > 0 &&
      period.from <= format(dayBefore, FMT) &&
      period.to >= format(dayBefore, FMT),
  ) as BasPeriod
}

/** The period with exactly these dates, if there is one. */
export function findPeriod(
  years: FinancialYear[],
  from: string,
  to: string,
): { year: FinancialYear; period: BasPeriod } | null {
  for (const year of years) {
    const period = year.periods.find((p) => p.from === from && p.to === to)

    if (period) {
      return { year, period }
    }
  }

  return null
}

/** The financial year starting on a 'yyyy-MM-dd' date. */
export function financialYearFrom(start: string, opening: number): FinancialYear {
  return financialYear(parseISO(start), opening)
}
