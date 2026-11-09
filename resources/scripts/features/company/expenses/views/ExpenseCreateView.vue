<template>
  <BasePage class="relative">
    <form
      id="expense-form"
      class="flex flex-col gap-4 md:gap-5"
      @submit.prevent="submitForm"
    >
      <!-- On phones Save moves to the bottom bar, still submitting this form -->
      <BasePageHeader
        :help="$t('page_help.expenses')"
        :title="pageTitle"
        phone-actions="bar"
      >
        <BaseBreadcrumb>
          <BaseBreadcrumbItem
            :title="$t('general.home')"
            to="/admin/dashboard"
          />
          <BaseBreadcrumbItem
            :title="$t('expenses.expense', 2)"
            to="/admin/expenses"
          />
          <BaseBreadcrumbItem :title="pageTitle" to="#" active />
        </BaseBreadcrumb>

        <template #actions>
          <BaseButton
            v-if="
              isEdit &&
              expenseStore.currentExpense.supplier_id &&
              userStore.hasAbilities('create-supplier-credit')
            "
            variant="white"
            type="button"
            @click="
              $router.push({
                path: '/admin/supplier-credits/create',
                query: { expense_id: $route.params.id },
              })
            "
            >{{ $t('purchases.new_credit') }}</BaseButton
          >
          <BaseButton
            v-if="isEdit && expenseStore.currentExpense.attachment_receipt_url"
            :loading="isOpeningReceipt"
            variant="white"
            type="button"
            @click="viewReceipt"
          >
            <template #left="slotProps">
              <BaseIcon name="EyeIcon" :class="slotProps.class" />
            </template>
            {{ $t('expenses.view_receipt') }}
          </BaseButton>
          <BaseButton
            v-if="isEdit && expenseStore.currentExpense.attachment_receipt_url"
            :loading="isDownloadingReceipt"
            variant="white"
            type="button"
            @click="downloadReceipt"
          >
            <template #left="slotProps">
              <BaseIcon name="ArrowDownTrayIcon" :class="slotProps.class" />
            </template>
            {{ $t('expenses.download_receipt') }}
          </BaseButton>

          <BaseButton
            :loading="isSaving"
            :content-loading="isFetchingInitialData"
            :disabled="isSaving"
            variant="primary"
            type="submit"
            form="expense-form"
          >
            <template #left="slotProps">
              <BaseIcon
                v-if="!isSaving"
                name="ArrowDownOnSquareIcon"
                :class="slotProps.class"
              />
            </template>
            {{
              isEdit
                ? $t('expenses.update_expense')
                : $t('expenses.save_expense')
            }}
          </BaseButton>
        </template>
      </BasePageHeader>

      <div
        class="grid items-start gap-5"
        :class="userStore.hasAbilities('view-supplier') ? 'lg:grid-cols-2' : ''"
      >
        <SupplierPicker
          v-if="
            userStore.hasAbilities('view-supplier') && !isFetchingInitialData
          "
          v-model="expenseStore.currentExpense.supplier_id"
          :required="false"
        />
        <BaseCard container-class="p-4 md:p-5">
          <BaseInputGrid>
            <!-- Category -->
            <BaseInputGroup
              :label="$t('expenses.category')"
              :content-loading="isFetchingInitialData"
              required
            >
              <!-- A new company has no categories: one can be added from here -->
              <BaseMultiselect
                v-if="!isFetchingInitialData"
                :key="categoryReloadKey"
                v-model="expenseStore.currentExpense.expense_category_id"
                :content-loading="isFetchingInitialData"
                value-prop="id"
                label="name"
                track-by="id"
                :options="searchCategory"
                :filter-results="false"
                resolve-on-load
                :delay="500"
                searchable
                :placeholder="$t('expenses.categories.select_a_category')"
                @update:model-value="onCategoryChange"
              >
                <template
                  v-if="
                    userStore.hasAbilities([
                      ABILITIES.CREATE_EXPENSE,
                      ABILITIES.EDIT_EXPENSE,
                    ])
                  "
                  #action
                >
                  <BaseSelectAction @click="addCategory">
                    <BaseIcon
                      name="PlusIcon"
                      class="h-4 me-2 -ms-2 text-center text-primary-400"
                    />
                    {{ $t('settings.expense_category.add_new_category') }}
                  </BaseSelectAction>
                </template>
              </BaseMultiselect>
            </BaseInputGroup>

            <!-- Expense Date -->
            <BaseInputGroup
              :label="$t('expenses.expense_date')"
              :content-loading="isFetchingInitialData"
              required
            >
              <BaseDatePicker
                v-model="expenseStore.currentExpense.expense_date"
                :content-loading="isFetchingInitialData"
                :calendar-button="true"
              />
            </BaseInputGroup>

            <!-- Expense Number -->
            <BaseInputGroup
              :label="$t('expenses.expense_number')"
              :content-loading="isFetchingInitialData"
            >
              <BaseInput
                v-model="expenseStore.currentExpense.expense_number"
                :content-loading="isFetchingInitialData"
                type="text"
                name="expense_number"
                :placeholder="$t('expenses.expense_number_placeholder')"
              />
            </BaseInputGroup>

            <!-- How the amount is typed: with tax inside it, on top, or none -->
            <ExpenseTaxTreatmentPicker
              v-if="!isFetchingInitialData"
              :model-value="taxTreatment"
              :tax-name="taxName"
              @update:model-value="onTreatmentChange"
            />

            <!-- Amount -->
            <BaseInputGroup
              :label="amountLabel"
              :content-loading="isFetchingInitialData"
              required
            >
              <BaseMoney
                :key="String(expenseStore.currentExpense.selectedCurrency)"
                v-model="enteredAmountData"
                class="focus:border focus:border-solid focus:border-primary-500"
                :currency="expenseStore.currentExpense.selectedCurrency"
              />
              <p
                v-if="taxTreatment !== 'none' && !isFetchingInitialData"
                class="mt-1.5 text-xs text-muted"
              >
                {{
                  taxTreatment === 'excluded'
                    ? $t('expenses.total_paid')
                    : $t('expenses.amount_excluding_tax', { tax: taxName || $t('expenses.tax') })
                }}:
                <BaseFormatMoney
                  :amount="
                    taxTreatment === 'excluded'
                      ? grossAmount
                      : enteredAmount - taxTotal
                  "
                  :currency="expenseStore.currentExpense.selectedCurrency"
                />
              </p>
            </BaseInputGroup>

            <!-- Currency -->
            <BaseInputGroup
              :label="$t('expenses.currency')"
              :content-loading="isFetchingInitialData"
              required
            >
              <BaseMultiselect
                v-model="expenseStore.currentExpense.currency_id"
                value-prop="id"
                label="name"
                track-by="name"
                :content-loading="isFetchingInitialData"
                :options="globalStore.currencies"
                searchable
                :can-deselect="false"
                :placeholder="$t('customers.select_currency')"
                class="w-full"
                @update:model-value="onCurrencyChange"
              />
            </BaseInputGroup>

            <!-- Exchange Rate -->
            <ExchangeRateConverter
              :store="expenseStore"
              store-prop="currentExpense"
              :v="{
                exchange_rate: { $error: false, $errors: [], $touch: () => {} },
              }"
              :is-loading="isFetchingInitialData"
              :is-edit="isEdit"
              :customer-currency="expenseStore.currentExpense.currency_id"
            />

            <!-- Customer -->
            <BaseInputGroup
              :content-loading="isFetchingInitialData"
              :label="$t('expenses.customer')"
            >
              <BaseCustomerSelectInput
                v-if="!isFetchingInitialData"
                v-model="expenseStore.currentExpense.customer_id"
                can-deselect
                show-action
              />
            </BaseInputGroup>

            <!-- Payment Mode -->
            <BaseInputGroup
              :content-loading="isFetchingInitialData"
              :label="$t('payments.payment_mode')"
            >
              <PurchaseLookupSelect
                v-model="expenseStore.currentExpense.payment_method_id"
                kind="method"
                :options="expenseStore.paymentModes"
                :content-loading="isFetchingInitialData"
              />
            </BaseInputGroup>

            <!-- Custom fields join the form's own grid rather than forming a
               band of their own; they are attributes like the rest. -->
            <CustomFieldInput
              v-for="field in customFields"
              :key="field.id"
              :custom-field-scope="customFieldValidationScope"
              :field="field"
            />
          </BaseInputGrid>
        </BaseCard>
      </div>
      <BaseCard container-class="p-4 md:p-5">
        <BaseInputGrid>
          <!-- Notes -->
          <BaseInputGroup
            :content-loading="isFetchingInitialData"
            :label="$t('expenses.note')"
          >
            <BaseTextarea
              v-model="expenseStore.currentExpense.notes"
              :content-loading="isFetchingInitialData"
              :row="4"
              rows="4"
            />
          </BaseInputGroup>

          <!-- Receipt -->
          <BaseInputGroup :label="$t('expenses.receipt')">
            <BaseFileUploader
              v-model="expenseStore.currentExpense.receiptFiles"
              accept="image/*,.doc,.docx,.pdf,.csv,.xlsx,.xls"
              @change="onFileInputChange"
              @remove="onFileInputRemove"
            />
          </BaseInputGroup>
        </BaseInputGrid>

        <ExpenseTaxSection
          v-if="taxTreatment !== 'none'"
          v-model="expenseStore.currentExpense.taxes"
          :amount="enteredAmount"
          :tax-included="taxTreatment === 'included'"
          :tax-types="taxTypes"
          :currency="expenseStore.currentExpense.selectedCurrency"
          :is-loading="isFetchingInitialData"
          @tax-type-created="onTaxTypeCreated"
        />
      </BaseCard>

      <!-- What the expense means at tax time -->
      <BaseCard container-class="p-4 md:p-5">
        <div class="mb-4 flex items-center gap-2">
          <h2 class="text-base font-semibold text-heading">
            {{ $t('expenses.tax_and_deductions') }}
          </h2>
          <BaseHelpPopover
            :title="$t('expenses.tax_and_deductions')"
            :text="$t('expenses.tax_and_deductions_description')"
          />
        </div>

        <BaseInputGrid>
          <BaseInputGroup
            :label="$t('expenses.business_use_percent')"
            :help-text="$t('expenses.business_use_percent_help')"
            :content-loading="isFetchingInitialData"
          >
            <BaseInput
              v-model="businessUsePercent"
              :content-loading="isFetchingInitialData"
              type="number"
              min="0"
              max="100"
              step="0.01"
              name="business_use_percent"
            />
          </BaseInputGroup>

          <BaseInputGroup
            :label="$t('expenses.tax_deductible')"
            :content-loading="isFetchingInitialData"
          >
            <BaseSwitch
              v-model="expenseStore.currentExpense.tax_deductible"
              class="mt-2"
              @update:model-value="isDeductibleTouched = true"
            />
          </BaseInputGroup>

          <BaseInputGroup
            :label="$t('expenses.deduction_note')"
            :content-loading="isFetchingInitialData"
            class="md:col-span-2"
          >
            <BaseTextarea
              v-model="deductionNote"
              :content-loading="isFetchingInitialData"
              :placeholder="$t('expenses.deduction_note_placeholder')"
              rows="3"
            />
          </BaseInputGroup>
        </BaseInputGrid>
      </BaseCard>
    </form>

    <CategoryModal />
    <PurchaseLookupModals :context="lookups" />
  </BasePage>
