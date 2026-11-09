<template>
  <div class="space-y-6 pt-6">
    <BaseCard container-class="p-4 md:p-5">
      <form class="space-y-4" @submit.prevent="load()">
        <div class="grid gap-4 md:grid-cols-3">
          <BaseInputGroup :label="$t('reports.sales.date_range')">
            <BasePeriodPicker
              v-model="period"
              :presets="presets"
              block
              position="bottom-start"
            />
          </BaseInputGroup>
          <BaseInputGroup
            :label="$t('reports.projects.invoice_field')"
            :help-text="$t('reports.projects.fields_help')"
          >
            <BaseMultiselect
              v-model="invoiceFieldId"
              :options="report?.fields.invoice ?? []"
              value-prop="id"
              label="label"
              :can-deselect="false"
            />
          </BaseInputGroup>
          <BaseInputGroup :label="$t('reports.projects.expense_field')">
            <BaseMultiselect
              v-model="expenseFieldId"
              :options="report?.fields.expense ?? []"
              value-prop="id"
              label="label"
              :can-deselect="false"
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
            {{ $t('reports.projects.download_csv') }}
          </BaseButton>
        </div>
      </form>
    </BaseCard>

    <p v-if="error" role="alert" class="text-sm text-danger">{{ error }}</p>

    <template v-if="report">
      <BaseInfoAlert
        v-if="!report.fields.invoice.length || !report.fields.expense.length"
        :title="$t('reports.projects.title')"
        :lists="[$t('reports.projects.no_fields')]"
      />

      <BaseStatStrip :columns="3">
        <BaseStat :label="$t('reports.projects.income')">
          <BaseFormatMoney :amount="report.totals.income" :currency="report.currency" />
        </BaseStat>
        <BaseStat :label="$t('reports.projects.costs')">
          <BaseFormatMoney :amount="report.totals.costs" :currency="report.currency" />
        </BaseStat>
        <BaseStat :label="$t('reports.projects.profit')" emphasis>
          <BaseFormatMoney :amount="report.totals.profit" :currency="report.currency" />
        </BaseStat>
      </BaseStatStrip>

      <BaseCard container-class="p-0 overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="border-b border-line-light text-xs text-muted">
            <tr>
              <th scope="col" class="px-4 py-3 text-start font-medium">{{ $t('reports.projects.project') }}</th>
              <th scope="col" class="px-4 py-3 text-end font-medium">{{ $t('reports.projects.income') }}</th>
              <th scope="col" class="px-4 py-3 text-end font-medium">{{ $t('reports.projects.costs') }}</th>
              <th scope="col" class="px-4 py-3 text-end font-medium">{{ $t('reports.projects.profit') }}</th>
            </tr>
          </thead>
          <tbody v-for="row in report.projects" :key="row.key">
            <tr class="border-b border-line-light">
              <th scope="row" class="px-4 py-3 text-start font-medium">
                <button
                  type="button"
                  class="inline-flex items-center gap-2 text-heading hover:text-primary-600"
                  :aria-expanded="expanded.has(row.key)"
                  @click="toggle(row.key)"
                >
                  <BaseIcon
                    :name="expanded.has(row.key) ? 'ChevronDownIcon' : 'ChevronRightIcon'"
                    class="h-4 w-4 text-subtle rtl:-scale-x-100"
                  />
                  {{ row.project ?? $t('reports.projects.no_project') }}
                </button>
              </th>
              <td class="px-4 py-3 text-end text-body">
                <BaseFormatMoney :amount="row.income" :currency="report.currency" />
              </td>
              <td class="px-4 py-3 text-end text-body">
                <BaseFormatMoney :amount="row.costs" :currency="report.currency" />
              </td>
              <td class="px-4 py-3 text-end font-medium" :class="row.profit < 0 ? 'text-danger' : 'text-heading'">
                <BaseFormatMoney :amount="row.profit" :currency="report.currency" />
              </td>
            </tr>
            <tr v-if="expanded.has(row.key)" class="border-b border-line-light bg-surface-muted">
              <td colspan="4" class="px-4 py-3">
                <div class="grid gap-6 md:grid-cols-2">
                  <div>
                    <p class="mb-2 text-xs font-semibold text-muted">{{ $t('reports.projects.invoices') }}</p>
                    <ul class="space-y-1">
                      <li
                        v-for="invoice in row.invoices"
                        :key="invoice.invoice_id"
                        class="flex justify-between gap-3"
                      >
                        <router-link :to="`/admin/invoices/${invoice.invoice_id}/view`" class="text-primary-600">
                          {{ invoice.invoice_number }} · {{ formatDay(invoice.date) }}
                        </router-link>
                        <BaseFormatMoney :amount="invoice.amount" :currency="report.currency" />
                      </li>
                      <li v-if="!row.invoices.length" class="text-subtle">-</li>
                    </ul>
                  </div>
                  <div>
                    <p class="mb-2 text-xs font-semibold text-muted">{{ $t('reports.projects.expenses') }}</p>
                    <ul class="space-y-1">
                      <li
                        v-for="expense in row.expenses"
                        :key="expense.expense_id"
                        class="flex justify-between gap-3"
                      >
                        <router-link :to="`/admin/expenses/${expense.expense_id}/view`" class="text-primary-600">
                          {{ [expense.number, expense.category].filter(Boolean).join(' · ') }} · {{ formatDay(expense.date) }}
                        </router-link>
                        <BaseFormatMoney :amount="expense.amount" :currency="report.currency" />
                      </li>
                      <li v-if="!row.expenses.length" class="text-subtle">-</li>
                    </ul>
                  </div>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
        <p v-if="!report.projects.length" class="p-4 text-sm text-muted md:p-5">
          {{ $t('reports.projects.no_records') }}
        </p>
      </BaseCard>
    </template>
  </div>
