<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { required, minLength, maxLength, helpers } from '@vuelidate/validators'
import useVuelidate from '@vuelidate/core'
import { useLookupDialog } from '@/scripts/composables/use-lookup-dialog'
import { useNotificationStore } from '@/scripts/stores/notification.store'
import { expenseService } from '@/scripts/api/services/expense.service'
import type {
  ExpenseCategory,
  ExpenseTaxTreatment,
} from '@/scripts/types/domain/expense'

interface CategoryForm {
  id: number | null
  name: string
  description: string
  /** Whether a new expense under this category starts as deductible */
  tax_deductible: boolean
  /** How a new expense's amount starts out; '' follows the company default */
  default_tax_treatment: ExpenseTaxTreatment | ''
}

const props = withDefaults(
  defineProps<{
    show?: boolean
    title?: string
    data?: unknown
    lockTransactionType?: boolean
  }>(),
  { show: undefined, title: '', data: undefined, lockTransactionType: false },
)
const emit = defineEmits<{ close: []; saved: [record: unknown] }>()
const dialog = useLookupDialog(props, 'CategoryModal', {
  close: () => emit('close'),
  saved: (record) => emit('saved', record),
})
const notificationStore = useNotificationStore()
const { t } = useI18n()

const isSaving = ref<boolean>(false)
const isEdit = ref<boolean>(false)

const currentCategory = ref<CategoryForm>(emptyCategory())

const treatmentOptions = computed<
  Array<{ value: ExpenseTaxTreatment | ''; label: string }>
>(() => {
  const tax = t('expenses.tax')

  return [
    { value: '', label: t('settings.expense_category.treatment_company_default') },
    { value: 'included', label: t('expenses.tax_treatment_included', { tax }) },
    { value: 'excluded', label: t('expenses.tax_treatment_excluded', { tax }) },
    { value: 'none', label: t('expenses.tax_treatment_none', { tax }) },
  ]
})

function emptyCategory(): CategoryForm {
  return {
    id: null,
    name: '',
    description: '',
    tax_deductible: true,
    default_tax_treatment: '',
  }
}

function payload() {
  return {
    name: currentCategory.value.name,
    description: currentCategory.value.description || null,
    tax_deductible: currentCategory.value.tax_deductible,
    default_tax_treatment: currentCategory.value.default_tax_treatment || null,
  }
}

const modalActive = dialog.active

const rules = computed(() => ({
  name: {
    required: helpers.withMessage(t('validation.required'), required),
    minLength: helpers.withMessage(
      t('validation.name_min_length', { count: 3 }),
      minLength(3),
    ),
  },
  description: {
    maxLength: helpers.withMessage(
      t('validation.description_maxlength', { count: 255 }),
      maxLength(255),
    ),
  },
}))

const v$ = useVuelidate(rules, currentCategory)

async function setInitialData(): Promise<void> {
  dialog.clearErrors()
  v$.value.$reset()
  if (dialog.data.value && typeof dialog.data.value === 'number') {
    isEdit.value = true
    const response = await expenseService.getCategory(dialog.data.value)
    if (response.data) {
      currentCategory.value = {
        id: response.data.id,
        name: response.data.name,
        description: response.data.description ?? '',
        tax_deductible: response.data.tax_deductible ?? true,
        default_tax_treatment: response.data.default_tax_treatment ?? '',
      }
    }
  } else {
    isEdit.value = false
    resetForm()
  }
}

async function submitCategoryData(): Promise<void> {
  v$.value.$touch()
  if (v$.value.$invalid) {
    return
  }

  isSaving.value = true
  dialog.clearErrors()
  let created: ExpenseCategory | null = null

  try {
    if (isEdit.value && currentCategory.value.id) {
      await expenseService.updateCategory(currentCategory.value.id, payload())
      notificationStore.showNotification({
        type: 'success',
        message: 'settings.expense_category.updated_message',
      })
    } else {
      const response = await expenseService.createCategory(payload())
      created = response.data
      notificationStore.showNotification({
        type: 'success',
        message: 'settings.expense_category.created_message',
      })
    }

    isSaving.value = false
    // A new category goes back to whoever opened the modal, to select it
    dialog.saved(created || undefined)
    closeCategoryModal()
  } catch (error) {
    dialog.fail(error)
    isSaving.value = false
  }
}

function resetForm(): void {
  currentCategory.value = emptyCategory()
}

function closeCategoryModal(): void {
  if (!isSaving.value) dialog.close()
}
</script>

<template>
  <BaseModal
    :show="modalActive"
    :closable="!isSaving"
    @close="closeCategoryModal"
    @open="setInitialData"
  >
    <template #header>
      {{ dialog.title.value }}
    </template>

    <form action="" @submit.stop.prevent="submitCategoryData">
      <p
        v-if="dialog.error.value"
        role="alert"
        class="px-6 pt-4 text-sm text-danger"
      >
        {{ dialog.error.value }}
      </p>
      <div class="p-8 sm:p-6">
        <BaseInputGrid layout="one-column">
          <BaseInputGroup
            :label="$t('expenses.category')"
            :error="
              dialog.errors.value.name?.[0] ||
              (v$.name.$error && v$.name.$errors[0].$message)
            "
            required
          >
            <BaseInput
              v-model="currentCategory.name"
              :invalid="v$.name.$error || !!dialog.errors.value.name"
              type="text"
              @input="v$.name.$touch()"
            />
          </BaseInputGroup>

          <BaseInputGroup
            :label="$t('expenses.description')"
            :error="v$.description.$error && v$.description.$errors[0].$message"
          >
            <BaseTextarea
              v-model="currentCategory.description"
              rows="4"
              cols="50"
              @input="v$.description.$touch()"
            />
          </BaseInputGroup>

          <!-- Starting values for a new expense filed under this category -->
          <BaseInputGroup
            :label="$t('settings.expense_category.default_tax_treatment')"
            :help-text="$t('settings.expense_category.default_tax_treatment_help')"
          >
            <BaseMultiselect
              v-model="currentCategory.default_tax_treatment"
              :options="treatmentOptions"
              value-prop="value"
              label="label"
              :can-deselect="false"
            />
          </BaseInputGroup>

          <BaseInputGroup :label="$t('expenses.tax_deductible')">
            <BaseSwitch
              v-model="currentCategory.tax_deductible"
              :label-right="$t('settings.expense_category.tax_deductible_help')"
            />
          </BaseInputGroup>
        </BaseInputGrid>
      </div>

      <div
        class="z-0 flex justify-end p-4 border-t border-line-default border-solid"
      >
        <BaseButton
          type="button"
          variant="primary-outline"
          class="me-3 text-sm"
          @click="closeCategoryModal"
        >
          {{ $t('general.cancel') }}
        </BaseButton>

        <BaseButton
          :loading="isSaving"
          :disabled="isSaving"
          variant="primary"
          type="submit"
        >
          <template #left="slotProps">
            <BaseIcon
              v-if="!isSaving"
              name="ArrowDownOnSquareIcon"
              :class="slotProps.class"
            />
          </template>
          {{ isEdit ? $t('general.update') : $t('general.save') }}
        </BaseButton>
      </div>
    </form>
  </BaseModal>
</template>