</template>

<script setup lang="ts">
import { providePurchaseLookups } from '../../purchases/composables/use-purchase-lookups'
import PurchaseLookupModals from '../../purchases/components/PurchaseLookupModals.vue'
import PurchaseLookupSelect from '../../purchases/components/PurchaseLookupSelect.vue'
const lookups = providePurchaseLookups()

import SupplierPicker from '../../purchases/components/SupplierPicker.vue'
import { ref, computed, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useExpenseStore } from '../store'
import { useGlobalStore } from '../../../../stores/global.store'
import { useCompanyStore } from '../../../../stores/company.store'
import { useNotificationStore } from '../../../../stores/notification.store'
import { useUserStore } from '../../../../stores/user.store'
import { useModalStore } from '../../../../stores/modal.store'
import { ABILITIES } from '../../../../config/abilities'
import {
  handleApiError,
  getErrorTranslationKey,
} from '@/scripts/utils/error-handling'
import { formatDate } from '@/scripts/utils/format-date'
import CategoryModal from '@/scripts/features/company/settings/components/CategoryModal.vue'
import {
  downloadDocument,
  fetchDocumentBlob,
  openDocument,
} from '@/scripts/utils/documents'
import { ExchangeRateConverter } from '../../../shared/document-form'
import BaseHelpPopover from '@/scripts/components/base/BaseHelpPopover.vue'
import ExpenseTaxSection from '../components/ExpenseTaxSection.vue'
import ExpenseTaxTreatmentPicker from '../components/ExpenseTaxTreatmentPicker.vue'
import CustomFieldInput from '@/scripts/features/shared/custom-fields/CustomFieldInput.vue'
import { useCustomFields } from '@/scripts/features/shared/custom-fields/use-custom-fields'
import { taxTypeService } from '@/scripts/api/services/tax-type.service'
import {
  enteredFromGross,
  expenseTaxFromType,
  grossFromEntered,
  isTaxTreatment,
  taxTotalOf,
  treatmentOf,
} from '../tax-treatment'
import type {
  ExpenseCategory,
  ExpenseTaxTreatment,
} from '../../../../types/domain/expense'
import type { Currency } from '../../../../types/domain/currency'
import type { TaxType } from '../../../../types/domain/tax'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const customFieldValidationScope = 'customFields'

