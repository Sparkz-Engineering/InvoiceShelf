import type { RouteRecordRaw } from 'vue-router'

const TaxDeductionsView = () => import('./views/TaxDeductionsView.vue')

export const taxDeductionRoutes: RouteRecordRaw[] = [
  {
    path: 'tax-deductions',
    name: 'tax-deductions.index',
    component: TaxDeductionsView,
    meta: {
      requiresAuth: true,
      ability: 'view-tax-deduction',
      title: 'tax_deductions.title',
    },
  },
]
