<template>
  <BasePage>
    <BasePageHeader
      :title="$t('tax_deductions.title')"
      :help="$t('page_help.tax_deductions')"
      phone-actions="bar"
    >
      <BaseBreadcrumb>
        <BaseBreadcrumbItem :title="$t('general.home')" to="/admin/dashboard" />
        <BaseBreadcrumbItem :title="$t('tax_deductions.title')" to="#" active />
      </BaseBreadcrumb>

      <template #actions>
        <BaseButton
          variant="white"
          :loading="downloading === 'csv'"
          @click="download('csv')"
        >
          <template #left="slotProps">
            <BaseIcon name="TableCellsIcon" :class="slotProps.class" />
          </template>
          {{ $t('tax_deductions.download_csv') }}
        </BaseButton>
        <BaseButton
          variant="white"
          :loading="downloading === 'pdf'"
          @click="download('pdf')"
        >
          <template #left="slotProps">
            <BaseIcon name="ArrowDownTrayIcon" :class="slotProps.class" />
          </template>
          {{ $t('tax_deductions.download_pdf') }}
        </BaseButton>
        <BaseButton v-if="canCreate" variant="primary" @click="openModal(null)">
          <template #left="slotProps">
            <BaseIcon name="PlusIcon" :class="slotProps.class" />
          </template>
          {{ $t('tax_deductions.add') }}
        </BaseButton>
      </template>
    </BasePageHeader>

    <p class="max-w-3xl text-sm text-muted">{{ $t('tax_deductions.description') }}</p>

    <BaseCard container-class="p-4 md:p-5">
      <BaseInputGroup :label="$t('tax_deductions.financial_year')" class="w-full md:w-80">
        <BaseMultiselect
          v-model="yearKey"
          :options="yearOptions"
          value-prop="value"
          label="label"
          :can-deselect="false"
        />
      </BaseInputGroup>
    </BaseCard>

    <p v-if="error" role="alert" class="text-sm text-danger">{{ error }}</p>

    <BaseContentPlaceholders v-if="loading && !report">
      <BaseContentPlaceholdersBox :rounded="true" class="h-48 w-full" />
    </BaseContentPlaceholders>

    <template v-if="report">
      <BaseStatStrip :columns="3">
        <BaseStat :label="$t('tax_deductions.from_expenses')">
          <BaseFormatMoney :amount="report.totals.expenses" :currency="currency" />
        </BaseStat>
        <BaseStat :label="$t('tax_deductions.other')">
          <BaseFormatMoney :amount="report.totals.other" :currency="currency" />
        </BaseStat>
        <BaseStat :label="$t('tax_deductions.total')" emphasis>
          <BaseFormatMoney :amount="report.totals.total" :currency="currency" />
        </BaseStat>
      </BaseStatStrip>

      <!-- Deductions recorded by hand -->
      <section class="space-y-3">
        <h2 class="font-semibold text-section text-heading">
          {{ $t('tax_deductions.other') }}
        </h2>
        <BaseCard container-class="p-0 overflow-x-auto">
          <table v-if="deductions.length" class="w-full text-sm">
            <thead class="border-b border-line-light text-xs text-muted">
              <tr>
                <th scope="col" class="px-4 py-3 text-start font-medium">{{ $t('tax_deductions.date') }}</th>
                <th scope="col" class="px-4 py-3 text-start font-medium">{{ $t('tax_deductions.category') }}</th>
                <th scope="col" class="px-4 py-3 text-start font-medium">{{ $t('tax_deductions.description_label') }}</th>
                <th scope="col" class="px-4 py-3 text-end font-medium">{{ $t('tax_deductions.amount') }}</th>
                <th scope="col" class="px-4 py-3"><span class="sr-only">{{ $t('general.actions') }}</span></th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="deduction in deductions"
                :key="deduction.id"
                class="border-b border-line-light align-top last:border-b-0"
              >
                <td class="whitespace-nowrap px-4 py-3 text-body">{{ formatDay(deduction.deduction_date) }}</td>
                <td class="px-4 py-3 text-body">{{ $t(`tax_deductions.categories.${deduction.category}`) }}</td>
                <td class="px-4 py-3">
                  <p class="font-medium text-heading">{{ deduction.description }}</p>
                  <p v-if="deduction.notes" class="mt-1 whitespace-pre-wrap text-muted">{{ deduction.notes }}</p>
                </td>
                <td class="whitespace-nowrap px-4 py-3 text-end text-heading">
                  <BaseFormatMoney :amount="deduction.amount" :currency="currency" />
                </td>
                <td class="whitespace-nowrap px-4 py-3 text-end">
                  <BaseButton
                    v-if="canEdit"
                    variant="white"
                    size="sm"
                    :aria-label="$t('tax_deductions.edit')"
                    @click="openModal(deduction)"
                  >
                    <BaseIcon name="PencilSquareIcon" class="h-4 w-4" />
                  </BaseButton>
                  <BaseButton
                    v-if="canDelete"
                    variant="white"
                    size="sm"
                    class="ms-2"
                    :aria-label="$t('general.delete')"
                    @click="remove(deduction)"
                  >
                    <BaseIcon name="TrashIcon" class="h-4 w-4 text-danger" />
                  </BaseButton>
                </td>
              </tr>
            </tbody>
          </table>
          <div v-else class="flex flex-wrap items-center justify-between gap-4 p-4 text-sm md:p-5">
            <p class="text-muted">{{ $t('tax_deductions.no_other') }}</p>
            <BaseButton v-if="canCreate" variant="primary-outline" size="sm" @click="openModal(null)">
              {{ $t('tax_deductions.add') }}
            </BaseButton>
          </div>
        </BaseCard>
      </section>

      <!-- Deductible expenses, by category -->
      <section class="space-y-3">
        <div class="flex items-center gap-2">
          <h2 class="font-semibold text-section text-heading">
            {{ $t('tax_deductions.from_expenses') }}
          </h2>
          <BaseHelpPopover
            :title="$t('tax_deductions.from_expenses')"
            :text="$t('tax_deductions.from_expenses_help')"
          />
        </div>
        <BaseCard container-class="p-0 overflow-x-auto">
          <table v-if="report.expenses.length" class="w-full text-sm">
            <thead class="border-b border-line-light text-xs text-muted">
              <tr>
                <th scope="col" class="px-4 py-3 text-start font-medium">{{ $t('tax_deductions.date') }}</th>
                <th scope="col" class="px-4 py-3 text-start font-medium">{{ $t('tax_deductions.expense') }}</th>
                <th scope="col" class="px-4 py-3 text-end font-medium">{{ $t('tax_deductions.cost_excluding_tax') }}</th>
                <th scope="col" class="px-4 py-3 text-end font-medium">{{ $t('tax_deductions.business_use') }}</th>
                <th scope="col" class="px-4 py-3 text-end font-medium">{{ $t('tax_deductions.deductible') }}</th>
              </tr>
            </thead>
            <tbody v-for="category in report.expenses" :key="category.expense_category_id">
              <tr class="border-b border-line-light bg-surface-muted">
                <th scope="rowgroup" colspan="4" class="px-4 py-2 text-start font-semibold text-heading">
                  {{ category.name ?? $t('expenses.uncategorized') }}
                </th>
                <td class="px-4 py-2 text-end font-semibold text-heading">
                  <BaseFormatMoney :amount="category.total" :currency="currency" />
                </td>
              </tr>
              <tr
                v-for="line in category.lines"
                :key="line.expense_id"
                class="border-b border-line-light align-top"
              >
                <td class="whitespace-nowrap px-4 py-3 text-body">{{ formatDay(line.date) }}</td>
                <td class="px-4 py-3">
                  <router-link :to="`/admin/expenses/${line.expense_id}/view`" class="text-primary-600">
                    {{ [line.number, line.supplier ?? line.notes].filter(Boolean).join(' · ') || $t('expenses.expense') }}
                  </router-link>
                  <p v-if="line.deduction_note" class="mt-1 whitespace-pre-wrap text-muted">
                    {{ line.deduction_note }}
                  </p>
                </td>
                <td class="whitespace-nowrap px-4 py-3 text-end text-body">
                  <BaseFormatMoney :amount="line.net" :currency="currency" />
                </td>
                <td class="whitespace-nowrap px-4 py-3 text-end text-body">{{ line.business_use_percent }}%</td>
                <td class="whitespace-nowrap px-4 py-3 text-end text-heading">
                  <BaseFormatMoney :amount="line.deductible" :currency="currency" />
                </td>
              </tr>
            </tbody>
          </table>
          <p v-else class="p-4 text-sm text-muted md:p-5">{{ $t('tax_deductions.no_expenses') }}</p>
        </BaseCard>
        <p v-if="report.not_deductible.count" class="text-xs text-muted">
          {{
            $t('tax_deductions.not_deductible', {
              count: report.not_deductible.count,
              amount: money(report.not_deductible.amount),
            })
          }}
        </p>
      </section>
    </template>

    <TaxDeductionModal
      :show="modalOpen"
      :deduction="editing"
      :default-date="defaultDate"
      :currency="currency"
      @close="modalOpen = false"
      @saved="onSaved"
    />
  </BasePage>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { parseISO } from 'date-fns'
