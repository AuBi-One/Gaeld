<script setup>
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/AppLayout.vue'
import Card from '@/Components/UI/Card.vue'
import CardHeader from '@/Components/UI/CardHeader.vue'
import CardTitle from '@/Components/UI/CardTitle.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import Button from '@/Components/UI/Button.vue'
import FormInput from '@/Components/UI/FormInput.vue'
import FormSelect from '@/Components/UI/FormSelect.vue'
import FormTextarea from '@/Components/UI/FormTextarea.vue'
import Breadcrumb from '@/Components/UI/Breadcrumb.vue'
import { useTranslations } from '@/lib/useTranslations'
import { useFormatters } from '@/lib/useFormatters'
import { AlertTriangle, MapPin, Plus, Trash2 } from 'lucide-vue-next'

const { t } = useTranslations()
const { formatCurrency } = useFormatters()

const props = defineProps({
  claim: { type: Object, default: null },
  people: { type: Array, default: () => [] },
  places: { type: Array, default: () => [] },
  rates: { type: Array, default: () => [] },
  routingEnabled: { type: Boolean, default: false },
  defaultPersonId: { type: String, default: null },
})

const lineTypes = ['km', 'meal', 'accommodation', 'transport', 'other']
const hq = computed(() => props.places.find(p => p.kind === 'hq'))

function newLine(type = 'km') {
  return {
    type,
    description: '',
    from_place_id: type === 'km' ? (hq.value?.id ?? '') : '',
    to_place_id: '',
    round_trip: true,
    km_lookup: null,
    km: '',
    km_override_reason: '',
    vehicle_type: 'car',
    amount: '',
  }
}

const form = useForm({
  person_id: props.claim?.person_id ?? props.defaultPersonId ?? (props.people.length === 1 ? props.people[0].id : ''),
  date: props.claim?.date ?? new Date().toISOString().slice(0, 10),
  title: props.claim?.title ?? '',
  notes: props.claim?.notes ?? '',
  lines: props.claim?.lines?.length
    ? props.claim.lines.map(l => ({ ...newLine(l.type), ...l, km: l.km ?? '', amount: l.type === 'km' ? '' : l.amount, round_trip: l.round_trip ?? false }))
    : [newLine()],
})

const personOptions = computed(() => props.people.map(p => ({ value: p.id, label: p.name })))
const typeOptions = computed(() => lineTypes.map(v => ({ value: v, label: t(`ec_type_${v}`) })))
const placeOptions = computed(() => props.places.map(p => ({ value: p.id, label: `${p.label}${p.city ? ` (${p.city})` : ''} — ${t(`ec_kind_${p.kind}`)}` })))
const person = computed(() => props.people.find(p => p.id === form.person_id))

function rateFor(line) {
  const rate = props.rates.find(r => r.vehicle_type === (line.vehicle_type || 'car')
    && r.valid_from <= form.date && (!r.valid_to || r.valid_to >= form.date))
  return rate ? Number(rate.rate_per_km) : null
}

function lineAmount(line) {
  if (line.type !== 'km') return Number(line.amount) || 0
  const rate = rateFor(line)
  if (rate === null || !line.km) return 0
  return Math.round(Number(line.km) * rate * 20) / 20
}

const total = computed(() => form.lines.reduce((sum, line) => sum + lineAmount(line), 0))

function expectedKm(line) {
  return line.km_lookup === null || line.km_lookup === '' ? null : Number(line.km_lookup) * (line.round_trip ? 2 : 1)
}

function kmDiffers(line) {
  const expected = expectedKm(line)
  return expected !== null && line.km !== '' && Math.abs(Number(line.km) - expected) > 0.05
}

function isCommute(line) {
  const home = person.value?.home_place_id
  const ids = [line.from_place_id, line.to_place_id]
  return line.type === 'km' && home && hq.value && ids.includes(home) && ids.includes(hq.value.id)
}

const lookupError = ref({})
const lookingUp = ref({})

