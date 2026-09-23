<script setup>
import { computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/AppLayout.vue'
import Button from '@/Components/UI/Button.vue'
import DataTable from '@/Components/UI/DataTable.vue'
import Badge from '@/Components/UI/Badge.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import StatCard from '@/Components/UI/StatCard.vue'
import { useTranslations } from '@/lib/useTranslations'
import { useFormatters } from '@/lib/useFormatters'
import { FilePen, HandCoins, Plus, Receipt, Scale, Settings } from 'lucide-vue-next'

const { t } = useTranslations()
const { formatCurrency, formatDate } = useFormatters()

// The user's own claims only (docs/DESIGN-expense-claims.md §8.2).
const props = defineProps({
  claims: Object,
  filters: { type: Object, default: () => ({}) },
  balances: { type: Object, default: () => ({}) },
  hasPerson: { type: Boolean, default: false },
  canManage: { type: Boolean, default: false },
  canCreate: { type: Boolean, default: false },
})

const statusVariant = { draft: 'secondary', approved: 'warning', settled: 'success', debt: 'info' }

const tableFilters = computed(() => [
  {
    key: 'status',
    label: t('status'),
    value: props.filters.status || null,
    options: ['draft', 'approved', 'settled', 'debt'].map(s => ({ value: s, label: t(`ec_status_${s}`) })),
  },
])

const columns = computed(() => [
  { key: 'reference', label: t('ec_reference'), class: 'font-mono text-sm', minWidth: 90 },
  { key: 'date', label: t('date'), format: value => formatDate(value), minWidth: 110 },
  { key: 'title', label: t('ec_title'), minWidth: 240 },
  { key: 'status', label: t('status'), minWidth: 150 },
  { key: 'total', label: t('ec_total'), class: 'text-right whitespace-nowrap font-mono', format: value => formatCurrency(value), minWidth: 120 },
])

const tile = key => props.balances?.[key] ?? { count: 0, total: '0' }

function applyFilter({ key, value }) {
  router.get('/expense-claims', { ...props.filters, [key]: value || undefined }, { preserveState: true, replace: true })
}
</script>

<template>
  <AppLayout :title="t('ec_title_claims')" help-page="expenses">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
      <p class="max-w-2xl text-sm text-[hsl(var(--muted-foreground))]">{{ t('ec_intro_own_claims') }}</p>
      <div class="flex gap-2">
        <Button v-if="canManage" as="a" href="/settings/expense-claims" variant="outline">
          <Settings class="mr-2 h-4 w-4" />
          {{ t('ec_nav_settings_short') }}
        </Button>
        <Button v-if="canCreate && (hasPerson || canManage)" as="a" href="/expense-claims/create">
          <Plus class="mr-2 h-4 w-4" />
          {{ t('ec_new_claim') }}
        </Button>
      </div>
    </div>

    <p v-if="!hasPerson" class="mb-6 rounded-md border border-[hsl(var(--border))] p-4 text-sm">
      {{ t('ec_no_person') }}
    </p>

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
      <StatCard :title="`${t('ec_status_draft')} (${tile('draft').count})`" :value="formatCurrency(tile('draft').total)" :icon="FilePen" />
      <StatCard :title="`${t('ec_open_unpaid')} (${tile('approved').count})`" :value="formatCurrency(tile('approved').total)" :icon="HandCoins" />
      <StatCard :title="`${t('ec_debt_balance')} (${tile('debt').count})`" :value="formatCurrency(tile('debt').total)" :icon="Scale" />
    </div>

    <DataTable
      :columns="columns"
      :rows="claims?.data ?? []"
      :pagination="claims"
      :filters="tableFilters"
      :row-link="row => `/expense-claims/${row.id}`"
      @filter="applyFilter"
    >
      <!-- Other cells use the DataTable default (a link to the row); the badge needs its own
           link, except in the mobile card, which is already one link -->
      <template #cell-status="{ row, inLink }">
        <component :is="inLink ? 'span' : Link" v-bind="inLink ? {} : { href: `/expense-claims/${row.id}` }" class="block">
          <Badge :variant="statusVariant[row.status] ?? 'secondary'">{{ t(`ec_status_${row.status}`) }}</Badge>
          <span v-if="row.status === 'debt' && row.debt_repaid" class="ml-2 text-xs text-[hsl(var(--muted-foreground))]">{{ t('ec_debt_repaid') }}</span>
        </component>
      </template>
      <template #empty>
        <EmptyState
          :icon="Receipt"
          :title="t('ec_no_claims')"
          :description="t('ec_no_claims_desc')"
          :action-label="canCreate && hasPerson ? t('ec_new_claim') : ''"
          :action-href="canCreate && hasPerson ? '/expense-claims/create' : ''"
        />
      </template>
    </DataTable>
  </AppLayout>
</template>
