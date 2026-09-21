<script setup>
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/AppLayout.vue'
import Card from '@/Components/UI/Card.vue'
import CardHeader from '@/Components/UI/CardHeader.vue'
import CardTitle from '@/Components/UI/CardTitle.vue'
import CardDescription from '@/Components/UI/CardDescription.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import Button from '@/Components/UI/Button.vue'
import Badge from '@/Components/UI/Badge.vue'
import Modal from '@/Components/UI/Modal.vue'
import FormInput from '@/Components/UI/FormInput.vue'
import FormSelect from '@/Components/UI/FormSelect.vue'
import { useTranslations } from '@/lib/useTranslations'
import { useFormatters } from '@/lib/useFormatters'
import { MapPin, Pencil, Plus, Search, Trash2 } from 'lucide-vue-next'

const { t } = useTranslations()
const { formatDate } = useFormatters()

const props = defineProps({
  settings: { type: Object, required: true },
  rates: { type: Array, default: () => [] },
  places: { type: Array, default: () => [] },
  people: { type: Array, default: () => [] },
  employees: { type: Array, default: () => [] },
  contacts: { type: Array, default: () => [] },
  organization: { type: Object, default: null },
  routing: { type: Object, default: () => ({}) },
})

// ── Accounts ──
const accountFields = ['expense_account_code', 'staff_liability_code', 'owner_liability_code', 'staff_debt_code', 'owner_debt_code', 'bank_account_code']
const accountsForm = useForm({ ...props.settings })
const saveAccounts = () => accountsForm.put('/settings/expense-claims/accounts', { preserveScroll: true })

// ── Rates ──
const rateForm = useForm({ vehicle_type: 'car', valid_from: '', valid_to: '', rate_per_km: '', note: '' })
const addRate = () => rateForm.post('/settings/expense-claims/rates', { preserveScroll: true, onSuccess: () => rateForm.reset() })
const deleteRate = rate => router.delete(`/settings/expense-claims/rates/${rate.id}`, { preserveScroll: true })

// ── Places ──
const kinds = ['hq', 'home', 'client', 'other']
const kindOptions = computed(() => kinds.map(k => ({ value: k, label: t(`ec_kind_${k}`) })))
const placeOpen = ref(false)
const editingPlace = ref(null)
const placeForm = useForm({ kind: 'client', label: '', address: '', postal_code: '', city: '', country: 'CH', lat: null, lon: null, contact_id: null })
const searchQuery = ref('')
const searchResults = ref([])
const searching = ref(false)
const contactOptions = computed(() => props.contacts.map(c => ({ value: String(c.id), label: c.name })))
const selectedContact = ref('')

function openPlace(place = null) {
  editingPlace.value = place
  placeForm.clearErrors()
  Object.assign(placeForm, place
    ? { kind: place.kind, label: place.label, address: place.address ?? '', postal_code: place.postal_code ?? '', city: place.city ?? '', country: place.country ?? 'CH', lat: place.lat, lon: place.lon, contact_id: place.contact_id }
    : { kind: 'client', label: '', address: '', postal_code: '', city: '', country: 'CH', lat: null, lon: null, contact_id: null })
  searchQuery.value = place ? [place.address, place.postal_code, place.city].filter(Boolean).join(' ') : ''
  searchResults.value = []
  selectedContact.value = place?.contact_id ? String(place.contact_id) : ''
  placeOpen.value = true
}

function fillFrom(source, kind) {
  Object.assign(placeForm, {
    kind: kind ?? placeForm.kind,
    label: placeForm.label || source.name,
    address: source.address ?? '',
    postal_code: source.postal_code ?? '',
    city: source.city ?? '',
    country: source.country || 'CH',
    lat: null,
    lon: null,
  })
  searchQuery.value = [source.address, source.postal_code, source.city].filter(Boolean).join(' ')
}

