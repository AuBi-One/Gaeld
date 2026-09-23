<script setup>
import { computed } from 'vue'
import Button from '@/Components/UI/Button.vue'
import FormInput from '@/Components/UI/FormInput.vue'
import FormTextarea from '@/Components/UI/FormTextarea.vue'
import { useTranslations } from '@/lib/useTranslations'
import { useFormatters } from '@/lib/useFormatters'
import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-vue-next'

// Offer / template lines, edited in place (the parent passes a reactive array).
const props = defineProps({
  modelValue: { type: Array, required: true },
  errors: { type: Object, default: () => ({}) },
  currency: { type: String, default: 'CHF' },
  vatRate: { type: [Number, null], default: null },
  showTotals: { type: Boolean, default: true },
  idPrefix: { type: String, default: 'of-line' },
})

const { t } = useTranslations()
const { formatCurrency } = useFormatters()
const lines = computed(() => props.modelValue)

function newLine(type) {
  return { type, label: '', description: '', quantity: type === 'item' ? '1' : '', unit: '', unit_price: type === 'item' ? '0' : '' }
}

function move(index, direction) {
  const target = index + direction
  if (target < 0 || target >= lines.value.length) return
  const [moved] = lines.value.splice(index, 1)
  lines.value.splice(target, 0, moved)
}

// Same arithmetic as the server (and Gäld invoices): amount = qty × price, VAT per line, 2 decimals.
// In cents: qty and price rounded to 2 decimals, product truncated to the cent (bcmul), VAT rounded half away from zero.
const cents = value => Math.round((Number(value) || 0) * 100)
const amount = line => line.type === 'item' ? Math.trunc(cents(line.quantity) * cents(line.unit_price) / 100) / 100 : 0
const round2 = value => Math.sign(value) * Math.round(Math.abs(value) * 100 + 1e-9) / 100
const subtotal = computed(() => lines.value.reduce((sum, line) => sum + amount(line), 0))
const vat = computed(() => props.vatRate === null ? 0 : lines.value.reduce((sum, line) => sum + round2(amount(line) * props.vatRate / 100), 0))
const error = (i, field) => props.errors[`lines.${i}.${field}`]
</script>

<template>
  <div class="space-y-3">
    <p v-if="errors.lines" class="text-sm text-[hsl(var(--destructive))]">{{ errors.lines }}</p>
    <div
      v-for="(line, i) in lines"
      :key="i"
      :class="['rounded-lg border border-[hsl(var(--border))] p-3', line.type === 'text' ? 'bg-[hsl(var(--muted)/0.35)]' : '']"
    >
      <div class="grid gap-3 lg:grid-cols-[5rem_minmax(0,1fr)_6rem_7rem_8rem_7rem_auto] lg:items-start">
        <FormInput v-model="line.label" :id="`${idPrefix}-${i}-label`" :label="t('of_pos')" :error="error(i, 'label')" />
        <FormTextarea
          v-model="line.description"
          :id="`${idPrefix}-${i}-description`"
          :label="line.type === 'text' ? t('of_line_text') : t('of_description')"
          :error="error(i, 'description')"
          :rows="2"
          :class="line.type === 'text' ? 'lg:col-span-5' : ''"
          required
        />
        <template v-if="line.type === 'item'">
          <FormInput v-model="line.quantity" :id="`${idPrefix}-${i}-quantity`" type="number" step="0.01" :label="t('of_quantity')" :error="error(i, 'quantity')" required />
          <FormInput v-model="line.unit" :id="`${idPrefix}-${i}-unit`" :label="t('of_unit')" :error="error(i, 'unit')" />
          <FormInput v-model="line.unit_price" :id="`${idPrefix}-${i}-unit_price`" type="number" step="0.01" :label="t('of_unit_price')" :error="error(i, 'unit_price')" required />
          <div class="pt-7 text-right font-mono text-sm">{{ formatCurrency(amount(line), currency) }}</div>
        </template>
        <div class="flex gap-1 lg:pt-6">
          <Button type="button" variant="ghost" size="icon" :disabled="i === 0" :title="t('move_line_up')" @click="move(i, -1)"><ArrowUp class="h-4 w-4" /></Button>
          <Button type="button" variant="ghost" size="icon" :disabled="i === lines.length - 1" :title="t('move_line_down')" @click="move(i, 1)"><ArrowDown class="h-4 w-4" /></Button>
          <Button type="button" variant="ghost" size="icon" :title="t('of_remove_line')" @click="lines.splice(i, 1)"><Trash2 class="h-4 w-4" /></Button>
        </div>
      </div>
    </div>

    <div class="flex flex-wrap gap-2">
      <Button type="button" variant="outline" size="sm" @click="lines.push(newLine('item'))"><Plus class="mr-1 h-4 w-4" />{{ t('of_add_item') }}</Button>
      <Button type="button" variant="outline" size="sm" @click="lines.push(newLine('text'))"><Plus class="mr-1 h-4 w-4" />{{ t('of_add_text') }}</Button>
    </div>

    <div v-if="showTotals" class="ml-auto max-w-sm space-y-1 border-t pt-3 text-sm">
      <div class="flex justify-between text-[hsl(var(--muted-foreground))]"><span>{{ t('of_subtotal') }}</span><span class="font-mono">{{ formatCurrency(subtotal, currency) }}</span></div>
      <div v-if="vatRate !== null" class="flex justify-between text-[hsl(var(--muted-foreground))]"><span>{{ t('of_vat') }} {{ vatRate }} %</span><span class="font-mono">{{ formatCurrency(vat, currency) }}</span></div>
      <div class="flex justify-between font-semibold"><span>{{ t('of_total') }}</span><span class="font-mono">{{ formatCurrency(subtotal + vat, currency) }}</span></div>
    </div>
  </div>
</template>
