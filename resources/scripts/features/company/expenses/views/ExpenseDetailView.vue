<template>
  <BasePage>
    <BasePageHeader
      :help="$t('page_help.expenses')"
      phone-actions="bar"
      :title="title"
      :subtitle="expense?.supplier?.name || expense?.formatted_expense_date || ''"
    >
      <BaseBreadcrumb>
        <BaseBreadcrumbItem :title="$t('general.home')" to="/admin/dashboard" />
        <BaseBreadcrumbItem
          :title="$t('expenses.expense', 2)"
          to="/admin/expenses"
        />
        <BaseBreadcrumbItem :title="title" to="#" active />
      </BaseBreadcrumb>

      <template v-if="expense && !isLoading" #actions>
        <BaseButton
          v-if="canEdit"
          variant="primary"
          @click="router.push(`/admin/expenses/${expense.id}/edit`)"
        >
          <template #left="slotProps">
            <BaseIcon name="PencilSquareIcon" :class="slotProps.class" />
          </template>
          {{ $t('general.edit') }}
        </BaseButton>

        <BaseDropdown
          v-if="canCredit || canDelete"
          position="bottom-end"
          width-class="w-56"
        >
          <template #activator>
            <span
              class="inline-flex h-11 items-center justify-center gap-2 rounded-lg border border-line-default bg-surface px-3 text-sm font-medium text-body hover:bg-hover md:h-9"
            >
              <BaseIcon name="EllipsisHorizontalIcon" class="h-5 w-5" />
              <span>{{ $t('navigation.more') }}</span>
            </span>
          </template>
          <BaseDropdownItem
            v-if="canCredit"
            :to="{
              path: '/admin/supplier-credits/create',
              query: { expense_id: expense.id },
            }"
          >
            {{ $t('purchases.new_credit') }}
          </BaseDropdownItem>
          <BaseDropdownItem v-if="canDelete" @click="removeExpense">
            <BaseIcon name="TrashIcon" class="me-3 h-5 w-5 text-danger" />
            {{ $t('general.delete') }}
          </BaseDropdownItem>
        </BaseDropdown>
      </template>
    </BasePageHeader>

    <p v-if="loadError" role="alert" class="text-sm text-danger">
      {{ $t('expenses.load_failed') }}
    </p>

    <BaseContentPlaceholders v-if="isLoading">
      <BaseContentPlaceholdersBox :rounded="true" class="h-48 w-full" />
    </BaseContentPlaceholders>

    <template v-else-if="expense">
      <BaseStatStrip :columns="expense.tax_deductible ? 4 : 3">
        <BaseStat :label="$t('expenses.total_paid')" emphasis>
          <BaseFormatMoney :amount="expense.amount" :currency="currency" />
        </BaseStat>
        <BaseStat :label="taxName">
          <BaseFormatMoney :amount="expense.tax_total" :currency="currency" />
        </BaseStat>
        <BaseStat
          :label="$t('expenses.amount_excluding_tax', { tax: taxName })"
        >
          <BaseFormatMoney :amount="expense.net_amount" :currency="currency" />
        </BaseStat>
        <BaseStat
          v-if="expense.tax_deductible"
          :label="$t('expenses.deductible_amount')"
        >
          <BaseFormatMoney
            :amount="expense.deductible_amount"
            :currency="currency"
          />
        </BaseStat>
      </BaseStatStrip>

      <div class="grid items-start gap-5 xl:grid-cols-3">
        <div class="space-y-5 xl:col-span-2">
          <BaseCard container-class="p-4 md:p-5">
            <dl class="grid gap-5 text-sm sm:grid-cols-2">
              <div>
                <dt class="text-muted">{{ $t('expenses.expense_date') }}</dt>
                <dd class="mt-1 text-heading">
                  {{ expense.formatted_expense_date }}
                </dd>
              </div>
              <div v-if="expense.expense_number">
                <dt class="text-muted">{{ $t('expenses.expense_number') }}</dt>
                <dd class="mt-1 break-words text-heading">
                  {{ expense.expense_number }}
                </dd>
              </div>
              <div v-if="expense.expense_category">
                <dt class="text-muted">{{ $t('expenses.category') }}</dt>
                <dd class="mt-1 text-heading">
                  {{ expense.expense_category.name }}
                </dd>
              </div>
              <div v-if="expense.supplier">
                <dt class="text-muted">{{ $t('purchases.supplier') }}</dt>
                <dd class="mt-1">
                  <router-link
                    :to="`/admin/suppliers/${expense.supplier.id}/view`"
                    class="font-medium text-primary-600"
                    >{{ expense.supplier.name }}</router-link
                  >
                  <span
                    v-if="expense.supplier.tax_id"
                    class="block text-xs text-muted"
                    >{{ expense.supplier.tax_id }}</span
                  >
                </dd>
              </div>
              <div v-if="expense.customer">
                <dt class="text-muted">{{ $t('expenses.customer') }}</dt>
                <dd class="mt-1">
                  <router-link
                    :to="`/admin/customers/${expense.customer.id}/view`"
                    class="font-medium text-primary-600"
                    >{{ expense.customer.name }}</router-link
                  >
                </dd>
              </div>
              <div v-if="expense.payment_method">
                <dt class="text-muted">{{ $t('payments.payment_mode') }}</dt>
                <dd class="mt-1 text-heading">
                  {{ expense.payment_method.name }}
                </dd>
              </div>
              <div v-if="isForeignCurrency">
                <dt class="text-muted">{{ $t('expenses.in_company_currency') }}</dt>
                <dd class="mt-1 text-heading">
                  <BaseFormatMoney
                    :amount="expense.base_amount"
                    :currency="companyStore.selectedCompanyCurrency"
                  />
                  <span class="block text-xs text-muted">
                    {{ $t('expenses.exchange_rate_value', { rate: expense.exchange_rate }) }}
                  </span>
                </dd>
              </div>
            </dl>

            <!-- Every expense field is listed, answered or not, so a field
                 added after the expense was recorded still shows up -->
            <dl
              v-if="customFields.length"
              class="mt-5 grid gap-5 border-t border-line-light pt-5 text-sm sm:grid-cols-2"
            >
              <div v-for="field in customFields" :key="field.id">
                <dt class="text-muted">{{ field.label }}</dt>
                <dd
                  class="mt-1 break-words whitespace-pre-wrap"
                  :class="field.answer === null ? 'text-subtle' : 'text-heading'"
                >
                  {{ field.answer ?? '—' }}
                </dd>
              </div>
            </dl>

            <div v-if="expense.notes" class="mt-5">
              <p class="text-sm text-muted">{{ $t('expenses.note') }}</p>
              <p class="mt-1 whitespace-pre-wrap text-sm text-body">
                {{ expense.notes }}
              </p>
            </div>
          </BaseCard>

          <ExpenseReceiptPreview
            v-if="expense.attachment_receipt_meta"
            :expense-id="expense.id"
            :meta="expense.attachment_receipt_meta"
          />
          <BaseCard v-else container-class="p-4 md:p-5">
            <div class="flex items-center gap-3 text-sm text-muted">
              <BaseIcon name="PaperClipIcon" class="h-5 w-5 text-subtle" />
              <span>{{ $t('expenses.no_receipt') }}</span>
              <router-link
                v-if="canEdit"
                :to="`/admin/expenses/${expense.id}/edit`"
                class="font-medium text-primary-600"
                >{{ $t('expenses.attach_receipt') }}</router-link
              >
            </div>
          </BaseCard>
        </div>

        <div class="space-y-5">
          <!-- What the expense means for tax -->
          <BaseCard container-class="p-4 md:p-5">
            <h2 class="mb-4 text-base font-semibold text-heading">
              {{ $t('expenses.tax_and_deductions') }}
            </h2>

            <dl class="space-y-3 text-sm">
              <div class="flex justify-between gap-3">
                <dt class="text-muted">{{ $t('expenses.tax_treatment') }}</dt>
                <dd class="text-end text-heading">{{ treatmentLabel }}</dd>
              </div>
              <div
                v-for="tax in expense.taxes ?? []"
                :key="tax.id ?? tax.tax_type_id"
                class="flex justify-between gap-3"
              >
                <dt class="text-muted">
                  {{ tax.name }}
                  <template v-if="tax.calculation_type !== 'fixed' && tax.percent !== null">
                    ({{ tax.percent }}%)
                  </template>
                </dt>
                <dd class="text-heading">
                  <BaseFormatMoney :amount="tax.amount" :currency="currency" />
                </dd>
              </div>
              <div class="flex justify-between gap-3">
                <dt class="text-muted">{{ $t('expenses.business_use_percent') }}</dt>
                <dd class="text-heading">{{ expense.business_use_percent }}%</dd>
              </div>
              <div
                v-if="expense.tax_total"
                class="flex justify-between gap-3"
              >
                <dt class="text-muted">
                  {{ $t('expenses.claimable_tax', { tax: taxName }) }}
                </dt>
                <dd class="font-medium text-heading">
                  <BaseFormatMoney
                    :amount="expense.claimable_tax"
                    :currency="currency"
                  />
                </dd>
              </div>
              <div class="flex justify-between gap-3">
                <dt class="text-muted">{{ $t('expenses.tax_deductible') }}</dt>
                <dd class="text-heading">
                  {{ expense.tax_deductible ? $t('general.yes') : $t('general.no') }}
                </dd>
              </div>
              <div
                v-if="expense.tax_deductible"
                class="flex justify-between gap-3"
              >
                <dt class="text-muted">{{ $t('expenses.deductible_amount') }}</dt>
                <dd class="font-medium text-heading">
                  <BaseFormatMoney
                    :amount="expense.deductible_amount"
                    :currency="currency"
                  />
                </dd>
              </div>
            </dl>

            <div
              v-if="expense.deduction_note"
              class="mt-4 border-t border-line-light pt-4"
            >
              <p class="text-xs font-medium text-muted">
                {{ $t('expenses.deduction_note') }}
              </p>
              <p class="mt-1 whitespace-pre-wrap text-sm text-body">
                {{ expense.deduction_note }}
              </p>
            </div>
          </BaseCard>

          <BaseCard container-class="p-4 md:p-5">
            <dl class="space-y-3 text-sm">
              <div v-if="expense.creator" class="flex justify-between gap-3">
                <dt class="text-muted">{{ $t('expenses.recorded_by') }}</dt>
                <dd class="text-end text-heading">{{ expense.creator.name }}</dd>
              </div>
              <div class="flex justify-between gap-3">
                <dt class="text-muted">{{ $t('expenses.recorded_on') }}</dt>
                <dd class="text-end text-heading">
                  {{ expense.formatted_created_at }}
                </dd>
              </div>
            </dl>
          </BaseCard>
        </div>
      </div>
    </template>
  </BasePage>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { expenseService } from '@/scripts/api/services/expense.service'
