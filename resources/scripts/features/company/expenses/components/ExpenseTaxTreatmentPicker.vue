<template>
  <!-- Real radio inputs, so a screen reader and the keyboard treat the choice
       as the one-of-three it is -->
  <fieldset class="min-w-0">
    <legend class="mb-1.5 text-sm font-medium text-body">
      {{ $t('expenses.tax_treatment') }}
    </legend>

    <div
      class="grid grid-cols-3 gap-1 rounded-lg border border-line-default bg-surface-muted p-1"
    >
      <label
        v-for="option in options"
        :key="option.value"
        class="relative flex min-h-10 cursor-pointer items-center justify-center whitespace-nowrap rounded-md px-1.5 text-center text-sm font-medium transition-colors has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-focus"
        :class="
          modelValue === option.value
            ? 'bg-surface text-heading shadow-sm'
            : 'text-muted hover:text-heading'
        "
      >
        <input
          :name="name"
          type="radio"
          class="sr-only"
          :value="option.value"
          :checked="modelValue === option.value"
          :disabled="disabled"
          @change="emit('update:modelValue', option.value)"
        />
        {{ option.label }}
      </label>
    </div>
  </fieldset>
</template>

<script setup lang="ts">
import { computed, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ExpenseTaxTreatment } from '@/scripts/types/domain/expense'

interface Props {
  modelValue: ExpenseTaxTreatment
  /** The tax's own name, such as "GST", so the choices read naturally */
  taxName?: string | null
  disabled?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  taxName: null,
  disabled: false,
})

const emit = defineEmits<{
  (event: 'update:modelValue', value: ExpenseTaxTreatment): void
}>()

const { t } = useI18n()
const name = `tax-treatment-${useId()}`

const options = computed<Array<{ value: ExpenseTaxTreatment; label: string }>>(
  () => {
    const tax = props.taxName || t('expenses.tax')

    return [
      { value: 'included', label: t('expenses.tax_treatment_included_short', { tax }) },
      { value: 'excluded', label: t('expenses.tax_treatment_excluded_short', { tax }) },
      { value: 'none', label: t('expenses.tax_treatment_none_short', { tax }) },
    ]
  },
)
</script>