function contactChosen(id) {
  const contact = props.contacts.find(c => String(c.id) === id)
  if (!contact) return
  placeForm.contact_id = contact.id
  fillFrom(contact, 'client')
}

async function searchAddress() {
  if (searchQuery.value.trim().length < 3) return
  searching.value = true
  try {
    const response = await fetch(`/settings/expense-claims/address-search?q=${encodeURIComponent(searchQuery.value)}`, { headers: { Accept: 'application/json' } })
    searchResults.value = response.ok ? (await response.json()).results : []
  } finally {
    searching.value = false
  }
}

function chooseResult(result) {
  placeForm.lat = result.lat
  placeForm.lon = result.lon
  // geo.admin labels read "Street 12 1234 City"
  const match = result.label.match(/^(.*?)\s+(\d{4})\s+(.+)$/)
  if (match) {
    placeForm.address = match[1]
    placeForm.postal_code = match[2]
    placeForm.city = match[3]
  }
  searchResults.value = []
}

function savePlace() {
  const options = { preserveScroll: true, onSuccess: () => { placeOpen.value = false } }
  if (editingPlace.value) placeForm.put(`/settings/expense-claims/places/${editingPlace.value.id}`, options)
  else placeForm.post('/settings/expense-claims/places', options)
}

const deletePlace = place => router.delete(`/settings/expense-claims/places/${place.id}`, { preserveScroll: true })

// ── People ──
const personOpen = ref(false)
const editingPerson = ref(null)
const personForm = useForm({ name: '', employee_id: null, is_owner: false, home_place_id: null })
const employeeOptions = computed(() => [{ value: '', label: t('ec_not_employee') }, ...props.employees.map(e => ({ value: e.id, label: `${e.first_name} ${e.last_name}` }))])
const homeOptions = computed(() => [{ value: '', label: '—' }, ...props.places.filter(p => p.kind === 'home').map(p => ({ value: p.id, label: p.label }))])

function openPerson(person = null) {
  editingPerson.value = person
  personForm.clearErrors()
  Object.assign(personForm, person
    ? { name: person.name, employee_id: person.employee_id ?? '', is_owner: person.is_owner, home_place_id: person.home_place_id ?? '' }
    : { name: '', employee_id: '', is_owner: false, home_place_id: '' })
  personOpen.value = true
}

function employeeChosen(id) {
  const employee = props.employees.find(e => e.id === id)
  if (employee && !personForm.name) personForm.name = `${employee.first_name} ${employee.last_name}`
}

function savePerson() {
  personForm.transform(data => ({ ...data, employee_id: data.employee_id || null, home_place_id: data.home_place_id || null }))
  const options = { preserveScroll: true, onSuccess: () => { personOpen.value = false } }
  if (editingPerson.value) personForm.put(`/settings/expense-claims/people/${editingPerson.value.id}`, options)
  else personForm.post('/settings/expense-claims/people', options)
}

const deletePerson = person => router.delete(`/settings/expense-claims/people/${person.id}`, { preserveScroll: true })
const placeLabel = id => props.places.find(p => p.id === id)?.label ?? ''
</script>

