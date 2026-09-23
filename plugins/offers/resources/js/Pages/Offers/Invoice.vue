<script setup>
import { computed, reactive } from 'vue'
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/AppLayout.vue'
import Card from '@/Components/UI/Card.vue'
import CardHeader from '@/Components/UI/CardHeader.vue'
import CardTitle from '@/Components/UI/CardTitle.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import Button from '@/Components/UI/Button.vue'
import FormInput from '@/Components/UI/FormInput.vue'
import FormTextarea from '@/Components/UI/FormTextarea.vue'
import Breadcrumb from '@/Components/UI/Breadcrumb.vue'
import { useTranslations } from '@/lib/useTranslations'
import { useFormatters } from '@/lib/useFormatters'
import { FileText } from 'lucide-vue-next'

const { t } = useTranslations()
const { formatCurrency } = useFormatters()

const props = defineProps({
  offer: { type: Object, required: true },
  lines: { type: Array, default: () => [] },
})

// One row per offer item line; lines with something left are ticked by default.
const rows = reactive(props.lines.map(line => ({
  ...line,
  selected: Number(line.remaining) !== 0,
  invoice_amount: line.remaining,
  invoice_description: line.invoice_text,
})))

const form = useForm({ lines: [] })
const selectedTotal = computed(() => rows.filter(r => r.selected).reduce((sum, r) => sum + (Number(r.invoice_amount) || 0), 0))
const rowError = (index, field) => {
  const position = rows.filter(r => r.selected).indexOf(rows[index])
  return position === -1 ? undefined : form.errors[`lines.${position}.${field}`]
}

function submit() {
  form.transform(() => ({
    lines: rows.filter(r => r.selected).map(r => ({ line_id: r.id, amount: String(r.invoice_amount), description: r.invoice_description })),
  })).post(`/offers/${props.offer.id}/invoice`)
}
</script>

<template>
  <AppLayout :title="t('of_invoice_select_title', { number: offer.number })" help-page="invoices">
    <Breadcrumb
      :items="[
        { label: t('of_title_offers'), href: '/offers' },
        { label: offer.number, href: `/offers/${offer.id}` },
        { label: t('of_action_invoice') },
      ]"
      class="mb-4"
    />

    <form class="max-w-5xl space-y-6" @submit.prevent="submit">
      <Card>
        <CardHeader>
          <CardTitle>{{ t('of_invoice_select_title', { number: offer.number }) }} — {{ offer.title }}</CardTitle>
          <p class="mt-1 text-sm text-[hsl(var(--muted-foreground))]">{{ t('of_invoice_select_intro') }}</p>
        </CardHeader>
        <CardContent class="space-y-3">
          <p v-if="form.errors.lines || form.errors.status" class="text-sm text-[hsl(var(--destructive))]">{{ form.errors.lines || form.errors.status }}</p>
          <div
            v-for="(row, i) in rows"
            :key="row.id"
            :class="['rounded-lg border border-[hsl(var(--border))] p-3', row.selected ? '' : 'opacity-60']"
          >
            <label class="flex items-start gap-3 text-sm">
              <input :id="`of-inv-${i}-selected`" v-model="row.selected" type="checkbox" class="mt-1 h-4 w-4 accent-[hsl(var(--primary))]" :disabled="Number(row.remaining) === 0" />
              <span class="flex-1">
                <span class="font-medium">{{ row.label ? `${row.label} ` : '' }}{{ row.description }}</span>
                <span class="block text-xs text-[hsl(var(--muted-foreground))]">
                  {{ Number(row.quantity) }} {{ row.unit }} × {{ formatCurrency(row.unit_price, offer.currency) }} = {{ formatCurrency(row.amount, offer.currency) }}
                  · {{ t('of_invoiced_amount') }} {{ formatCurrency(row.invoiced, offer.currency) }}
                  · {{ t('of_remaining') }} {{ formatCurrency(row.remaining, offer.currency) }}
                </span>
              </span>
            </label>
            <p v-if="rowError(i, 'line_id')" class="mt-2 text-sm text-[hsl(var(--destructive))]">{{ rowError(i, 'line_id') }}</p>
            <div v-if="row.selected" class="mt-3 grid gap-3 sm:grid-cols-[minmax(0,1fr)_12rem]">
              <FormTextarea v-model="row.invoice_description" :id="`of-inv-${i}-description`" :label="t('of_invoice_text')" :rows="2" :error="rowError(i, 'description')" required />
              <FormInput v-model="row.invoice_amount" :id="`of-inv-${i}-amount`" type="number" step="0.01" :label="t('of_amount_to_invoice')" :error="rowError(i, 'amount')" required />
            </div>
          </div>
          <div class="flex justify-end border-t pt-3 text-sm font-semibold">
            <span class="mr-4">{{ t('of_selected_total') }}</span>
            <span class="font-mono">{{ formatCurrency(selectedTotal, offer.currency) }}</span>
          </div>
        </CardContent>
      </Card>

      <div class="flex justify-end gap-2">
        <Button as="a" :href="`/offers/${offer.id}`" variant="outline">{{ t('cancel') }}</Button>
        <Button type="submit" :loading="form.processing" :disabled="!rows.some(r => r.selected)"><FileText class="mr-2 h-4 w-4" />{{ t('of_create_invoice_submit') }}</Button>
      </div>
    </form>
  </AppLayout>
</template>