const expenseStore = useExpenseStore()
const globalStore = useGlobalStore()
const companyStore = useCompanyStore()
const notificationStore = useNotificationStore()
const userStore = useUserStore()
const modalStore = useModalStore()

const isSaving = ref<boolean>(false)
const isFetchingInitialData = ref<boolean>(false)
const isAttachmentReceiptRemoved = ref<boolean>(false)
const isDownloadingReceipt = ref<boolean>(false)
const isOpeningReceipt = ref<boolean>(false)

// The purchase tax types this company can put on an expense
const taxTypes = ref<TaxType[]>([])

// How the amount is typed, and the figure typed. The store's `amount` is the
// gross and is only worked out from these when the form is saved.
const taxTreatment = ref<ExpenseTaxTreatment>('included')
const enteredAmount = ref<number>(0)

// A new expense follows its category's starting values until the user picks
// for themselves
const isTreatmentTouched = ref<boolean>(false)
const isDeductibleTouched = ref<boolean>(false)

const enteredAmountData = computed<number>({
  get: () => enteredAmount.value / 100,
  set: (value: number) => {
    enteredAmount.value = Math.round(value * 100)
  },
})

const isEdit = computed<boolean>(() => route.name === 'expenses.edit')

const customFields = useCustomFields({
  store: expenseStore,
  storeProp: 'currentExpense',
  type: 'Expense',
  isEdit: () => isEdit.value,
})

