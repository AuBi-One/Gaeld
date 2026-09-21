<script setup>
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Components/AppLayout.vue'
import Button from '@/Components/UI/Button.vue'
import DataTable from '@/Components/UI/DataTable.vue'
import Badge from '@/Components/UI/Badge.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import StatCard from '@/Components/UI/StatCard.vue'
import { useTranslations } from '@/lib/useTranslations'
import { useFormatters } from '@/lib/useFormatters'
import { Plus, Receipt, Settings } from 'lucide-vue-next'

const { t } = useTranslations()
const { formatCurrency, formatDate } = useFormatters()

const props = defineProps({
  claims: Object,
  filters: { type: Object, default: () => ({}) },
  people: { type: Array, default: () => [] },
  openTotal: { type: String, default: '0' },
  openCount: { type: Number, default: 0 },
  canManage: { type: Boolean, default: false },
})

const statusVariant = { draft: 'secondary', approved: 'warning', settled: 'success', debt: 'info' }

const tableFilters = computed(() => [
  {
    key: 'status',
    label: t('status'),
    value: props.filters.status || null,
    options: ['draft', 'approved', 'settled', 'debt'].map(s => ({ value: s, label: t(`ec_status_${s}`) })),
  },
  {
    key: 'person_id',
    label: t('ec_person'),
    value: props.filters.person_id || null,
    options: props.people.map(p => ({ value: p.id, label: p.name })),
  },
])

const columns = computed(() => [
  { key: 'reference', label: t('ec_reference'), minWidth: 90 },
  { key: 'date', label: t('date'), minWidth: 110 },
  { key: 'person', label: t('ec_person'), minWidth: 140 },
  { key: 'title', label: t('ec_title'), minWidth: 220 },
  { key: 'status', label: t('status'), minWidth: 130 },
  { key: 'total', label: t('ec_total'), class: 'text-right whitespace-nowrap', minWidth: 120 },
])

function applyFilter({ key, value }) {
  router.get('/expense-claims', { ...props.filters, [key]: value || undefined }, { preserveState: true, replace: true })
}
</script>

<template>
  <AppLayout :title="t('ec_title_claims')" help-page="expenses">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
      <p class="max-w-2xl text-sm text-[hsl(var(--muted-foreground))]">{{ t('ec_intro_claims') }}</p>
      <div v-if="canManage" class="flex gap-2">
        <Button as="a" href="/settings/expense-claims" variant="outline">
          <Settings class="mr-2 h-4 w-4" />
          {{ t('ec_nav_settings_short') }}
        </Button>
        <Button as="a" href="/expense-claims/create">
          <Plus class="mr-2 h-4 w-4" />
          {{ t('ec_new_claim') }}
        </Button>
      </div>
    </div>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      <StatCard :title="`${t('ec_open_unpaid')} (${openCount})`" :value="formatCurrency(openTotal)" :icon="Receipt" />
    </div>

    <DataTable
      :columns="columns"
      :rows="claims?.data ?? []"
      :pagination="claims"
      :filters="tableFilters"
      :row-link="row => `/expense-claims/${row.id}`"
      @filter="applyFilter"
    >
      <template #cell-reference="{ row }">
        <span class="font-mono text-sm">{{ row.reference }}</span>
      </template>
      <template #cell-date="{ row }">{{ formatDate(row.date) }}</template>
      <template #cell-status="{ row }">
        <Badge :variant="statusVariant[row.status] ?? 'secondary'">{{ t(`ec_status_${row.status}`) }}</Badge>
      </template>
      <template #cell-total="{ row }">
        <span class="font-mono">{{ formatCurrency(row.total) }}</span>
      </template>
      <template #empty>
        <EmptyState
          :icon="Receipt"
          :title="t('ec_no_claims')"
          :description="t('ec_no_claims_desc')"
          :action-label="canManage ? t('ec_new_claim') : ''"
          :action-href="canManage ? '/expense-claims/create' : ''"
        />
      </template>
    </DataTable>
  </AppLayout>
</template>