async function lookUp(index) {
  const line = form.lines[index]
  lookupError.value = { ...lookupError.value, [index]: '' }
  lookingUp.value = { ...lookingUp.value, [index]: true }
  try {
    const query = new URLSearchParams({ from: line.from_place_id, to: line.to_place_id })
    const response = await fetch(`/expense-claims/distance?${query}`, { headers: { Accept: 'application/json' } })
    const data = await response.json().catch(() => ({}))
    if (!response.ok) {
      lookupError.value = { ...lookupError.value, [index]: data.message || t('ec_distance_lookup_failed') }
      return
    }
    line.km_lookup = data.km
    line.km = String(Number(data.km) * (line.round_trip ? 2 : 1))
  } finally {
    lookingUp.value = { ...lookingUp.value, [index]: false }
  }
}

function placesChanged(line) {
  line.km_lookup = null
}

function roundTripChanged(line) {
  const expected = expectedKm(line)
  if (expected !== null) line.km = String(expected)
}

function submit() {
  form.transform(data => ({
    ...data,
    lines: data.lines.map(line => line.type === 'km'
      ? {
          type: 'km',
          description: line.description || null,
          from_place_id: line.from_place_id || null,
          to_place_id: line.to_place_id || null,
          round_trip: !!line.round_trip,
          km: line.km,
          km_override_reason: line.km_override_reason || null,
          vehicle_type: line.vehicle_type || 'car',
        }
      : { type: line.type, description: line.description || null, amount: line.amount }),
  }))
  if (props.claim) {
    form.put(`/expense-claims/${props.claim.id}`)
  } else {
    form.post('/expense-claims')
  }
}

const lineError = (index, field) => form.errors[`lines.${index}.${field}`]
</script>