const pageTitle = computed<string>(() =>
  isEdit.value ? t('expenses.edit_expense') : t('expenses.new_expense'),
)

/**
 * The tax a new expense starts with: the one chosen in the tax settings, or
 * the only purchase tax there is.
 */
const defaultTaxType = computed<TaxType | null>(() => {
  const chosen = Number(
    companyStore.selectedCompanySettings?.default_purchase_tax_type_id,
  )

  return (
    taxTypes.value.find((taxType) => taxType.id === chosen) ??
    (taxTypes.value.length === 1 ? taxTypes.value[0] : null)
  )
})

/** "GST" rather than "tax", when the expense or the company says which */
const taxName = computed<string | null>(
  () =>
    expenseStore.currentExpense.taxes[0]?.name ??
    defaultTaxType.value?.name ??
    null,
)

const taxTotal = computed<number>(() =>
  taxTreatment.value === 'none'
    ? 0
    : taxTotalOf(expenseStore.currentExpense.taxes),
)

const grossAmount = computed<number>(() =>
  grossFromEntered(
    enteredAmount.value,
    taxTreatment.value === 'none' ? [] : expenseStore.currentExpense.taxes,
    taxTreatment.value,
  ),
)

const amountLabel = computed<string>(() => {
  const tax = taxName.value || t('expenses.tax')

  if (taxTreatment.value === 'included') {
    return t('expenses.amount_including_tax', { tax })
  }

  if (taxTreatment.value === 'excluded') {
    return t('expenses.amount_excluding_tax', { tax })
  }

  return t('expenses.amount')
})

const businessUsePercent = computed<number | string>({
  get: () => expenseStore.currentExpense.business_use_percent,
  set: (value: number | string) => {
    const percent = Number(value)

    expenseStore.currentExpense.business_use_percent = Number.isFinite(percent)
      ? Math.min(100, Math.max(0, percent))
      : 100
  },
})

