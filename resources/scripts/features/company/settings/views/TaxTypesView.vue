<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useCompanyStore } from '../../../../stores/company.store'
import { useUserStore } from '../../../../stores/user.store'
import { useModalStore } from '../../../../stores/modal.store'
import { taxTypeService } from '../../../../api/services/tax-type.service'
import TaxTypeDropdown from '@/scripts/features/company/settings/components/TaxTypeDropdown.vue'
import TaxTypeModal from '@/scripts/features/company/settings/components/TaxTypeModal.vue'
import BasSettings from '@/scripts/features/company/settings/components/BasSettings.vue'
import type { TaxType } from '@/scripts/types/domain/tax'

interface TableColumn {
  key: string
  label?: string
  thClass?: string
  tdClass?: string
  sortable?: boolean
}

interface FetchParams {
  page: number
  filter: Record<string, unknown>
  sort: { fieldName: string; order: 'asc' | 'desc' }
}

interface FetchResult {
  data: unknown[]
  pagination: {
    totalPages: number
    currentPage: number
    totalCount: number
    limit: number
  }
}

const ABILITIES = {
  CREATE_TAX_TYPE: 'create-tax-type',
  DELETE_TAX_TYPE: 'delete-tax-type',
  EDIT_TAX_TYPE: 'edit-tax-type',
} as const

const { t } = useI18n()
const companyStore = useCompanyStore()
const modalStore = useModalStore()
const userStore = useUserStore()
const table = ref<{ refresh: () => void } | null>(null)
const taxPerItemSetting = ref<string>(companyStore.selectedCompanySettings.tax_per_item)

const defaultCurrency = computed(() => companyStore.selectedCompanyCurrency)

const taxTypeColumns = computed<TableColumn[]>(() => [
  {
    key: 'name',
    label: t('settings.tax_types.tax_name'),
    thClass: 'extra',
    tdClass: 'font-medium text-heading',
  },
  {
    key: 'calculation_type',
    label: t('settings.tax_types.calculation_type'),
    thClass: 'extra',
    tdClass: 'font-medium text-heading',
  },
  {
    key: 'amount',
    label: t('settings.tax_types.amount'),
    thClass: 'extra',
    tdClass: 'font-medium text-heading',
  },
  {
    key: 'transaction_type',
    label: t('tax_types.used_for'),
    thClass: 'extra',
    tdClass: 'font-medium text-heading',
  },
  {
    key: 'actions',
    label: '',
    tdClass: 'text-end text-sm font-medium',
    sortable: false,
  },
])

const salesTaxEnabled = computed<boolean>(() => {
  return companyStore.selectedCompanySettings.sales_tax_us_enabled === 'YES'
})

const taxPerItemField = computed<boolean>({
  get: () => taxPerItemSetting.value === 'YES',
  set: async (newValue: boolean) => {
    const value = newValue ? 'YES' : 'NO'
    taxPerItemSetting.value = value

    await companyStore.updateCompanySettings({
      data: { settings: { tax_per_item: value } },
      message: 'general.setting_updated',
    })
  },
})

const taxIncludedSettings = reactive<{ tax_included: string; tax_included_by_default: string }>({
  tax_included: companyStore.selectedCompanySettings.tax_included ?? 'NO',
  tax_included_by_default: companyStore.selectedCompanySettings.tax_included_by_default ?? 'NO',
})

const taxIncludedField = computed<boolean>({
  get: () => taxIncludedSettings.tax_included === 'YES',
  set: async (newValue: boolean) => {
    const value = newValue ? 'YES' : 'NO'
    taxIncludedSettings.tax_included = value

    if (!newValue) {
      taxIncludedSettings.tax_included_by_default = 'NO'
    }

    await companyStore.updateCompanySettings({
      data: { settings: { ...taxIncludedSettings } },
      message: 'general.setting_updated',
    })
  },
})

const taxIncludedByDefaultField = computed<boolean>({
  get: () => taxIncludedSettings.tax_included_by_default === 'YES',
  set: async (newValue: boolean) => {
    const value = newValue ? 'YES' : 'NO'
    taxIncludedSettings.tax_included_by_default = value

    await companyStore.updateCompanySettings({
      data: { settings: { tax_included_by_default: value } },
      message: 'general.setting_updated',
    })
  },
})

// The tax a new expense starts with, such as GST on a purchase
const purchaseTaxTypes = ref<TaxType[]>([])
const defaultPurchaseTaxTypeId = ref<number | null>(
  Number(companyStore.selectedCompanySettings.default_purchase_tax_type_id) || null,
)

async function fetchPurchaseTaxTypes(): Promise<void> {
  try {
    const response = await taxTypeService.list({
      limit: 'all',
      transaction_type: 'purchases',
    })
    purchaseTaxTypes.value = response.data
  } catch {
    purchaseTaxTypes.value = []
  }
}

async function saveDefaultPurchaseTax(id: number | null): Promise<void> {
  defaultPurchaseTaxTypeId.value = id

  await companyStore.updateCompanySettings({
    data: { settings: { default_purchase_tax_type_id: id ? String(id) : '' } },
    message: 'general.setting_updated',
  })
}

onMounted(() => {
  if (userStore.currentUser?.is_owner) {
    void fetchPurchaseTaxTypes()
  }
})