<template>
  <AppLayout :title="claim ? t('ec_edit_claim') : t('ec_new_claim')" help-page="expenses">
    <Breadcrumb
      :items="[
        { label: t('expenses'), href: '/expenses' },
        { label: t('ec_title_claims'), href: '/expense-claims' },
        { label: claim ? claim.reference : t('ec_new_claim') },
      ]"
      class="mb-4"
    />

    <form class="max-w-4xl space-y-6" @submit.prevent="submit">
      <Card>
        <CardHeader>
          <CardTitle>{{ claim ? `${t('ec_edit_claim')} ${claim.reference}` : t('ec_new_claim') }}</CardTitle>
        </CardHeader>
        <CardContent class="grid gap-4 sm:grid-cols-2">
          <FormSelect v-model="form.person_id" id="ec-form-person-id" :label="t('ec_person')" :options="personOptions" :error="form.errors.person_id" :placeholder="t('ec_person')" required />
          <FormInput v-model="form.date" id="ec-form-date" type="date" :label="t('date')" :error="form.errors.date" required />
          <FormInput v-model="form.title" id="ec-form-title" :label="t('ec_title')" :error="form.errors.title" class="sm:col-span-2" required />
          <FormTextarea v-model="form.notes" id="ec-form-notes" :label="t('ec_notes')" :error="form.errors.notes" :rows="2" class="sm:col-span-2" />
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <div class="flex items-center justify-between">
            <CardTitle>{{ t('ec_lines') }}</CardTitle>
            <span class="font-mono text-sm">{{ t('ec_total') }} {{ formatCurrency(total) }}</span>
          </div>
        </CardHeader>
        <CardContent class="space-y-4">
          <p v-if="form.errors.lines" class="text-sm text-[hsl(var(--destructive))]">{{ form.errors.lines }}</p>
          <p v-if="!routingEnabled" class="text-xs text-[hsl(var(--muted-foreground))]">{{ t('ec_routing_off') }}</p>

          <div
            v-for="(line, index) in form.lines"
            :key="index"
            class="space-y-3 rounded-lg border border-[hsl(var(--border))] p-4"
          >
            <div class="grid gap-3 sm:grid-cols-[12rem_1fr_auto] sm:items-end">
              <FormSelect v-model="line.type" :id="`ec-line-${index}-type`" :label="t('type')" :options="typeOptions" />
              <FormInput v-model="line.description" :id="`ec-line-${index}-description`" :label="t('ec_description')" :error="lineError(index, 'description')" />
              <Button type="button" variant="ghost" size="icon" :title="t('ec_remove_line')" :disabled="form.lines.length === 1" @click="form.lines.splice(index, 1)">
                <Trash2 class="h-4 w-4" />
              </Button>
            </div>

            <template v-if="line.type === 'km'">
              <div class="grid gap-3 sm:grid-cols-2">
                <FormSelect v-model="line.from_place_id" :id="`ec-line-${index}-from_place_id`" :label="t('ec_from')" :options="placeOptions" :placeholder="t('ec_from')" :error="lineError(index, 'from_place_id')" @update:model-value="placesChanged(line)" />
                <FormSelect v-model="line.to_place_id" :id="`ec-line-${index}-to_place_id`" :label="t('ec_to')" :options="placeOptions" :placeholder="t('ec_to')" :error="lineError(index, 'to_place_id')" @update:model-value="placesChanged(line)" />
              </div>
              <div class="grid gap-3 sm:grid-cols-[auto_auto_10rem_1fr] sm:items-end">
                <label class="flex h-9 items-center gap-2 text-sm">
                  <input v-model="line.round_trip" type="checkbox" class="h-4 w-4 accent-[hsl(var(--primary))]" @change="roundTripChanged(line)" />
                  {{ t('ec_round_trip') }}
                </label>
                <Button
                  v-if="routingEnabled"
                  type="button"
                  variant="outline"
                  size="sm"
                  :loading="lookingUp[index]"
                  :disabled="!line.from_place_id || !line.to_place_id || line.from_place_id === line.to_place_id"
                  @click="lookUp(index)"
                >
                  <MapPin class="mr-2 h-4 w-4" />
                  {{ t('ec_look_up_km') }}
                </Button>
                <span v-else />
                <FormInput v-model="line.km" :id="`ec-line-${index}-km`" type="number" step="0.1" min="0" :label="t('ec_km')" :error="lineError(index, 'km')" required />
                <div class="pb-2 text-right text-sm">
                  <span class="text-[hsl(var(--muted-foreground))]">{{ rateFor(line) !== null ? `× ${rateFor(line).toFixed(2)} = ` : '' }}</span>
                  <span class="font-mono">{{ formatCurrency(lineAmount(line)) }}</span>
                </div>
              </div>
              <p v-if="line.km_lookup !== null && line.km_lookup !== ''" class="text-xs text-[hsl(var(--muted-foreground))]">
                {{ t('ec_km_lookup_hint', { km: line.km_lookup }) }}
              </p>
              <p v-if="lookupError[index]" class="text-xs text-[hsl(var(--destructive))]">{{ lookupError[index] }}</p>
              <FormInput v-if="kmDiffers(line) || line.km_override_reason || lineError(index, 'km_override_reason')" v-model="line.km_override_reason" :id="`ec-line-${index}-km_override_reason`" :label="t('ec_km_override_reason')" :error="lineError(index, 'km_override_reason')" required />
              <p v-if="isCommute(line)" class="flex items-center gap-2 text-xs text-amber-700 dark:text-amber-300">
                <AlertTriangle class="h-4 w-4 shrink-0" />
                {{ t('ec_commute_warning') }}
              </p>
            </template>

            <div v-else class="grid gap-3 sm:grid-cols-[1fr_10rem]">
              <span />
              <FormInput v-model="line.amount" :id="`ec-line-${index}-amount`" type="number" step="0.01" min="0" :label="t('ec_amount')" :error="lineError(index, 'amount')" required />
            </div>
          </div>

          <div class="flex flex-wrap gap-2">
            <Button type="button" variant="outline" size="sm" @click="form.lines.push(newLine('km'))">
              <Plus class="mr-2 h-4 w-4" />{{ t('ec_type_km') }}
            </Button>
            <Button type="button" variant="outline" size="sm" @click="form.lines.push(newLine('meal'))">
              <Plus class="mr-2 h-4 w-4" />{{ t('ec_type_meal') }}
            </Button>
            <Button type="button" variant="outline" size="sm" @click="form.lines.push(newLine('other'))">
              <Plus class="mr-2 h-4 w-4" />{{ t('ec_add_line') }}
            </Button>
          </div>
        </CardContent>
      </Card>

      <div class="flex justify-end gap-2">
        <Button as="a" :href="claim ? `/expense-claims/${claim.id}` : '/expense-claims'" variant="outline">{{ t('cancel') }}</Button>
        <Button type="submit" :loading="form.processing">{{ t('ec_save_draft') }}</Button>
      </div>
    </form>
  </AppLayout>
</template>
