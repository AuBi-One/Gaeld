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
import { FilePen, FileText, Plus, Send, LayoutTemplate } from 'lucide-vue-next'

const { t } = useTranslations()
const { formatCurrency, formatDate } = useFormatters()

const props = defineProps({
  offers: Object,
  filters: { type: Object, default: () => ({}) },
  statuses: { type: Array, default: () => [] },
  contacts: { type: Array, default: () => [] },
  years: { type: Array, default: () => [] },
  stats: { type: Object, default: () => ({}) },
  canManage: { type: Boolean, default: false },
})

const statusVariant = { draft: 'secondary', sent: 'info', accepted: 'success', refused: 'destructive', superseded: 'outline', expired: 'warning' }

const tableFilters = computed(() => [
  { key: 'status', label: t('status'), value: props.filters.status || null, options: props.statuses.map(s => ({ value: s, label: t(`of_status_${s}`) })) },
  { key: 'contact', label: t('of_contact'), value: props.filters.contact || null, options: props.contacts.map(c => ({ value: String(c.id), label: c.name })) },
  { key: 'year', label: t('of_year'), value: props.filters.year || null, options: props.years.map(y => ({ value: String(y), label: String(y) })) },
])

const columns = computed(() => [
  { key: 'number', label: t('of_number'), class: 'font-mono text-sm', minWidth: 110 },
  { key: 'offer_date', label: t('date'), format: value => formatDate(value), minWidth: 100 },
  { key: 'contact', label: t('of_contact'), minWidth: 160 },
  { key: 'title', label: t('of_subject'), minWidth: 220 },
  { key: 'status', label: t('status'), minWidth: 130 },
  { key: 'total', label: t('of_total'), class: 'text-right whitespace-nowrap font-mono', format: (value, row) => formatCurrency(value, row.currency), minWidth: 120 },
])

function applyFilter({ key, value }) {
  router.get('/offers', { ...props.filters, [key]: value || undefined }, { preserveState: true, replace: true })
}
</script>

<template>
  <AppLayout :title="t('of_title_offers')" help-page="invoices">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
      <p class="max-w-2xl text-sm text-[hsl(var(--muted-foreground))]">{{ t('of_intro_offers') }}</p>
      <div class="flex gap-2">
        <Button as="a" href="/offer-templates" variant="outline"><LayoutTemplate class="mr-2 h-4 w-4" />{{ t('of_nav_templates') }}</Button>
        <Button v-if="canManage" as="a" href="/offers/create"><Plus class="mr-2 h-4 w-4" />{{ t('of_new_offer') }}</Button>
      </div>
    </div>

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
      <StatCard :title="`${t('of_stat_open')} (${stats.open?.count ?? 0})`" :value="formatCurrency(stats.open?.total ?? 0)" :icon="Send" />
      <StatCard :title="`${t('of_stat_to_invoice')} (${stats.to_invoice?.count ?? 0})`" :value="formatCurrency(stats.to_invoice?.total ?? 0)" :icon="FilePen" />
      <StatCard :title="t('of_stat_drafts')" :value="stats.drafts ?? 0" :icon="FileText" />
    </div>

    <DataTable
      :columns="columns"
      :rows="offers?.data ?? []"
      :pagination="offers"
      :filters="tableFilters"
      :row-link="row => `/offers/${row.id}`"
      @filter="applyFilter"
    >
      <template #cell-contact="{ row, inLink }">
        <component :is="inLink ? 'span' : Link" v-bind="inLink ? {} : { href: `/offers/${row.id}` }" class="block">
          {{ row.contact }}
          <span v-if="row.attention" class="block text-xs text-[hsl(var(--muted-foreground))]">{{ row.attention }}</span>
        </component>
      </template>
      <template #cell-status="{ row, inLink }">
        <component :is="inLink ? 'span' : Link" v-bind="inLink ? {} : { href: `/offers/${row.id}` }" class="flex flex-wrap items-center gap-1">
          <Badge :variant="statusVariant[row.status] ?? 'secondary'">{{ t(`of_status_${row.status}`) }}</Badge>
          <Badge v-if="row.invoice" variant="success">{{ t('of_invoiced') }}</Badge>
        </component>
      </template>
      <template #empty>
        <EmptyState
          :icon="FilePen"
          :title="t('of_no_offers')"
          :description="t('of_no_offers_desc')"
          :action-label="canManage ? t('of_new_offer') : ''"
          :action-href="canManage ? '/offers/create' : ''"
        />
      </template>
    </DataTable>
  </AppLayout>
</template>