function hasAtleastOneAbility(): boolean {
  return userStore.hasAbilities([ABILITIES.DELETE_TAX_TYPE, ABILITIES.EDIT_TAX_TYPE])
}

async function fetchData({ page, sort }: FetchParams): Promise<FetchResult> {
  const data = {
    orderByField: sort.fieldName || 'created_at',
    orderBy: sort.order || 'desc',
    page,
  }

  const response = await taxTypeService.list(data)

  return {
    data: response.data,
    pagination: {
      totalPages: response.meta.last_page,
      currentPage: page,
      totalCount: response.meta.total,
      limit: 5,
    },
  }
}

function refreshTable(): void {
  table.value?.refresh()
  void fetchPurchaseTaxTypes()
}

function openTaxModal(): void {
  modalStore.openModal({
    title: t('settings.tax_types.add_tax'),
    componentName: 'TaxTypeModal',
    size: 'sm',
    data: { transaction_type: 'sales' },
    refreshData: refreshTable,
  })
}
</script>

<template>
  <BaseSettingCard
    :title="$t('settings.tax_types.title')"
    :description="$t('settings.tax_types.description')"
  >
    <TaxTypeModal />

    <template v-if="userStore.hasAbilities(ABILITIES.CREATE_TAX_TYPE)" #action>
      <BaseButton type="submit" variant="primary-outline" @click="openTaxModal">
        <template #left="slotProps">
          <BaseIcon :class="slotProps.class" name="PlusIcon" />
        </template>
        {{ $t('settings.tax_types.add_new_tax') }}
      </BaseButton>
    </template>

    <BaseTable
      ref="table"
      class="mt-16"
      :data="fetchData"
      :columns="taxTypeColumns"
    >
      <template #cell-calculation_type="{ row }">
        {{ $t(`settings.tax_types.${row.data.calculation_type}`) }}
        <BaseBadge v-if="row.data.compound_tax" class="ms-2">
          {{ $t('tax_types.compound_tax') }}
        </BaseBadge>
      </template>

      <template #cell-amount="{ row }">
        <template v-if="row.data.calculation_type === 'percentage'">
          {{ row.data.percent }} %
        </template>
        <template v-else-if="row.data.calculation_type === 'fixed'">
          <BaseFormatMoney :amount="row.data.fixed_amount" :currency="defaultCurrency" />
        </template>
        <template v-else> - </template>
      </template>

      <template #cell-transaction_type="{ row }">
        <BaseBadge>
          {{ $t(`tax_types.${row.data.transaction_type}`) }}
        </BaseBadge>
      </template>

      <template v-if="hasAtleastOneAbility()" #cell-actions="{ row }">
        <TaxTypeDropdown
          :row="row.data"
          :table="table"
          :load-data="refreshTable"
        />
      </template>
      <!-- Nothing here yet: say what goes here and offer to add the first -->
      <template #empty>
        <BaseEmptyPlaceholder
          compact
          art="tax"
          :ghost="4"
          :title="$t('settings.tax_types.empty_title')"
          :description="$t('settings.tax_types.empty_description')"
        >
          <template v-if="userStore.hasAbilities(ABILITIES.CREATE_TAX_TYPE)" #actions>
            <BaseButton variant="primary-outline" @click="openTaxModal">
              <template #left="slotProps">
                <BaseIcon name="PlusIcon" :class="slotProps.class" />
              </template>
              {{ $t('settings.tax_types.add_new_tax') }}
            </BaseButton>
          </template>
        </BaseEmptyPlaceholder>
      </template>
    </BaseTable>

    <div v-if="userStore.currentUser?.is_owner">
      <BaseDivider class="mt-8 mb-2" />

      <BaseSwitchSection
        v-model="taxPerItemField"
        :disabled="salesTaxEnabled"
        :title="$t('settings.tax_types.tax_per_item')"
        :description="$t('settings.tax_types.tax_setting_description')"
      />

      <BaseDivider class="mt-8 mb-2" />

      <BaseSwitchSection
        v-model="taxIncludedField"
        :title="$t('settings.tax_types.tax_included')"
        :description="$t('settings.tax_types.tax_included_description')"
      />

      <BaseSwitchSection
        v-if="taxIncludedField"
        v-model="taxIncludedByDefaultField"
        :title="$t('settings.tax_types.tax_included_by_default')"
        :description="$t('settings.tax_types.tax_included_by_default_description')"
      />

      <BaseDivider class="mt-8 mb-6" />

      <div class="grid gap-4 md:grid-cols-2 md:items-start">
        <div>
          <p id="default-purchase-tax-label" class="text-base font-medium text-heading">
            {{ $t('settings.tax_types.default_purchase_tax') }}
          </p>
          <p class="mt-1 text-sm text-muted">
            {{ $t('settings.tax_types.default_purchase_tax_description') }}
          </p>
        </div>
        <BaseMultiselect
          :model-value="defaultPurchaseTaxTypeId"
          :options="purchaseTaxTypes"
          value-prop="id"
          label="name"
          track-by="name"
          :placeholder="$t('settings.tax_types.no_default_purchase_tax')"
          aria-labelledby="default-purchase-tax-label"
          can-deselect
          @update:model-value="saveDefaultPurchaseTax"
        />
      </div>

      <BaseDivider class="mt-8 mb-2" />

      <BasSettings />
    </div>
  </BaseSettingCard>
</template>
