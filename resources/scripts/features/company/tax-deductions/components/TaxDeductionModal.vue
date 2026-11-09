<template>
  <BaseModal :show="show" :closable="!isSaving" size="md" @close="close">
    <template #header>
      {{ deduction ? $t('tax_deductions.edit') : $t('tax_deductions.add') }}
    </template>

    <form @submit.prevent="save">
      <p v-if="error" role="alert" class="px-6 pt-4 text-sm text-danger">{{ error }}</p>

      <div class="p-6">
        <BaseInputGrid>
          <BaseInputGroup
            :label="$t('tax_deductions.date')"
            :error="errors.deduction_date?.[0]"
            required
          >
            <BaseDatePicker v-model="form.deduction_date" :calendar-button="true" />
          </BaseInputGroup>

          <BaseInputGroup
            :label="$t('tax_deductions.category')"
            :error="errors.category?.[0]"
            required
          >
            <BaseMultiselect
              v-model="form.category"
              :options="categoryOptions"
              value-prop="value"
              label="label"
              :can-deselect="false"
            />
          </BaseInputGroup>

          <BaseInputGroup
            :label="$t('tax_deductions.description_label')"
            :error="errors.description?.[0]"
            class="md:col-span-2"
            required
          >
            <BaseInput
              v-model="form.description"
              type="text"
              maxlength="255"
              :placeholder="$t('tax_deductions.description_placeholder')"
            />
          </BaseInputGroup>

          <BaseInputGroup
            :label="$t('tax_deductions.amount')"
            :error="errors.amount?.[0]"
            required
          >
            <BaseMoney v-model="amountData" :currency="currency" />
          </BaseInputGroup>

          <BaseInputGroup
            :label="$t('tax_deductions.notes')"
            :error="errors.notes?.[0]"
            class="md:col-span-2"
          >
            <BaseTextarea
              v-model="form.notes"
              rows="3"
              :placeholder="$t('tax_deductions.notes_placeholder')"
            />
          </BaseInputGroup>
        </BaseInputGrid>
      </div>

      <div class="flex justify-end gap-3 border-t border-line-default p-4">
        <BaseButton type="button" variant="primary-outline" @click="close">
          {{ $t('general.cancel') }}
        </BaseButton>
        <BaseButton type="submit" variant="primary" :loading="isSaving" :disabled="isSaving">
          {{ deduction ? $t('general.update') : $t('general.save') }}
        </BaseButton>
      </div>
    </form>
  </BaseModal>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { taxDeductionService } from '@/scripts/api/services/tax-deduction.service'
import { useNotificationStore } from '@/scripts/stores/notification.store'
import { handleApiError } from '@/scripts/utils/error-handling'
import { formatDate } from '@/scripts/utils/format-date'
import type { Currency } from '@/scripts/types/domain/currency'
import {
  TAX_DEDUCTION_CATEGORIES,
  type TaxDeduction,
  type TaxDeductionCategory,
} from '@/scripts/types/domain/tax-deduction'

/**
 * Add or change a deduction recorded by hand.
 */
interface Props {
  show: boolean
  /** The deduction to change; none to add one */
  deduction?: TaxDeduction | null
  /** Where a new deduction is dated, such as the last day of the year shown */
  defaultDate?: string | null
  currency: Currency | null
}

const props = withDefaults(defineProps<Props>(), {
  deduction: null,
  defaultDate: null,
})

const emit = defineEmits<{
  (event: 'close'): void
  (event: 'saved', deduction: TaxDeduction): void
}>()

const { t } = useI18n()
const notificationStore = useNotificationStore()

const isSaving = ref<boolean>(false)
const error = ref<string>('')
const errors = ref<Record<string, string[]>>({})

const form = reactive<{
  deduction_date: string
  category: TaxDeductionCategory
  description: string
  amount: number
  notes: string
}>({
  deduction_date: '',
  category: 'home_office',
  description: '',
  amount: 0,
  notes: '',
})

const amountData = computed<number>({
  get: () => form.amount / 100,
  set: (value: number) => {
    form.amount = Math.round(value * 100)
  },
})

const categoryOptions = computed(() =>
  TAX_DEDUCTION_CATEGORIES.map((category) => ({
    value: category,
    label: t(`tax_deductions.categories.${category}`),
  })),
)

watch(
  () => props.show,
  (show) => {
    if (!show) {
      return
    }

    error.value = ''
    errors.value = {}
    form.deduction_date = props.deduction?.deduction_date ?? props.defaultDate ?? formatDate(new Date())
    form.category = props.deduction?.category ?? 'home_office'
    form.description = props.deduction?.description ?? ''
    form.amount = props.deduction?.amount ?? 0
    form.notes = props.deduction?.notes ?? ''
  },
  { immediate: true },
)

function close(): void {
  if (!isSaving.value) {
    emit('close')
  }
}

async function save(): Promise<void> {
  isSaving.value = true
  error.value = ''
  errors.value = {}

  const payload = { ...form, notes: form.notes || null }

  try {
    const saved = props.deduction
      ? await taxDeductionService.update(props.deduction.id, payload)
      : await taxDeductionService.create(payload)

    notificationStore.showNotification({
      type: 'success',
      message: props.deduction ? 'tax_deductions.updated_message' : 'tax_deductions.created_message',
    })
    emit('saved', saved)
  } catch (e) {
    const normalized = handleApiError(e)
    errors.value = normalized.validationErrors
    error.value = normalized.message
  } finally {
    isSaving.value = false
  }
}
</script>