</template>

<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { parseISO } from 'date-fns'
import { reportService } from '@/scripts/api/services/report.service'
import { useCompanyStore } from '@/scripts/stores/company.store'
import { useGlobalStore } from '@/scripts/stores/global.store'
import { useNotificationStore } from '@/scripts/stores/notification.store'
import { handleApiError } from '@/scripts/utils/error-handling'
import { downloadDocument } from '@/scripts/utils/documents'
import type { ProjectReport } from '@/scripts/types/domain/project-report'
import { useReportDownload } from '../useReportDownload'
import { useReportPeriod } from '../use-report-period'

/**
 * Income and costs per project, where a project is the answer in a custom
 * field on invoices and on expenses, such as a project number.
 */
const { t, locale } = useI18n()
const companyStore = useCompanyStore()
const globalStore = useGlobalStore()
const notificationStore = useNotificationStore()

const { period, presets, formData, syncUrl } = useReportPeriod(() => void load(false))

const report = ref<ProjectReport | null>(null)
const invoiceFieldId = ref<number | null>(null)
const expenseFieldId = ref<number | null>(null)
const loading = ref<boolean>(false)
const error = ref<string>('')
const downloadingCsv = ref<boolean>(false)
const expanded = ref<Set<string>>(new Set())
let request = 0

function params(): Record<string, string> {
  return {
    from_date: formData.from_date,
    to_date: formData.to_date,
    ...(invoiceFieldId.value ? { invoice_field_id: String(invoiceFieldId.value) } : {}),
    ...(expenseFieldId.value ? { expense_field_id: String(expenseFieldId.value) } : {}),
  }
}

function reportPath(extra: Record<string, string> = {}): string | null {
  const hash = companyStore.selectedCompany?.unique_hash

  return hash ? `/reports/projects/${hash}?${new URLSearchParams({ ...params(), ...extra })}` : null
}

function formatDay(date: string): string {
  return new Intl.DateTimeFormat(locale.value.replace('_', '-'), { dateStyle: 'medium' }).format(parseISO(date))
}

function toggle(key: string): void {
  const next = new Set(expanded.value)

  if (next.has(key)) {
    next.delete(key)
  } else {
    next.add(key)
  }

  expanded.value = next
}

async function load(persist = true): Promise<void> {
  const ticket = ++request
  loading.value = true
  error.value = ''

  if (persist) {
    void syncUrl()
  }

  try {
    const data = await reportService.getProjectReport({
      from_date: formData.from_date,
      to_date: formData.to_date,
      invoice_field_id: invoiceFieldId.value,
      expense_field_id: expenseFieldId.value,
    })

    if (ticket === request) {
      report.value = data
      // The fields actually used, which is the default pairing on first load
      invoiceFieldId.value = data.invoice_field_id
      expenseFieldId.value = data.expense_field_id
    }
  } catch (e) {
    if (ticket === request) {
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
    await downloadDocument(path, {}, 'projects.csv')
  } catch {
    notificationStore.showNotification({ type: 'error', message: t('pdf.download_failed') })
  } finally {
    downloadingCsv.value = false
  }
}

// The download button in the page header takes the PDF
globalStore.downloadReport = useReportDownload(() => reportPath())

void load()

onBeforeUnmount(() => {
  request++
})
</script>