const deductionNote = computed<string>({
  get: () => expenseStore.currentExpense.deduction_note ?? '',
  set: (value: string) => {
    expenseStore.currentExpense.deduction_note = value
  },
})

const receiptPath = computed<string>(() =>
  isEdit.value ? `/reports/expenses/${route.params.id}/download-receipt` : '',
)

/**
 * The receipt used to be a plain link, which only reaches a server the page
 * shares an origin with. Fetching it through the API client works from a
 * client too, and a missing file now says so instead of saving the error.
 */
async function downloadReceipt(): Promise<void> {
  if (!receiptPath.value) {
    return
  }

  isDownloadingReceipt.value = true

  try {
    await downloadDocument(receiptPath.value, {}, 'receipt')
  } catch {
    notificationStore.showNotification({
      type: 'error',
      message: t('pdf.download_failed'),
    })
  } finally {
    isDownloadingReceipt.value = false
  }
}

/**
 * Open the receipt in the browser's own viewer (a new tab), or the system
 * viewer on a device, rather than saving it first.
 */
async function viewReceipt(): Promise<void> {
  if (!isEdit.value) {
    return
  }

  isOpeningReceipt.value = true

  try {
    const fetched = await fetchDocumentBlob(
      `/reports/expenses/${route.params.id}/receipt`,
      {},
      expenseStore.currentExpense.receiptFiles[0]?.name ?? 'receipt',
    )

    await openDocument(fetched.blob, fetched.filename)
  } catch {
    notificationStore.showNotification({
      type: 'error',
      message: t('expenses.receipt_unavailable'),
    })
  } finally {
    isOpeningReceipt.value = false
  }
}

// Initialize
expenseStore.resetCurrentExpenseData()
loadData()

function onFileInputChange(_fileName: string, file: File): void {
  expenseStore.currentExpense.attachment_receipt = file
}

function onFileInputRemove(): void {
  expenseStore.currentExpense.attachment_receipt = null
  isAttachmentReceiptRemoved.value = true
}

function onCurrencyChange(currencyId: number): void {
  const found = globalStore.currencies.find(
    (c: Currency) => c.id === currencyId,
  )
  expenseStore.currentExpense.selectedCurrency = found ?? null
}

/**
 * Switch how the amount is typed. Choosing a tax treatment on an expense
 * without taxes raises the default tax; choosing none clears them.
 */
function applyTreatment(next: ExpenseTaxTreatment): void {
  taxTreatment.value = next

  if (next === 'none') {
    expenseStore.currentExpense.taxes = []
    expenseStore.currentExpense.tax_included = true

    return
  }

  const included = next === 'included'
  expenseStore.currentExpense.tax_included = included

  if (!expenseStore.currentExpense.taxes.length && defaultTaxType.value) {
    expenseStore.currentExpense.taxes = [
      expenseTaxFromType(defaultTaxType.value, enteredAmount.value, included),
    ]
  }
}

function onTreatmentChange(next: ExpenseTaxTreatment): void {
  isTreatmentTouched.value = true
  applyTreatment(next)
}

async function fetchTaxTypes(): Promise<void> {
  try {
    const response = await taxTypeService.list({
      limit: 'all',
      transaction_type: 'purchases',
    })
    taxTypes.value = response.data
  } catch {
    taxTypes.value = []
  }
}

async function onTaxTypeCreated(): Promise<void> {
  await fetchTaxTypes()
}

// Categories seen in the search, so a choice can hand over its defaults
const knownCategories = new Map<number, ExpenseCategory>()

// A category added from the select, kept in its options until they include it
const createdCategory = ref<ExpenseCategory | null>(null)
const categoryReloadKey = ref<number>(0)

async function searchCategory(search: string): Promise<ExpenseCategory[]> {
  const { expenseService } =
    await import('../../../../api/services/expense.service')
  const res = await expenseService.listCategories({ search })
  const categories = res.data ?? []

  if (
    createdCategory.value &&
    !categories.some((c) => c.id === createdCategory.value?.id)
  ) {
    categories.unshift(createdCategory.value)
  }

  categories.forEach((category) => knownCategories.set(category.id, category))

  return categories
}