import { useCompanyStore } from '@/scripts/stores/company.store'
import { useDialogStore } from '@/scripts/stores/dialog.store'
import { useUserStore } from '@/scripts/stores/user.store'
import { ABILITIES } from '@/scripts/config/abilities'
import { useCustomFieldDefinitions } from '@/scripts/features/shared/custom-fields/use-custom-fields'
import ExpenseReceiptPreview from '../components/ExpenseReceiptPreview.vue'
import { treatmentOf } from '../tax-treatment'
import type { Expense } from '@/scripts/types/domain/expense'
import type { CustomFieldValue } from '@/scripts/types/domain/custom-field'

/**
 * One expense, read-only: what was paid, the tax on it and what can be
 * claimed, with the receipt shown in place.
 */
const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const companyStore = useCompanyStore()
const dialogStore = useDialogStore()
const userStore = useUserStore()

const expense = ref<Expense | null>(null)
const isLoading = ref<boolean>(true)
const loadError = ref<boolean>(false)

const canEdit = computed<boolean>(() =>
  userStore.hasAbilities(ABILITIES.EDIT_EXPENSE),
)
const canDelete = computed<boolean>(() =>
  userStore.hasAbilities(ABILITIES.DELETE_EXPENSE),
)
const canCredit = computed<boolean>(
  () =>
    Boolean(expense.value?.supplier_id) &&
    userStore.hasAbilities('create-supplier-credit'),
)

