<template>
  <div class="space-y-6 pt-6">
    <BaseCard container-class="p-4 md:p-5">
      <form class="space-y-4" @submit.prevent="load()">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
          <BaseInputGroup :label="$t('reports.bas.financial_year')">
            <BaseMultiselect
              v-model="yearKey"
              :options="yearOptions"
              value-prop="value"
              label="label"
              :can-deselect="false"
              @update:model-value="onYearChange"
            />
          </BaseInputGroup>
          <BaseInputGroup :label="$t('reports.bas.period')">
            <BaseMultiselect
              v-model="periodKey"
              :options="periodOptions"
              value-prop="value"
              label="label"
              :can-deselect="false"
            />
          </BaseInputGroup>
          <BaseInputGroup :label="$t('reports.bas.basis')">
            <BaseMultiselect
              v-model="basis"
              :options="basisOptions"
              value-prop="value"
              label="label"
              :can-deselect="false"
            />
          </BaseInputGroup>
          <BaseInputGroup :label="$t('reports.bas.payg_method')">
            <BaseMultiselect
              v-model="paygMethod"
              :options="paygOptions"
              value-prop="value"
              label="label"
              :can-deselect="false"
            />
          </BaseInputGroup>
          <BaseInputGroup
            v-if="paygMethod === 'amount'"
            :label="$t('reports.bas.payg_amount')"
            :help-text="$t('reports.bas.payg_amount_help')"
          >
            <BaseMoney
              v-model="paygAmountData"
              :currency="report?.currency ?? companyStore.selectedCompanyCurrency"
            />
          </BaseInputGroup>
          <BaseInputGroup
            v-if="paygMethod === 'rate'"
            :label="$t('reports.bas.payg_rate')"
            :help-text="$t('reports.bas.payg_rate_help')"
          >
            <BaseInput
              v-model="paygRate"
              type="number"
              min="0"
              max="100"
              step="0.01"
            />
          </BaseInputGroup>
        </div>

        <div class="flex flex-wrap gap-3">
          <BaseButton type="submit" :loading="loading">
            {{ $t('reports.update_report') }}
          </BaseButton>
          <BaseButton
            type="button"
            variant="white"
            :loading="downloadingCsv"
            @click="downloadCsv"
          >
            <template #left="slotProps">
              <BaseIcon name="TableCellsIcon" :class="slotProps.class" />
            </template>
            {{ $t('reports.bas.download_csv') }}
          </BaseButton>
          <BaseButton
            class="md:hidden"
            type="button"
            variant="white"
            @click="viewPdf"
          >
            {{ $t('reports.view_pdf') }}
          </BaseButton>
        </div>
      </form>
    </BaseCard>

    <p v-if="loading" role="status" class="text-sm text-muted">
      {{ $t('general.loading') }}
    </p>
    <p v-if="error" role="alert" class="text-sm text-danger">{{ error }}</p>

    <template v-if="report">
      <p class="text-sm text-muted">
        {{
          report.basis === 'accrual'
            ? $t('reports.bas.basis_accrual_note')
            : $t('reports.bas.basis_cash_note')
        }}
      </p>

      <BaseStatStrip :columns="4">
        <BaseStat :label="`1A · ${$t('reports.bas.label_1a')}`">
          <BaseFormatMoney :amount="report.labels['1A'].cents" :currency="report.currency" />
        </BaseStat>
        <BaseStat :label="`1B · ${$t('reports.bas.label_1b')}`">
          <BaseFormatMoney :amount="report.labels['1B'].cents" :currency="report.currency" />
        </BaseStat>
        <BaseStat :label="`5A · ${$t('reports.bas.label_5a')}`">
          <BaseFormatMoney :amount="report.payg['5A'] * 100" :currency="report.currency" />
        </BaseStat>
        <BaseStat
          :label="report.totals['9'] >= 0 ? $t('reports.bas.payable') : $t('reports.bas.refundable')"
          emphasis
        >
          <BaseFormatMoney
            :amount="Math.abs(report.totals['9']) * 100"
            :currency="report.currency"
          />
        </BaseStat>
      </BaseStatStrip>

      <!-- Whether this period's statement has gone in -->
      <BaseCard container-class="p-4 md:p-5">
        <div
          v-if="report.lodgement"
          class="flex flex-wrap items-start justify-between gap-4"
        >
          <div class="flex items-start gap-3 text-sm">
            <BaseIcon name="CheckBadgeIcon" class="mt-0.5 h-5 w-5 shrink-0 text-status-green" />
            <div>
              <p class="font-medium text-heading">
                {{ $t('reports.bas.lodged_on', { date: formatDay(report.lodgement.lodged_on) }) }}
                <template v-if="report.lodgement.reference">
                  · {{ report.lodgement.reference }}
                </template>
              </p>
              <p v-if="report.lodgement.notes" class="mt-1 whitespace-pre-wrap text-muted">
                {{ report.lodgement.notes }}
              </p>
              <p class="mt-1 text-muted">{{ $t('reports.bas.lodged_help') }}</p>
            </div>
          </div>
          <BaseButton
            v-if="canManageLodgements"
            variant="white"
            size="sm"
            :loading="removingLodgement"
            @click="removeLodgement"
          >
            {{ $t('reports.bas.remove_lodgement') }}
          </BaseButton>
        </div>

        <div v-else-if="!showLodgeForm" class="flex flex-wrap items-center justify-between gap-4 text-sm">
          <p class="text-muted">{{ $t('reports.bas.not_lodged') }}</p>
          <BaseButton
            v-if="canManageLodgements"
            variant="primary-outline"
            size="sm"
            @click="openLodgeForm"
          >
            {{ $t('reports.bas.mark_lodged') }}
          </BaseButton>
        </div>

        <form v-else class="space-y-4" @submit.prevent="lodge">
          <p class="text-sm text-muted">{{ $t('reports.bas.mark_lodged_help') }}</p>
          <div class="grid gap-4 md:grid-cols-2">
            <BaseInputGroup :label="$t('reports.bas.lodged_date')" required>
              <BaseDatePicker v-model="lodgeForm.lodged_on" :calendar-button="true" />
            </BaseInputGroup>
            <BaseInputGroup :label="$t('reports.bas.lodgement_reference')">
              <BaseInput v-model="lodgeForm.reference" type="text" maxlength="255" />
            </BaseInputGroup>
            <BaseInputGroup :label="$t('reports.bas.lodgement_notes')" class="md:col-span-2">
              <BaseTextarea v-model="lodgeForm.notes" rows="2" />
            </BaseInputGroup>
          </div>
          <div class="flex gap-3">
            <BaseButton type="submit" :loading="lodging">
              {{ $t('reports.bas.mark_lodged') }}
            </BaseButton>
            <BaseButton type="button" variant="white" @click="showLodgeForm = false">
              {{ $t('general.cancel') }}
            </BaseButton>
          </div>
        </form>
      </BaseCard>

      <!-- Things to sort out before lodging -->
      <section v-if="report.checks.length" class="space-y-3">
        <h2 class="font-semibold text-section text-heading">
          {{ $t('reports.bas.checks') }}
        </h2>
        <details
          v-for="check in report.checks"
          :key="check.key"
          class="rounded-lg border p-4"
          :class="
            check.severity === 'warning'
              ? 'border-alert-warning-text/30 bg-alert-warning-bg'
              : 'border-line-light bg-surface'
          "
        >
          <summary class="flex cursor-pointer items-start gap-3 text-sm">
            <BaseIcon
              :name="check.severity === 'warning' ? 'ExclamationTriangleIcon' : 'InformationCircleIcon'"
              class="mt-0.5 h-5 w-5 shrink-0"
              :class="check.severity === 'warning' ? 'text-alert-warning-text' : 'text-muted'"
            />
            <span class="flex-1 text-heading">
              {{ checkMessage(check.key) }}
              <span class="text-muted">({{ check.count }})</span>
            </span>
          </summary>
          <ul class="mt-3 space-y-2 ps-8 text-sm">
            <li
              v-for="(item, index) in check.items"
              :key="index"
              class="flex flex-wrap items-center justify-between gap-2"
            >
              <template v-if="isChange(item)">
                <span class="font-medium text-heading">{{ item.label }}</span>
                <span class="text-body">
                  {{ $t('reports.bas.lodged_change', { lodged: formNumber(item.lodged), current: formNumber(item.current) }) }}
                </span>
              </template>
              <template v-else>
                <router-link :to="itemLink(item)" class="text-primary-600">
                  {{ itemLabel(item) }}
                </router-link>
                <BaseFormatMoney :amount="item.amount" :currency="report.currency" />
              </template>
            </li>
          </ul>
        </details>
      </section>

      <!-- The statement, label by label -->
      <section class="space-y-3">
        <div class="flex items-center gap-2">
          <h2 class="font-semibold text-section text-heading">
            {{ $t('reports.bas.statement') }}
          </h2>
          <BaseHelpPopover
            :title="$t('reports.bas.statement')"
            :text="$t('reports.bas.statement_help')"
          />
        </div>
        <BaseCard container-class="p-0 overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="border-b border-line-light text-xs text-muted">
              <tr>
                <th scope="col" class="px-4 py-3 text-start font-medium">{{ $t('reports.bas.label') }}</th>
                <th scope="col" class="px-4 py-3 text-start font-medium">{{ $t('reports.bas.description') }}</th>
                <th scope="col" class="px-4 py-3 text-end font-medium">{{ $t('reports.bas.exact_amount') }}</th>
                <th scope="col" class="px-4 py-3 text-end font-medium">{{ $t('reports.bas.on_the_form') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="row in statementRows"
                :key="row.label"
                class="border-b border-line-light last:border-b-0"
                :class="row.total ? 'font-semibold text-heading' : 'text-body'"
              >
                <th scope="row" class="px-4 py-3 text-start font-semibold text-heading">{{ row.label }}</th>
                <td class="px-4 py-3">{{ row.description }}</td>
                <td class="px-4 py-3 text-end">
                  <BaseFormatMoney
                    v-if="row.cents !== null"
                    :amount="row.cents"
                    :currency="report.currency"
                  />
                </td>
                <td class="px-4 py-3 text-end tabular-nums">{{ row.form }}</td>
              </tr>
            </tbody>
          </table>
        </BaseCard>
        <p class="text-xs text-muted">
          {{ $t('reports.bas.cents_note') }}
        </p>
      </section>

      <!-- How 1B was reached -->
      <section class="space-y-3">
        <h2 class="font-semibold text-section text-heading">
          {{ $t('reports.bas.purchases_reconciliation') }}
        </h2>
        <BaseCard container-class="p-4 md:p-5">
          <dl class="space-y-2 text-sm">
            <div class="flex justify-between gap-3">
              <dt class="text-muted">{{ $t('reports.bas.tax_on_purchases') }}</dt>
              <dd><BaseFormatMoney :amount="report.purchases.tax" :currency="report.currency" /></dd>
            </div>
            <div class="flex justify-between gap-3">
              <dt class="text-muted">{{ $t('reports.bas.private_use') }}</dt>
              <dd><BaseFormatMoney :amount="-report.purchases.private_use" :currency="report.currency" /></dd>
            </div>
            <div class="flex justify-between gap-3 border-t border-line-light pt-2 font-semibold text-heading">
              <dt>1B · {{ $t('reports.bas.label_1b') }}</dt>
              <dd><BaseFormatMoney :amount="report.purchases.claimable" :currency="report.currency" /></dd>
            </div>
          </dl>
        </BaseCard>
      </section>

      <!-- The rows behind the figures -->
      <section class="space-y-3">
        <h2 class="font-semibold text-section text-heading">
          {{ $t('reports.bas.sales') }}
        </h2>
        <BaseTable
          :data="report.sales.lines"
          :columns="salesColumns"
          :no-results-message="$t('reports.bas.no_sales')"
        >
          <template #cell-date="{ row }">{{ formatDay(row.data.payment_date ?? row.data.date) }}</template>
          <template #cell-reference="{ row }">
            <router-link
              v-if="row.data.payment_id"
              :to="`/admin/payments/${row.data.payment_id}/view`"
              class="text-primary-600"
              >{{ row.data.payment_number }}</router-link
            >
            <span v-if="row.data.payment_id" class="text-subtle"> · </span>
            <router-link
              :to="`/admin/invoices/${row.data.invoice_id}/view`"
              class="text-primary-600"
              >{{ row.data.invoice_number }}</router-link
            >
          </template>
          <template #cell-amount="{ row }">
            <BaseFormatMoney :amount="row.data.amount" :currency="report.currency" />
          </template>
          <template #cell-tax="{ row }">
            <BaseFormatMoney :amount="row.data.tax" :currency="report.currency" />
          </template>
        </BaseTable>
      </section>

      <section
        v-for="section in purchaseSections"
        :key="section.key"
        class="space-y-3"
      >
        <h2 class="font-semibold text-section text-heading">{{ section.title }}</h2>
        <BaseTable
          :data="section.lines"
          :columns="purchaseColumns"
          :no-results-message="$t('reports.bas.no_purchases')"
        >
          <template #cell-date="{ row }">{{ formatDay(row.data.date) }}</template>
          <template #cell-reference="{ row }">
            <router-link :to="itemLink(row.data)" class="text-primary-600">
              {{ itemLabel(row.data) }}
            </router-link>
            <BaseIcon
              v-if="row.data.has_receipt"
              name="PaperClipIcon"
              class="ms-1 inline h-4 w-4 text-subtle"
              role="img"
              :aria-label="$t('expenses.has_receipt')"
            />
          </template>
          <template #cell-amount="{ row }">
            <BaseFormatMoney :amount="row.data.amount" :currency="report.currency" />
          </template>
          <template #cell-tax="{ row }">
            <BaseFormatMoney :amount="row.data.tax" :currency="report.currency" />
          </template>
          <template #cell-claimable="{ row }">
            <BaseFormatMoney :amount="row.data.claimable" :currency="report.currency" />
          </template>
        </BaseTable>
      </section>
    </template>

    <ReportPdfPane ref="pdfPane" :path="null" class="hidden" />
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { format, parseISO } from 'date-fns'
import BaseHelpPopover from '@/scripts/components/base/BaseHelpPopover.vue'
import { reportService } from '@/scripts/api/services/report.service'
import { basLodgementService } from '@/scripts/api/services/bas-lodgement.service'
import { useDialogStore } from '@/scripts/stores/dialog.store'
import { useUserStore } from '@/scripts/stores/user.store'
import { formatDate } from '@/scripts/utils/format-date'
import { useCompanyStore } from '@/scripts/stores/company.store'
import { useGlobalStore } from '@/scripts/stores/global.store'
import { useNotificationStore } from '@/scripts/stores/notification.store'
import { handleApiError } from '@/scripts/utils/error-handling'
import { downloadDocument } from '@/scripts/utils/documents'
import type { ColumnDef } from '@/scripts/components/table/DataTable.vue'
import type {
  BasBasis,
  BasLodgementChange,
  BasPaygMethod,
  BasPurchaseLine,
  BasReport,
  BasReportParams,
  BasUnallocatedPayment,
} from '@/scripts/types/domain/bas'
import ReportPdfPane from '../components/ReportPdfPane.vue'
import { useReportDownload } from '../useReportDownload'
import {
  financialYearFrom,
  financialYearStart,
  financialYears,
  findPeriod,
  lastFinishedQuarter,
  openingMonth,
  type FinancialYear,
} from '../bas-periods'

/**
 * The Australian BAS worksheet: the figures to copy onto the statement for a
 * quarter, how they were reached, and what to check before lodging.
 */
const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const companyStore = useCompanyStore()
const globalStore = useGlobalStore()
const notificationStore = useNotificationStore()
const dialogStore = useDialogStore()
const userStore = useUserStore()

const canManageLodgements = computed<boolean>(() =>
  userStore.hasAbilities('manage-bas-lodgement'),
)

// Periods of the chosen year with a statement marked as lodged
const lodgedPeriods = ref<Set<string>>(new Set())
const showLodgeForm = ref<boolean>(false)
const lodging = ref<boolean>(false)
const removingLodgement = ref<boolean>(false)
const lodgeForm = reactive({ lodged_on: '', reference: '', notes: '' })

const settings = computed(() => companyStore.selectedCompanySettings ?? {})
const opening = computed<number>(() => openingMonth(settings.value.fiscal_year))

const years = ref<FinancialYear[]>(financialYears(opening.value))
const yearKey = ref<string>('')
const periodKey = ref<string>('')

const basis = ref<BasBasis>(settings.value.bas_gst_basis === 'accrual' ? 'accrual' : 'cash')
const paygMethod = ref<BasPaygMethod>(
  (['amount', 'rate'] as string[]).includes(settings.value.bas_payg_method)
    ? (settings.value.bas_payg_method as BasPaygMethod)
    : 'none',
)
const paygAmount = ref<number>(Number(settings.value.bas_payg_amount) || 0)
const paygRate = ref<number | string>(Number(settings.value.bas_payg_rate) || 0)

const paygAmountData = computed<number>({
  get: () => paygAmount.value / 100,
  set: (value: number) => {
    paygAmount.value = Math.round(value * 100)
  },
})

const report = ref<BasReport | null>(null)
const loading = ref<boolean>(false)
const error = ref<string>('')
const downloadingCsv = ref<boolean>(false)
const pdfPane = ref<InstanceType<typeof ReportPdfPane> | null>(null)
let request = 0

const yearOptions = computed(() =>
  years.value.map((year) => ({
    value: year.key,
    label: t('reports.bas.financial_year_label', { year: year.label }),
  })),
)

const selectedYear = computed(() =>
  years.value.find((year) => year.key === yearKey.value),
)

const periodOptions = computed(() =>
  (selectedYear.value?.periods ?? []).map((period) => ({
    value: period.key,
    label:
      (period.quarter > 0
        ? t('reports.bas.quarter_label', {
            quarter: period.quarter,
            range: formatRange(period.from, period.to),
          })
        : t('reports.bas.full_year_label', { range: formatRange(period.from, period.to) })) +
      (lodgedPeriods.value.has(`${period.from}:${period.to}`)
        ? ` · ${t('reports.bas.lodged')}`
        : ''),
  })),
)

const selectedPeriod = computed(() =>
  selectedYear.value?.periods.find((period) => period.key === periodKey.value),
)

const basisOptions = computed(() => [
  { value: 'cash', label: t('reports.bas.basis_cash') },
  { value: 'accrual', label: t('reports.bas.basis_accrual') },
])

const paygOptions = computed(() => [
  { value: 'none', label: t('reports.bas.payg_none') },
  { value: 'amount', label: t('reports.bas.payg_by_amount') },
  { value: 'rate', label: t('reports.bas.payg_by_rate') },
])

const salesColumns = computed<ColumnDef[]>(() => [
  { key: 'date', label: t('reports.bas.date'), mobile: 'subtitle', sortable: false },
  { key: 'reference', label: t('reports.bas.reference'), mobile: 'title', sortable: false },
  { key: 'customer', label: t('reports.bas.customer'), sortable: false },
  { key: 'amount', label: t('reports.bas.amount'), align: 'end', mobile: 'trailing', sortable: false },
  { key: 'tax', label: t('reports.bas.gst'), align: 'end', sortable: false },
])

const purchaseColumns = computed<ColumnDef[]>(() => [
  { key: 'date', label: t('reports.bas.date'), mobile: 'subtitle', sortable: false },
  { key: 'reference', label: t('reports.bas.reference'), mobile: 'title', sortable: false },
  { key: 'supplier', label: t('reports.bas.supplier'), sortable: false },
  { key: 'amount', label: t('reports.bas.amount'), align: 'end', mobile: 'trailing', sortable: false },
  { key: 'tax', label: t('reports.bas.gst'), align: 'end', sortable: false },
  { key: 'claimable', label: t('reports.bas.gst_claimed'), align: 'end', sortable: false },
])

const purchaseSections = computed(() => {
  if (!report.value) {
    return []
  }

  const { expenses, bills, refunds } = report.value.purchases

  return [
    { key: 'expenses', title: t('reports.bas.expenses'), lines: expenses },
    ...(bills.length ? [{ key: 'bills', title: t('reports.bas.bills'), lines: bills }] : []),
    ...(refunds.length
      ? [{ key: 'refunds', title: t('reports.bas.supplier_credits'), lines: refunds }]
      : []),
  ]
})

const formNumber = (value: number | null): string =>
  value === null ? '' : new Intl.NumberFormat(locale.value.replace('_', '-')).format(value)

const statementRows = computed(() => {
  if (!report.value) {
    return []
  }

  const { labels, payg, totals } = report.value
  const rows: Array<{ label: string; description: string; cents: number | null; form: string; total?: boolean }> = [
    { label: 'G1', description: t('reports.bas.label_g1'), cents: labels.G1.cents, form: formNumber(labels.G1.dollars) },
    { label: '1A', description: t('reports.bas.label_1a'), cents: labels['1A'].cents, form: formNumber(labels['1A'].dollars) },
    { label: '1B', description: t('reports.bas.label_1b'), cents: labels['1B'].cents, form: formNumber(labels['1B'].dollars) },
  ]

  if (payg.method === 'rate') {
    rows.push(
      { label: 'T1', description: t('reports.bas.label_t1'), cents: null, form: formNumber(payg.T1) },
      { label: 'T2', description: t('reports.bas.label_t2'), cents: null, form: `${payg.T2}%` },
      { label: 'T11', description: t('reports.bas.label_t11'), cents: null, form: formNumber(payg.T11) },
    )
  }

  if (payg.method === 'amount') {
    rows.push({ label: 'T7', description: t('reports.bas.label_t7'), cents: null, form: formNumber(payg.T7) })
  }

  rows.push(
    { label: '5A', description: t('reports.bas.label_5a'), cents: null, form: formNumber(payg['5A']) },
    { label: '8A', description: t('reports.bas.label_8a'), cents: null, form: formNumber(totals['8A']) },
    { label: '8B', description: t('reports.bas.label_8b'), cents: null, form: formNumber(totals['8B']) },
    { label: '9', description: t('reports.bas.label_9'), cents: null, form: formNumber(totals['9']), total: true },
  )

  return rows
})

function formatRange(from: string, to: string): string {
  return new Intl.DateTimeFormat(locale.value.replace('_', '-'), {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  }).formatRange(parseISO(from), parseISO(to))
}

function formatDay(date?: string): string {
  return date
    ? new Intl.DateTimeFormat(locale.value.replace('_', '-'), { dateStyle: 'medium' }).format(parseISO(date))
    : ''
}

function checkMessage(key: string): string {
  return t(`reports.bas.check_${key}`, {
    amount: new Intl.NumberFormat(locale.value.replace('_', '-'), {
      style: 'currency',
      currency: report.value?.currency?.code ?? 'AUD',
    }).format(82.5),
  })
}

function itemLink(item: BasPurchaseLine | BasUnallocatedPayment): string {
  const line = item as BasPurchaseLine

  if (line.expense_id) return `/admin/expenses/${line.expense_id}/view`
  if (line.bill_id) return `/admin/bills/${line.bill_id}/view`
  if (line.credit_id) return `/admin/supplier-credits/${line.credit_id}/view`
  if (line.refund_id) return `/admin/supplier-refunds/${line.refund_id}/view`
  if (line.payment_id) return `/admin/payments/${line.payment_id}/view`

  return ''
}

function itemLabel(item: BasPurchaseLine | BasUnallocatedPayment): string {
  const line = item as BasPurchaseLine & Partial<BasUnallocatedPayment>

  if (line.expense_id) {
    return [line.number, line.category].filter(Boolean).join(' · ') || t('expenses.expense')
  }

  if (line.bill_id) {
    return [line.payment_number, line.bill_number].filter(Boolean).join(' · ')
  }

  return [line.payment_number ?? line.number, line.customer].filter(Boolean).join(' · ')
}

function isChange(item: unknown): item is BasLodgementChange {
  return typeof item === 'object' && item !== null && 'lodged' in item && 'current' in item
}

async function loadLodgedPeriods(): Promise<void> {
  const year = selectedYear.value
  const whole = year?.periods.find((period) => period.quarter === 0)

  if (!whole) {
    return
  }

  try {
    const lodgements = await basLodgementService.list({ from_date: whole.from, to_date: whole.to })
    lodgedPeriods.value = new Set(lodgements.map((l) => `${l.from_date}:${l.to_date}`))
  } catch {
    lodgedPeriods.value = new Set()
  }
}

function openLodgeForm(): void {
  lodgeForm.lodged_on = formatDate(new Date())
  lodgeForm.reference = ''
  lodgeForm.notes = ''
  showLodgeForm.value = true
}

async function lodge(): Promise<void> {
  lodging.value = true

  try {
    await basLodgementService.create({
      ...params(),
      lodged_on: lodgeForm.lodged_on,
      reference: lodgeForm.reference || null,
      notes: lodgeForm.notes || null,
    })
    showLodgeForm.value = false
    notificationStore.showNotification({ type: 'success', message: t('reports.bas.lodged_message') })
    await Promise.all([load(), loadLodgedPeriods()])
  } catch (e) {
    notificationStore.showNotification({ type: 'error', message: handleApiError(e).message })
  } finally {
    lodging.value = false
  }
}

function removeLodgement(): void {
  const lodgement = report.value?.lodgement

  if (!lodgement) {
    return
  }

  dialogStore
    .openDialog({
      title: t('general.are_you_sure'),
      message: t('reports.bas.remove_lodgement_confirm'),
      yesLabel: t('general.ok'),
      noLabel: t('general.cancel'),
      variant: 'danger',
      hideNoButton: false,
      size: 'lg',
    })
    .then(async (confirmed: boolean) => {
      if (!confirmed) {
        return
      }

      removingLodgement.value = true

      try {
        await basLodgementService.delete(lodgement.id)
        await Promise.all([load(), loadLodgedPeriods()])
      } finally {
        removingLodgement.value = false
      }
    })
}

function onYearChange(): void {
  // Keep the same quarter when moving between years
  const quarter = selectedPeriod.value?.quarter ?? 1
  const match = selectedYear.value?.periods.find((period) => period.quarter === quarter)

  periodKey.value = match?.key ?? selectedYear.value?.periods[0]?.key ?? ''
}

/** Start from the dates in the address when they are a BAS period, else the last finished quarter. */
function initialisePeriod(): void {
  const from = route.query.from_date
  const to = route.query.to_date

  if (typeof from === 'string' && typeof to === 'string') {
    let found = findPeriod(years.value, from, to)

    if (!found && /^\d{4}-\d{2}-\d{2}$/.test(from)) {
      const start = financialYearStart(opening.value, parseISO(from))
      const year = financialYearFrom(format(start, 'yyyy-MM-dd'), opening.value)
      const matching = year.periods.find((p) => p.from === from && p.to === to)

      if (matching) {
        years.value = [...years.value, year]
        found = { year, period: matching }
      }
    }

    if (found) {
      yearKey.value = found.year.key
      periodKey.value = found.period.key

      return
    }
  }

  const last = lastFinishedQuarter(opening.value)
  const year = years.value.find((y) => y.periods.some((p) => p.key === last.key))

  if (!year) {
    const start = last.key.split(':')[0]
    years.value = [...years.value, financialYearFrom(start, opening.value)]
  }

  yearKey.value = last.key.split(':')[0]
  periodKey.value = last.key
}

function params(): BasReportParams {
  const period = selectedPeriod.value

  return {
    from_date: period?.from ?? '',
    to_date: period?.to ?? '',
    basis: basis.value,
    payg_method: paygMethod.value,
    ...(paygMethod.value === 'amount' ? { payg_amount: paygAmount.value } : {}),
    ...(paygMethod.value === 'rate' ? { payg_rate: Number(paygRate.value) || 0 } : {}),
  }
}

function reportPath(extra: Record<string, string> = {}): string | null {
  const hash = companyStore.selectedCompany?.unique_hash

  if (!hash || !selectedPeriod.value) {
    return null
  }

  const query = new URLSearchParams(
    Object.entries({ ...params(), ...extra }).map(([key, value]) => [key, String(value)]),
  )

  return `/reports/bas/${hash}?${query}`
}

async function load(): Promise<void> {
  if (!selectedPeriod.value) {
    return
  }

  const ticket = ++request
  loading.value = true
  error.value = ''

  void router.replace({
    query: {
      ...route.query,
      report: 'bas',
      from_date: selectedPeriod.value.from,
      to_date: selectedPeriod.value.to,
    },
  })

  try {
    const data = await reportService.getBasReport(params())

    if (ticket === request) {
      report.value = data
    }
  } catch (e) {
    if (ticket === request) {
      report.value = null
      error.value = handleApiError(e).message
    }
  } finally {
    if (ticket === request) {
      loading.value = false
    }
  }
}

async function downloadCsv(): Promise<void> {
  const path = reportPath({ format: 'csv' })

  if (!path) {
    return
  }

  downloadingCsv.value = true

  try {
    await downloadDocument(path, {}, 'bas.csv')
  } catch {
    notificationStore.showNotification({ type: 'error', message: t('pdf.download_failed') })
  } finally {
    downloadingCsv.value = false
  }
}

function viewPdf(): void {
  void pdfPane.value?.view(reportPath())
}

// The download button in the page header takes the PDF
globalStore.downloadReport = useReportDownload(() => reportPath())

onMounted(() => {
  initialisePeriod()
  void load()
})

watch(yearKey, () => void loadLodgedPeriods(), { immediate: true })

onBeforeUnmount(() => {
  request++
})
</script>