/**
 * A new expense starts with its category's tax treatment and deductible
 * answer, unless the user has already chosen.
 */
function onCategoryChange(categoryId: number | null): void {
  const category = categoryId ? knownCategories.get(categoryId) : undefined

  if (isEdit.value || !category) {
    return
  }

  if (!isTreatmentTouched.value && isTaxTreatment(category.default_tax_treatment)) {
    applyTreatment(category.default_tax_treatment)
  }

  if (!isDeductibleTouched.value && category.tax_deductible !== undefined) {
    expenseStore.currentExpense.tax_deductible = category.tax_deductible
  }
}

function addCategory(): void {
  modalStore.openModal({
    title: t('settings.expense_category.add_category'),
    componentName: 'CategoryModal',
    size: 'sm',
    refreshData: (category: unknown) => {
      const saved = category as ExpenseCategory | undefined

      if (saved?.id) {
        createdCategory.value = saved
        knownCategories.set(saved.id, saved)
        categoryReloadKey.value++
        expenseStore.currentExpense.expense_category_id = saved.id
        onCategoryChange(saved.id)
      }
    },
  })
}

async function loadData(): Promise<void> {
  await globalStore.fetchCurrencies()

  const companyCurrency = companyStore.selectedCompanyCurrency
  if (!isEdit.value && companyCurrency) {
    expenseStore.currentExpense.currency_id = companyCurrency.id
    expenseStore.currentExpense.selectedCurrency = companyCurrency
  }

  isFetchingInitialData.value = true
  await Promise.all([
    expenseStore.fetchPaymentModes({ limit: 'all' }),
    fetchTaxTypes(),
  ])

  if (isEdit.value) {
    await expenseStore.fetchExpense(Number(route.params.id))
    if (expenseStore.currentExpense.selectedCurrency) {
      expenseStore.currentExpense.currency_id =
        expenseStore.currentExpense.selectedCurrency.id
    }

    const expense = expenseStore.currentExpense
    taxTreatment.value = treatmentOf(expense.tax_included, expense.taxes)
    enteredAmount.value = enteredFromGross(
      expense.amount,
      expense.taxes,
      taxTreatment.value,
    )
  } else {
    // A new expense is dated today, like a new invoice or payment
    expenseStore.currentExpense.expense_date ||= formatDate(new Date())

    if (route.query.customer) {
      expenseStore.currentExpense.customer_id = Number(route.query.customer)
    }

    applyTreatment(defaultTaxType.value ? 'included' : 'none')
  }

  isFetchingInitialData.value = false
}

async function submitForm(): Promise<void> {
  isSaving.value = true

  const taxes =
    taxTreatment.value === 'none' ? [] : expenseStore.currentExpense.taxes

  const formData: Record<string, unknown> = {
    ...expenseStore.currentExpense,
    expense_number: expenseStore.currentExpense.expense_number || '',
    amount: grossFromEntered(enteredAmount.value, taxes, taxTreatment.value),
    tax_included: taxTreatment.value !== 'excluded',
    // Sent even when empty, so clearing the note clears it
    deduction_note: expenseStore.currentExpense.deduction_note ?? '',
    taxes: taxes.map((tax) => ({
      tax_type_id: tax.tax_type_id,
      amount: tax.amount,
    })),
  }

  try {
    let savedId: number

    if (isEdit.value) {
      const response = await expenseStore.updateExpense({
        id: Number(route.params.id),
        data: formData,
        isAttachmentReceiptRemoved: isAttachmentReceiptRemoved.value,
      })
      savedId = response.data.data.id
    } else {
      const response = await expenseStore.addExpense(formData)
      savedId = response.data.data.id
    }
    isSaving.value = false
    expenseStore.currentExpense.attachment_receipt = null
    isAttachmentReceiptRemoved.value = false
    router.push(`/admin/expenses/${savedId}/view`)
  } catch (error) {
    isSaving.value = false

    // Say why it did not save instead of leaving the form as it was
    const normalized = handleApiError(error)
    const translationKey = getErrorTranslationKey(normalized.message)
    notificationStore.showNotification({
      type: 'error',
      message: translationKey ? t(translationKey) : normalized.message,
    })
  }
}

onBeforeUnmount(() => {
  expenseStore.resetCurrentExpenseData()
})
</script>