import BaseHelpPopover from '@/scripts/components/base/BaseHelpPopover.vue'
import { taxDeductionService } from '@/scripts/api/services/tax-deduction.service'
import { useCompanyStore } from '@/scripts/stores/company.store'
import { useDialogStore } from '@/scripts/stores/dialog.store'
import { useNotificationStore } from '@/scripts/stores/notification.store'
import { useUserStore } from '@/scripts/stores/user.store'
import { handleApiError } from '@/scripts/utils/error-handling'
import { downloadDocument } from '@/scripts/utils/documents'
import { formatDate } from '@/scripts/utils/format-date'
import { financialYears, openingMonth } from '../../reports/bas-periods'
import TaxDeductionModal from '../components/TaxDeductionModal.vue'
import type { TaxDeduction, TaxDeductionsReport } from '@/scripts/types/domain/tax-deduction'

/**
 * A financial year's deductions: the deductible expenses as they stand, and
 * the deductions recorded by hand, which can be added and changed here.
 */
const { t, locale } = useI18n()
const companyStore = useCompanyStore()
const dialogStore = useDialogStore()
const notificationStore = useNotificationStore()
const userStore = useUserStore()

const canCreate = computed(() => userStore.hasAbilities('create-tax-deduction'))
const canEdit = computed(() => userStore.hasAbilities('edit-tax-deduction'))
const canDelete = computed(() => userStore.hasAbilities('delete-tax-deduction'))