const currency = computed(() => expense.value?.currency ?? null)

const definitions = useCustomFieldDefinitions('Expense')

/** Each expense custom field with this expense's answer, or null for none. */
const customFields = computed<Array<{ id: number; label: string; answer: string | null }>>(() =>
  definitions.value.map((definition) => {
    const saved = expense.value?.fields?.find((field) => field.custom_field_id === definition.id)

    return { id: definition.id, label: definition.label, answer: saved ? displayAnswer(saved) : null }
  }),
)

function displayAnswer(field: CustomFieldValue): string | null {
  const value = field.default_answer

  if (value === null || value === '') {
    return null
  }

  if (field.type === 'Switch') {
    return t(value === true || Number(value) === 1 ? 'general.yes' : 'general.no')
  }

  return String(field.default_formatted_answer ?? value)
}

const isForeignCurrency = computed<boolean>(
  () =>
    Boolean(expense.value) &&
    expense.value?.currency_id !== companyStore.selectedCompanyCurrency?.id,
)

const taxName = computed<string>(
  () => expense.value?.taxes?.[0]?.name ?? t('expenses.tax_column'),
)

const title = computed<string>(
  () =>
    expense.value?.expense_number ||
    expense.value?.expense_category?.name ||
    t('expenses.expense'),
)

const treatmentLabel = computed<string>(() => {
  if (!expense.value) {
    return ''
  }

  const treatment = treatmentOf(
    expense.value.tax_included,
    expense.value.taxes ?? [],
  )

  return t(`expenses.tax_treatment_${treatment}`, { tax: taxName.value })
})

async function load(): Promise<void> {
  isLoading.value = true
  loadError.value = false

  try {
    const response = await expenseService.get(Number(route.params.id))
    expense.value = response.data
  } catch {
    expense.value = null
    loadError.value = true
  } finally {
    isLoading.value = false
  }
}

function removeExpense(): void {
  if (!expense.value) {
    return
  }

  const id = expense.value.id

  dialogStore
    .openDialog({
      title: t('general.are_you_sure'),
      message: t('expenses.confirm_delete'),
      yesLabel: t('general.ok'),
      noLabel: t('general.cancel'),
      variant: 'danger',
      hideNoButton: false,
      size: 'lg',
    })
    .then(async (confirmed: boolean) => {
      if (confirmed) {
        await expenseService.delete({ ids: [id] })
        router.push('/admin/expenses')
      }
    })
}

// Moving between expenses reuses the page; leaving it must not load nothing
watch(
  () => route.params.id,
  (id) => {
    if (id && route.name === 'expenses.view') {
      void load()
    }
  },
  { immediate: true },
)
</script>
