import type { RouteRecordRaw } from 'vue-router'

const ExpenseIndexView = () => import('../purchases/views/PurchaseWorkspaceView.vue')
const ExpenseCreateView = () => import('./views/ExpenseCreateView.vue')
const ExpenseDetailView = () => import('./views/ExpenseDetailView.vue')

export const expenseRoutes: RouteRecordRaw[] = [
  {
    path: 'expenses',
    name: 'expenses.index',
    component: ExpenseIndexView,
    props: { section: 'expenses' },
    meta: {
      requiresAuth: true,
      ability: ['view-expense', 'view-recurring-cost'],
      title: 'expenses.title',
    },
  },
  {
    path: 'expenses/create',
    name: 'expenses.create',
    component: ExpenseCreateView,
    meta: {
      requiresAuth: true,
      ability: 'create-expense',
      title: 'expenses.new_expense',
    },
  },
  {
    path: 'expenses/:id/view',
    name: 'expenses.view',
    component: ExpenseDetailView,
    meta: {
      requiresAuth: true,
      ability: 'view-expense',
      title: 'expenses.expense',
    },
  },
  {
    path: 'expenses/:id/edit',
    name: 'expenses.edit',
    component: ExpenseCreateView,
    meta: {
      requiresAuth: true,
      ability: 'edit-expense',
      title: 'expenses.edit_expense',
    },
  },
]