const years = computed(() =>
  financialYears(openingMonth(companyStore.selectedCompanySettings?.fiscal_year), 5),
)
const yearKey = ref<string>(years.value[0]?.key ?? '')
const selectedRange = computed(() =>
  years.value.find((year) => year.key === yearKey.value)?.periods.find((period) => period.quarter === 0),
)

const yearOptions = computed(() =>
  years.value.map((year) => ({
    value: year.key,
    label: t('tax_deductions.financial_year_label', { year: year.label }),
  })),
)

const report = ref<TaxDeductionsReport | null>(null)
const deductions = ref<TaxDeduction[]>([])
const loading = ref<boolean>(false)
const error = ref<string>('')
const downloading = ref<'pdf' | 'csv' | null>(null)
const modalOpen = ref<boolean>(false)
const editing = ref<TaxDeduction | null>(null)
let request = 0

const currency = computed(() => report.value?.currency ?? companyStore.selectedCompanyCurrency ?? null)

// A new deduction is dated today if that falls in the year shown, else on its last day
const defaultDate = computed<string | null>(() => {
  const range = selectedRange.value
  const today = formatDate(new Date())

  if (!range) {
    return null
  }

  return today >= range.from && today <= range.to ? null : range.to
})

function formatDay(date: string): string {
  return new Intl.DateTimeFormat(locale.value.replace('_', '-'), { dateStyle: 'medium' }).format(parseISO(date))
}

function money(cents: number): string {
  return new Intl.NumberFormat(locale.value.replace('_', '-'), {
    style: 'currency',
    currency: currency.value?.code ?? 'AUD',
  }).format(cents / 100)
}

async function load(): Promise<void> {
  const range = selectedRange.value

  if (!range) {
    return
  }

  const ticket = ++request
  loading.value = true
  error.value = ''

  try {
    const params = { from_date: range.from, to_date: range.to }
    const [summary, list] = await Promise.all([
      taxDeductionService.report(params),
      taxDeductionService.list(params),
    ])

    if (ticket === request) {
      report.value = summary
      deductions.value = list
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

function openModal(deduction: TaxDeduction | null): void {
  editing.value = deduction
  modalOpen.value = true
}

function onSaved(): void {
  modalOpen.value = false
  void load()
}

function remove(deduction: TaxDeduction): void {
  dialogStore
    .openDialog({
      title: t('general.are_you_sure'),
      message: t('tax_deductions.confirm_delete'),
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

      await taxDeductionService.delete(deduction.id)
      notificationStore.showNotification({ type: 'success', message: 'tax_deductions.deleted_message' })
      void load()
    })
}

async function download(format: 'pdf' | 'csv'): Promise<void> {
  const hash = companyStore.selectedCompany?.unique_hash
  const range = selectedRange.value

  if (!hash || !range) {
    return
  }

  downloading.value = format

  try {
    await downloadDocument(
      `/reports/tax-deductions/${hash}`,
      {
        from_date: range.from,
        to_date: range.to,
        ...(format === 'csv' ? { format: 'csv' } : { download: 'true' }),
      },
      `tax-deductions.${format}`,
    )
  } catch {
    notificationStore.showNotification({ type: 'error', message: t('pdf.download_failed') })
  } finally {
    downloading.value = null
  }
}

watch(yearKey, () => void load(), { immediate: true })
</script>
