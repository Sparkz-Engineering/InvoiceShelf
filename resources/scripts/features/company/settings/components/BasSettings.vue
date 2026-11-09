<template>
  <div>
    <BaseSwitchSection
      v-model="enabled"
      :title="$t('settings.bas.title')"
      :description="$t('settings.bas.description')"
    />

    <form
      v-if="enabled"
      class="mt-4 space-y-4"
      @submit.prevent="save"
    >
      <BaseInfoAlert
        v-if="!isJulyToJune"
        :title="$t('settings.bas.financial_year_title')"
        :lists="[$t('settings.bas.financial_year_hint')]"
      />

      <div class="grid gap-4 md:grid-cols-2">
        <BaseInputGroup
          :label="$t('settings.bas.basis')"
          :help-text="$t('settings.bas.basis_help')"
        >
          <BaseMultiselect
            v-model="form.bas_gst_basis"
            :options="basisOptions"
            value-prop="value"
            label="label"
            :can-deselect="false"
          />
        </BaseInputGroup>

        <BaseInputGroup
          :label="$t('settings.bas.payg_method')"
          :help-text="$t('settings.bas.payg_method_help')"
        >
          <BaseMultiselect
            v-model="form.bas_payg_method"
            :options="paygOptions"
            value-prop="value"
            label="label"
            :can-deselect="false"
          />
        </BaseInputGroup>

        <BaseInputGroup
          v-if="form.bas_payg_method === 'amount'"
          :label="$t('reports.bas.payg_amount')"
          :help-text="$t('reports.bas.payg_amount_help')"
        >
          <BaseMoney
            v-model="paygAmountData"
            :currency="companyStore.selectedCompanyCurrency"
          />
        </BaseInputGroup>

        <BaseInputGroup
          v-if="form.bas_payg_method === 'rate'"
          :label="$t('reports.bas.payg_rate')"
          :help-text="$t('reports.bas.payg_rate_help')"
        >
          <BaseInput
            v-model="form.bas_payg_rate"
            type="number"
            min="0"
            max="100"
            step="0.01"
          />
        </BaseInputGroup>
      </div>

      <BaseButton type="submit" variant="primary-outline" :loading="isSaving">
        {{ $t('general.save') }}
      </BaseButton>
    </form>
  </div>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useCompanyStore } from '@/scripts/stores/company.store'

/**
 * The company's Australian BAS settings: whether the BAS worksheet appears
 * in the reports, how GST is counted, and how the PAYG instalment is worked
 * out. The worksheet can still change the PAYG figures for one statement.
 */
const { t } = useI18n()
const companyStore = useCompanyStore()
const settings = companyStore.selectedCompanySettings ?? {}

const isSaving = ref<boolean>(false)

const form = reactive({
  bas_gst_basis: settings.bas_gst_basis === 'accrual' ? 'accrual' : 'cash',
  bas_payg_method: ['amount', 'rate'].includes(settings.bas_payg_method)
    ? settings.bas_payg_method
    : 'none',
  bas_payg_amount: Number(settings.bas_payg_amount) || 0,
  bas_payg_rate: Number(settings.bas_payg_rate) || 0,
})

const isJulyToJune = computed<boolean>(
  () => companyStore.selectedCompanySettings?.fiscal_year === '7-6',
)

const enabled = computed<boolean>({
  get: () => companyStore.selectedCompanySettings?.bas_enabled === 'YES',
  set: async (value: boolean) => {
    await companyStore.updateCompanySettings({
      data: { settings: { bas_enabled: value ? 'YES' : 'NO' } },
      message: 'general.setting_updated',
    })
  },
})

const paygAmountData = computed<number>({
  get: () => form.bas_payg_amount / 100,
  set: (value: number) => {
    form.bas_payg_amount = Math.round(value * 100)
  },
})

const basisOptions = computed(() => [
  { value: 'cash', label: t('reports.bas.basis_cash') },
  { value: 'accrual', label: t('reports.bas.basis_accrual') },
])

const paygOptions = computed(() => [
  { value: 'none', label: t('reports.bas.payg_none') },
  { value: 'amount', label: t('reports.bas.payg_by_amount') },
  { value: 'rate', label: t('reports.bas.payg_by_rate') },
])

async function save(): Promise<void> {
  isSaving.value = true

  try {
    await companyStore.updateCompanySettings({
      data: {
        settings: {
          bas_gst_basis: form.bas_gst_basis,
          bas_payg_method: form.bas_payg_method,
          bas_payg_amount: String(form.bas_payg_amount),
          bas_payg_rate: String(Number(form.bas_payg_rate) || 0),
        },
      },
      message: 'general.setting_updated',
    })
  } finally {
    isSaving.value = false
  }
}
</script>
