<script setup lang="ts">
import { computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useGlobalStore } from '@/scripts/stores/global.store'
import { useCompanyStore } from '@/scripts/stores/company.store'
import SalesReportView from './SalesReportView.vue'
import ProfitLossReportView from './ProfitLossReportView.vue'
import ExpensesReportView from './ExpensesReportView.vue'
import TaxReportView from './TaxReportView.vue'
import BasReportView from './BasReportView.vue'
import ProjectReportView from './ProjectReportView.vue'
import PurchaseReportView from '../../purchases/views/PurchaseReportView.vue'
const globalStore = useGlobalStore(),
  companyStore = useCompanyStore(),
  route = useRoute(),
  router = useRouter(),
  { t } = useI18n()

// The tabs in order, each with its title and help. The BAS tab is there only
// for a company that has switched it on, so everything else stays as it was.
const basEnabled = computed(
  () => companyStore.selectedCompanySettings?.bas_enabled === 'YES',
)
const reports = computed(() => [
  { key: 'sales', title: t('reports.sales.sales'), help: 'sales_report' },
  { key: 'profit-loss', title: t('reports.profit_loss.profit_loss'), help: 'profit_loss_report' },
  { key: 'expenses', title: t('reports.expenses.expenses'), help: 'expenses_report' },
  { key: 'purchases', title: t('reports.purchases'), help: 'purchases_report' },
  { key: 'taxes', title: t('reports.taxes.taxes'), help: 'tax_report' },
  { key: 'projects', title: t('reports.projects.title'), help: 'projects_report' },
  ...(basEnabled.value
    ? [{ key: 'bas', title: t('reports.bas.title'), help: 'bas_report' }]
    : []),
])
const keys = computed(() => reports.value.map((report) => report.key))
const titles = computed(() => reports.value.map((report) => report.title))
const selected = computed(() =>
  keys.value.includes(String(route.query.report))
    ? String(route.query.report)
    : 'sales',
)
const index = computed(() => keys.value.indexOf(selected.value))
const reportHelp = computed(() => reports.value[index.value]?.help)
function selectReport(tab: { title: string }) {
  const report = keys.value[titles.value.indexOf(tab.title)]
  if (report && report !== selected.value)
    router.push({ query: { ...route.query, report } })
}
watch(
  selected,
  () => {
    globalStore.downloadReport = null
  },
  { flush: 'sync' },
)
function onDownload() {
  globalStore.downloadReport?.()
}
</script>

<template>
  <BasePage>
    <BasePageHeader
      :help="$t(`page_help.${reportHelp}`)"
      :title="$t('reports.report', 2)"
    >
      <BaseBreadcrumb>
        <BaseBreadcrumbItem :title="$t('general.home')" to="/admin/dashboard" />
        <BaseBreadcrumbItem :title="$t('reports.report', 2)" to="#" active />
      </BaseBreadcrumb>

      <template #actions>
        <BaseButton
          variant="primary"
          class="ms-4"
          :disabled="!globalStore.downloadReport"
          @click="onDownload"
        >
          <template #left="slotProps">
            <BaseIcon name="ArrowDownTrayIcon" :class="slotProps.class" />
          </template>
          {{ $t('reports.download_pdf') }}
        </BaseButton>
      </template>
    </BasePageHeader>

    <BaseTabGroup
      :key="selected"
      :default-index="index"
      class="p-2"
      @change="selectReport"
    >
      <BaseTab
        :title="$t('reports.sales.sales')"
        tab-panel-container="px-0 py-0"
      >
        <SalesReportView v-if="selected === 'sales'" />
      </BaseTab>

      <BaseTab
        :title="$t('reports.profit_loss.profit_loss')"
        tab-panel-container="px-0 py-0"
      >
        <ProfitLossReportView v-if="selected === 'profit-loss'" />
      </BaseTab>

      <BaseTab
        :title="$t('reports.expenses.expenses')"
        tab-panel-container="px-0 py-0"
      >
        <ExpensesReportView v-if="selected === 'expenses'" />
      </BaseTab>

      <BaseTab
        :title="$t('reports.purchases')"
        tab-panel-container="px-0 py-0"
      >
        <PurchaseReportView v-if="selected === 'purchases'" />
      </BaseTab>
      <BaseTab
        :title="$t('reports.taxes.taxes')"
        tab-panel-container="px-0 py-0"
      >
        <TaxReportView v-if="selected === 'taxes'" />
      </BaseTab>
      <BaseTab
        :title="$t('reports.projects.title')"
        tab-panel-container="px-0 py-0"
      >
        <ProjectReportView v-if="selected === 'projects'" />
      </BaseTab>
      <BaseTab
        v-if="basEnabled"
        :title="$t('reports.bas.title')"
        tab-panel-container="px-0 py-0"
      >
        <BasReportView v-if="selected === 'bas'" />
      </BaseTab>
    </BaseTabGroup>
  </BasePage>
</template>