<template>
  <AppLayout :title="t('ec_settings_title')" help-page="payroll">
    <div class="max-w-5xl space-y-6">
      <!-- People -->
      <Card>
        <CardHeader>
          <div class="flex items-start justify-between gap-4">
            <div>
              <CardTitle>{{ t('ec_people') }}</CardTitle>
              <CardDescription>{{ t('ec_people_desc') }}</CardDescription>
            </div>
            <Button size="sm" @click="openPerson()"><Plus class="mr-2 h-4 w-4" />{{ t('ec_add_person') }}</Button>
          </div>
        </CardHeader>
        <CardContent class="overflow-x-auto p-0">
          <table class="w-full min-w-[560px] text-sm">
            <thead class="text-left text-xs uppercase text-[hsl(var(--muted-foreground))]">
              <tr>
                <th class="px-4 py-2">{{ t('name') }}</th>
                <th class="px-4 py-2">{{ t('ec_employee') }}</th>
                <th class="px-4 py-2">{{ t('ec_home_place') }}</th>
                <th class="px-4 py-2" />
              </tr>
            </thead>
            <tbody>
              <tr v-if="!people.length"><td colspan="4" class="px-4 py-3 text-[hsl(var(--muted-foreground))]">—</td></tr>
              <tr v-for="person in people" :key="person.id" class="border-t border-[hsl(var(--border))]">
                <td class="px-4 py-2">
                  {{ person.name }}
                  <Badge v-if="person.is_owner" variant="outline" class="ml-2">{{ t('ec_owner') }}</Badge>
                </td>
                <td class="px-4 py-2">{{ person.employee ? `${person.employee.first_name} ${person.employee.last_name}` : t('ec_not_employee') }}</td>
                <td class="px-4 py-2">{{ placeLabel(person.home_place_id) }}</td>
                <td class="whitespace-nowrap px-4 py-2 text-right">
                  <Button size="icon" variant="ghost" :title="t('edit')" @click="openPerson(person)"><Pencil class="h-4 w-4" /></Button>
                  <Button size="icon" variant="ghost" :title="t('delete')" @click="deletePerson(person)"><Trash2 class="h-4 w-4" /></Button>
                </td>
              </tr>
            </tbody>
          </table>
        </CardContent>
      </Card>

      <!-- Places -->
      <Card>
        <CardHeader>
          <div class="flex items-start justify-between gap-4">
            <div>
              <CardTitle>{{ t('ec_places') }}</CardTitle>
              <CardDescription>{{ t('ec_places_desc') }}</CardDescription>
            </div>
            <Button size="sm" @click="openPlace()"><Plus class="mr-2 h-4 w-4" />{{ t('ec_add_place') }}</Button>
          </div>
        </CardHeader>
        <CardContent class="overflow-x-auto p-0">
          <table class="w-full min-w-[560px] text-sm">
            <thead class="text-left text-xs uppercase text-[hsl(var(--muted-foreground))]">
              <tr>
                <th class="px-4 py-2">{{ t('ec_label') }}</th>
                <th class="px-4 py-2">{{ t('type') }}</th>
                <th class="px-4 py-2">{{ t('ec_address') }}</th>
                <th class="px-4 py-2" />
              </tr>
            </thead>
            <tbody>
              <tr v-if="!places.length"><td colspan="4" class="px-4 py-3 text-[hsl(var(--muted-foreground))]">—</td></tr>
              <tr v-for="place in places" :key="place.id" class="border-t border-[hsl(var(--border))]">
                <td class="px-4 py-2">{{ place.label }}</td>
                <td class="px-4 py-2">{{ t(`ec_kind_${place.kind}`) }}</td>
                <td class="px-4 py-2">
                  {{ [place.address, [place.postal_code, place.city].filter(Boolean).join(' ')].filter(Boolean).join(', ') }}
                  <MapPin v-if="place.lat !== null" class="ml-1 inline h-3.5 w-3.5 text-green-600" :title="t('ec_located')" />
                </td>
                <td class="whitespace-nowrap px-4 py-2 text-right">
                  <Button size="icon" variant="ghost" :title="t('edit')" @click="openPlace(place)"><Pencil class="h-4 w-4" /></Button>
                  <Button size="icon" variant="ghost" :title="t('delete')" @click="deletePlace(place)"><Trash2 class="h-4 w-4" /></Button>
                </td>
              </tr>
            </tbody>
          </table>
        </CardContent>
      </Card>

      <div class="grid gap-6 lg:grid-cols-2">
        <!-- Rates -->
        <Card>
          <CardHeader>
            <CardTitle>{{ t('ec_rates') }}</CardTitle>
            <CardDescription>{{ t('ec_rates_desc') }}</CardDescription>
          </CardHeader>
          <CardContent class="space-y-4">
            <table class="w-full text-sm">
              <thead class="text-left text-xs uppercase text-[hsl(var(--muted-foreground))]">
                <tr>
                  <th class="py-2">{{ t('ec_vehicle_type') }}</th>
                  <th class="py-2">{{ t('ec_valid_from') }}</th>
                  <th class="py-2">{{ t('ec_valid_to') }}</th>
                  <th class="py-2 text-right">CHF/km</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                <tr v-for="rate in rates" :key="rate.id" class="border-t border-[hsl(var(--border))]">
                  <td class="py-2">{{ rate.vehicle_type === 'car' ? t('ec_vehicle_car') : rate.vehicle_type }}</td>
                  <td class="py-2">{{ formatDate(rate.valid_from) }}</td>
                  <td class="py-2">{{ rate.valid_to ? formatDate(rate.valid_to) : '—' }}</td>
                  <td class="py-2 text-right font-mono">{{ Number(rate.rate_per_km).toFixed(2) }}</td>
                  <td class="py-2 text-right">
                    <Button size="icon" variant="ghost" :title="t('delete')" @click="deleteRate(rate)"><Trash2 class="h-4 w-4" /></Button>
                  </td>
                </tr>
              </tbody>
            </table>
            <form class="grid grid-cols-2 gap-3 border-t border-[hsl(var(--border))] pt-4 sm:grid-cols-4 sm:items-end" @submit.prevent="addRate">
              <FormInput v-model="rateForm.valid_from" id="ec-rateForm-valid-from" type="date" :label="t('ec_valid_from')" :error="rateForm.errors.valid_from" required />
              <FormInput v-model="rateForm.valid_to" id="ec-rateForm-valid-to" type="date" :label="t('ec_valid_to')" :error="rateForm.errors.valid_to" />
              <FormInput v-model="rateForm.rate_per_km" id="ec-rateForm-rate-per-km" type="number" step="0.0001" min="0" label="CHF/km" :error="rateForm.errors.rate_per_km" required />
              <Button type="submit" :loading="rateForm.processing"><Plus class="mr-2 h-4 w-4" />{{ t('ec_add_rate') }}</Button>
            </form>
          </CardContent>
        </Card>

        <!-- Accounts -->
        <Card>
          <CardHeader>
            <CardTitle>{{ t('ec_accounts') }}</CardTitle>
            <CardDescription>{{ t('ec_accounts_desc') }}</CardDescription>
          </CardHeader>
          <CardContent>
            <form class="grid gap-3 sm:grid-cols-2" @submit.prevent="saveAccounts">
              <FormInput
                v-for="field in accountFields"
                :key="field"
                v-model="accountsForm[field]" :id="`ec-acc-${field}`"
                :label="t(`ec_${field}`)"
                :error="accountsForm.errors[field]"
                required
              />
              <div class="flex justify-end sm:col-span-2">
                <Button type="submit" :loading="accountsForm.processing">{{ t('save') }}</Button>
              </div>
            </form>
          </CardContent>
        </Card>
      </div>

      <!-- Routing -->
      <Card>
        <CardHeader>
          <CardTitle>{{ t('ec_routing') }}</CardTitle>
          <CardDescription>
            {{ routing.configured ? t('ec_routing_on', { provider: routing.provider }) : t('ec_routing_setup') }}
          </CardDescription>
        </CardHeader>
      </Card>
    </div>

    <!-- Place modal -->
    <Modal :open="placeOpen" :title="editingPlace ? t('ec_edit_place') : t('ec_add_place')" size="lg" @close="placeOpen = false">
      <form class="space-y-4" @submit.prevent="savePlace">
        <div class="grid gap-3 sm:grid-cols-2">
          <FormSelect v-model="placeForm.kind" id="ec-placeForm-kind" :label="t('type')" :options="kindOptions" :error="placeForm.errors.kind" />
          <FormInput v-model="placeForm.label" id="ec-placeForm-label" :label="t('ec_label')" :error="placeForm.errors.label" required />
        </div>
        <div class="flex flex-wrap items-end gap-2">
          <FormSelect v-if="contacts.length" v-model="selectedContact" id="ec-selectedContact" :label="t('ec_from_contact')" :options="contactOptions" :placeholder="t('ec_from_contact')" class="min-w-[14rem] flex-1" @update:model-value="contactChosen" />
          <Button v-if="organization" type="button" variant="outline" size="sm" @click="fillFrom(organization, 'hq')">{{ t('ec_from_organization') }}</Button>
        </div>
        <div class="flex items-end gap-2">
          <FormInput v-model="searchQuery" id="ec-searchQuery" :label="t('ec_address_search')" class="flex-1" @keydown.enter.prevent="searchAddress" />
          <Button type="button" variant="outline" :loading="searching" @click="searchAddress"><Search class="h-4 w-4" /></Button>
        </div>
        <div v-if="searchResults.length" class="max-h-48 space-y-1 overflow-y-auto rounded-md border border-[hsl(var(--border))] p-1">
          <button
            v-for="(result, i) in searchResults"
            :key="i"
            type="button"
            class="block w-full rounded px-2 py-1.5 text-left text-sm hover:bg-[hsl(var(--accent))]"
            @click="chooseResult(result)"
          >
            {{ result.label }}
          </button>
        </div>
        <div class="grid gap-3 sm:grid-cols-[1fr_7rem_1fr]">
          <FormInput v-model="placeForm.address" id="ec-placeForm-address" :label="t('ec_address')" :error="placeForm.errors.address" />
          <FormInput v-model="placeForm.postal_code" id="ec-placeForm-postal-code" :label="t('ec_postal_code')" :error="placeForm.errors.postal_code" />
          <FormInput v-model="placeForm.city" id="ec-placeForm-city" :label="t('ec_city')" :error="placeForm.errors.city" />
        </div>
        <p class="flex items-center gap-2 text-xs" :class="placeForm.lat !== null ? 'text-green-700 dark:text-green-400' : 'text-[hsl(var(--muted-foreground))]'">
          <MapPin class="h-4 w-4" />
          {{ placeForm.lat !== null ? `${t('ec_located')} (${Number(placeForm.lat).toFixed(5)}, ${Number(placeForm.lon).toFixed(5)})` : t('ec_not_located') }}
        </p>
        <div class="flex justify-end gap-2">
          <Button type="button" variant="outline" @click="placeOpen = false">{{ t('cancel') }}</Button>
          <Button type="submit" :loading="placeForm.processing">{{ t('save') }}</Button>
        </div>
      </form>
    </Modal>

    <!-- Person modal -->
    <Modal :open="personOpen" :title="editingPerson ? t('ec_edit_person') : t('ec_add_person')" @close="personOpen = false">
      <form class="space-y-4" @submit.prevent="savePerson">
        <FormSelect v-model="personForm.employee_id" id="ec-personForm-employee-id" :label="t('ec_employee')" :options="employeeOptions" :error="personForm.errors.employee_id" @update:model-value="employeeChosen" />
        <FormInput v-model="personForm.name" id="ec-personForm-name" :label="t('name')" :error="personForm.errors.name" required />
        <FormSelect v-model="personForm.home_place_id" id="ec-personForm-home-place-id" :label="t('ec_home_place')" :options="homeOptions" :error="personForm.errors.home_place_id" />
        <label class="flex items-center gap-2 text-sm">
          <input v-model="personForm.is_owner" type="checkbox" class="h-4 w-4 accent-[hsl(var(--primary))]" />
          {{ t('ec_owner') }}
        </label>
        <div class="flex justify-end gap-2">
          <Button type="button" variant="outline" @click="personOpen = false">{{ t('cancel') }}</Button>
          <Button type="submit" :loading="personForm.processing">{{ t('save') }}</Button>
        </div>
      </form>
    </Modal>
  </AppLayout>
</template>
