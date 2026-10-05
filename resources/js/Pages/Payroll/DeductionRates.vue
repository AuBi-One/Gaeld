<script setup>
import { ref, computed, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Components/AppLayout.vue'
import Breadcrumb from '@/Components/UI/Breadcrumb.vue'
import Card from '@/Components/UI/Card.vue'
import CardHeader from '@/Components/UI/CardHeader.vue'
import CardTitle from '@/Components/UI/CardTitle.vue'
import CardDescription from '@/Components/UI/CardDescription.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import Button from '@/Components/UI/Button.vue'
import FormInput from '@/Components/UI/FormInput.vue'
import FormSelect from '@/Components/UI/FormSelect.vue'
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue'
import { useTranslations } from '@/lib/useTranslations'
import { Trash2, Plus, Copy, ChevronDown, ChevronRight } from 'lucide-vue-next'

const props = defineProps({
  deductionRateSets: { type: Array, default: () => [] },
  deductionRateAccounts: { type: Array, default: () => [] },
})

const { t } = useTranslations()

const accountOptions = computed(() => [
  { value: '', label: t('deduction_rate_no_account') },
  ...props.deductionRateAccounts.map(a => ({ value: String(a.id), label: `${a.code} — ${a.name}` })),
])

function familyKey(code) {
  return code.replace(/_(employee|employer)$/, '')
}

function groupLines(set) {
  const groups = new Map()
  for (const rate of set.rates ?? []) {
    const key = familyKey(rate.code)
    if (!groups.has(key)) groups.set(key, { key, name: rate.name, employee: null, employer: null })
    const group = groups.get(key)
    if (rate.type === 'employee') group.employee = rate
    else group.employer = rate
  }
  return Array.from(groups.values())
}

// Only the first set per code starts expanded, so a long history of past
// years doesn't bury the current one.
const seenCodes = new Set()
const expanded = ref(new Set(
  props.deductionRateSets.filter(s => {
    if (seenCodes.has(s.code)) return false
    seenCodes.add(s.code)
    return true
  }).map(s => s.id),
))

function toggle(setId) {
  if (expanded.value.has(setId)) expanded.value.delete(setId)
  else expanded.value.add(setId)
  expanded.value = new Set(expanded.value)
}

// --- Per-line rate/account edits, keyed by line id ---
// Synced from props rather than seeded once: a line created or reloaded
// after the initial render (e.g. right after adding one) must still get an
// editable value here, or its input shows blank even though it did save.
const rateEdits = ref({})
const accountEdits = ref({})
const setEdits = ref({})
watch(() => props.deductionRateSets, (sets) => {
  for (const set of sets) {
    if (!(set.id in setEdits.value)) {
      setEdits.value[set.id] = { title: set.title, date_from: set.date_from, date_to: set.date_to }
    }
    for (const rate of set.rates ?? []) {
      if (!(rate.id in rateEdits.value)) rateEdits.value[rate.id] = String(rate.rate)
      if (!(rate.id in accountEdits.value)) accountEdits.value[rate.id] = rate.account_id ? String(rate.account_id) : ''
    }
  }
}, { immediate: true, deep: true })

function maybeSaveRate(rate) {
  const value = rateEdits.value[rate.id]
  if (value === '' || value == null || Number(value) === Number(rate.rate)) return
  router.put(`/payroll/deduction-rates/${rate.id}`, {
    name: rate.name,
    rate: value,
    type: rate.type,
    is_active: rate.is_active,
    account_id: accountEdits.value[rate.id] || null,
  }, { preserveScroll: true })
}

function saveRateAccount(rate) {
  const accountId = accountEdits.value[rate.id] || null
  if ((rate.account_id ?? null) === (accountId ? Number(accountId) : null)) return
  router.put(`/payroll/deduction-rates/${rate.id}`, {
    name: rate.name,
    rate: rateEdits.value[rate.id] ?? rate.rate,
    type: rate.type,
    is_active: rate.is_active,
    account_id: accountId,
  }, { preserveScroll: true })
}

const lineToDelete = ref(null)
function confirmRemoveLine(rate) {
  lineToDelete.value = rate
}
function removeLine() {
  if (!lineToDelete.value) return
  router.delete(`/payroll/deduction-rates/${lineToDelete.value.id}`, {
    preserveScroll: true,
    onFinish: () => { lineToDelete.value = null },
  })
}

function slugify(name) {
  return name
    .trim()
    .toLowerCase()
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')
}

// --- Add a line to a set ---
const newLine = ref({})
function newLineFor(setId) {
  if (!newLine.value[setId]) {
    newLine.value[setId] = { name: '', employee_rate: '', employer_rate: '', employee_account_id: '', employer_account_id: '' }
  }
  return newLine.value[setId]
}
const addingLine = ref(null)

function canAddLine(setId) {
  const line = newLineFor(setId)
  return line.name.trim() !== '' && (line.employee_rate !== '' || line.employer_rate !== '')
}

function postLine(setId, name, code, rate, type, accountId) {
  return new Promise((resolve) => {
    router.post('/payroll/deduction-rates', {
      deduction_rate_set_id: setId, name, code, rate, type, account_id: accountId || null,
    }, { preserveScroll: true, onFinish: resolve })
  })
}

async function addLine(setId) {
  if (!canAddLine(setId) || addingLine.value === setId) return
  addingLine.value = setId
  const line = newLineFor(setId)
  const name = line.name.trim()
  const base = slugify(name)
  if (line.employee_rate !== '') {
    await postLine(setId, name, `${base}_employee`, line.employee_rate, 'employee', line.employee_account_id)
  }
  if (line.employer_rate !== '') {
    await postLine(setId, name, `${base}_employer`, line.employer_rate, 'employer', line.employer_account_id)
  }
  newLine.value[setId] = { name: '', employee_rate: '', employer_rate: '', employee_account_id: '', employer_account_id: '' }
  addingLine.value = null
}

// --- Sets themselves: create, edit, delete, duplicate ---
const newSet = ref({ code: '', title: '', date_from: '', date_to: '' })
const addingSet = ref(false)
const canAddSet = computed(() =>
  newSet.value.code.trim() !== '' && newSet.value.title.trim() !== ''
  && newSet.value.date_from !== '' && newSet.value.date_to !== '')

function addSet() {
  if (!canAddSet.value || addingSet.value) return
  addingSet.value = true
  router.post('/payroll/deduction-rate-sets', newSet.value, {
    preserveScroll: true,
    onFinish: () => {
      newSet.value = { code: '', title: '', date_from: '', date_to: '' }
      addingSet.value = false
    },
  })
}

const editingSet = ref(null)

function saveSetEdits(set) {
  const edits = setEdits.value[set.id]
  router.put(`/payroll/deduction-rate-sets/${set.id}`, edits, {
    preserveScroll: true,
    onFinish: () => { editingSet.value = null },
  })
}

const setToDelete = ref(null)
function confirmRemoveSet(set) {
  setToDelete.value = set
}
function removeSet() {
  if (!setToDelete.value) return
  router.delete(`/payroll/deduction-rate-sets/${setToDelete.value.id}`, {
    preserveScroll: true,
    onFinish: () => { setToDelete.value = null },
  })
}

// --- Duplicate a set for a new period ---
const duplicating = ref(null)
const duplicateForm = ref({ title: '', date_from: '', date_to: '' })

function startDuplicate(set) {
  duplicating.value = set.id
  duplicateForm.value = { title: `${set.title} (copie)`, date_from: '', date_to: '' }
}

function submitDuplicate(set) {
  router.post(`/payroll/deduction-rate-sets/${set.id}/duplicate`, duplicateForm.value, {
    preserveScroll: true,
    onFinish: () => { duplicating.value = null },
  })
}
</script>

<template>
  <AppLayout :title="t('deduction_rates')" help-page="payroll">
    <Breadcrumb
      :items="[{ label: t('payroll'), href: '/payroll/employees' }, { label: t('deduction_rates') }]"
      class="mb-4"
    />

    <Card>
      <CardHeader>
        <CardTitle>{{ t('settings_deduction_rates_title') }}</CardTitle>
        <CardDescription>{{ t('settings_deduction_rates_desc') }}</CardDescription>
      </CardHeader>
      <CardContent>
        <div class="space-y-4">
          <div
            v-for="set in deductionRateSets"
            :key="set.id"
            class="rounded-lg border border-[hsl(var(--border))]"
          >
            <!-- Set header -->
            <div class="flex items-center gap-2 p-3">
              <button
                type="button"
                class="shrink-0 text-[hsl(var(--muted-foreground))]"
                :aria-label="expanded.has(set.id) ? t('collapse') : t('expand')"
                @click="toggle(set.id)"
              >
                <ChevronDown v-if="expanded.has(set.id)" class="h-4 w-4" />
                <ChevronRight v-else class="h-4 w-4" />
              </button>

              <div v-if="editingSet !== set.id" class="flex flex-1 flex-wrap items-center gap-3">
                <span class="rounded bg-[hsl(var(--muted))] px-2 py-0.5 font-mono text-xs">{{ set.code }}</span>
                <span class="text-sm font-medium">{{ set.title }}</span>
                <span class="text-xs text-[hsl(var(--muted-foreground))]">{{ set.date_from }} → {{ set.date_to }}</span>
              </div>
              <div v-else class="flex flex-1 flex-wrap items-end gap-2">
                <FormInput :id="'set-title-' + set.id" v-model="setEdits[set.id].title" :label="t('deduction_rate_set_title')" class="w-48" />
                <FormInput :id="'set-from-' + set.id" v-model="setEdits[set.id].date_from" type="date" :label="t('from')" />
                <FormInput :id="'set-to-' + set.id" v-model="setEdits[set.id].date_to" type="date" :label="t('to')" />
                <Button size="sm" @click="saveSetEdits(set)">{{ t('save') }}</Button>
                <Button variant="ghost" size="sm" @click="editingSet = null">{{ t('cancel') }}</Button>
              </div>

              <div class="flex shrink-0 items-center gap-1">
                <Button v-if="editingSet !== set.id" variant="ghost" size="sm" @click="editingSet = set.id">
                  {{ t('edit') }}
                </Button>
                <Button variant="ghost" size="sm" @click="startDuplicate(set)">
                  <Copy class="mr-1 h-3.5 w-3.5" />
                  {{ t('deduction_rate_set_duplicate') }}
                </Button>
                <Button
                  variant="ghost"
                  size="icon"
                  :aria-label="t('delete') + ' ' + set.title"
                  class="h-8 w-8 text-[hsl(var(--muted-foreground))] hover:text-[hsl(var(--destructive))]"
                  @click="confirmRemoveSet(set)"
                >
                  <Trash2 class="h-4 w-4" />
                </Button>
              </div>
            </div>

            <!-- Duplicate form -->
            <div v-if="duplicating === set.id" class="space-y-2 border-t border-[hsl(var(--border))] bg-[hsl(var(--muted))]/30 p-3">
              <p class="text-xs text-[hsl(var(--muted-foreground))]">{{ t('deduction_rate_set_duplicate_hint') }}</p>
              <div class="flex flex-wrap items-end gap-2">
                <FormInput :id="'dup-title-' + set.id" v-model="duplicateForm.title" :label="t('deduction_rate_set_title')" class="w-48" />
                <FormInput :id="'dup-from-' + set.id" v-model="duplicateForm.date_from" type="date" :label="t('from')" />
                <FormInput :id="'dup-to-' + set.id" v-model="duplicateForm.date_to" type="date" :label="t('to')" />
                <Button size="sm" :disabled="!duplicateForm.title || !duplicateForm.date_from || !duplicateForm.date_to" @click="submitDuplicate(set)">
                  {{ t('deduction_rate_set_duplicate') }}
                </Button>
                <Button variant="ghost" size="sm" @click="duplicating = null">{{ t('cancel') }}</Button>
              </div>
            </div>

            <!-- Lines: one row per charge — name, employee rate+account+delete, employer rate+account+delete -->
            <div v-if="expanded.has(set.id)" class="space-y-3 border-t border-[hsl(var(--border))] p-3">
              <div class="overflow-x-auto">
                <table class="w-full min-w-[860px] border-collapse text-sm">
                  <thead>
                    <tr class="border-b border-[hsl(var(--border))] text-left text-xs text-[hsl(var(--muted-foreground))]">
                      <th class="py-2 pr-2 font-medium">{{ t('deduction_rate_name_placeholder') }}</th>
                      <th class="py-2 pr-2 font-medium">{{ t('deduction_rate_employee') }}</th>
                      <th class="py-2 pr-2 font-medium">{{ t('deduction_rate_account') }}</th>
                      <th class="w-9 py-2"></th>
                      <th class="py-2 pr-2 font-medium">{{ t('deduction_rate_employer') }}</th>
                      <th class="py-2 pr-2 font-medium">{{ t('deduction_rate_account') }}</th>
                      <th class="w-9 py-2"></th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr
                      v-for="group in groupLines(set)"
                      :key="group.key"
                      class="border-b border-[hsl(var(--border))] last:border-0"
                    >
                      <td class="py-2 pr-2 font-medium">{{ group.name }}</td>

                      <td class="py-2 pr-2">
                        <FormInput
                          v-if="group.employee"
                          :id="'rate-employee-' + group.employee.id"
                          v-model="rateEdits[group.employee.id]"
                          type="number" step="0.01" min="0" max="100" class="w-24"
                          @blur="maybeSaveRate(group.employee)"
                        />
                        <span v-else class="text-xs text-[hsl(var(--muted-foreground))]">—</span>
                      </td>
                      <td class="py-2 pr-2">
                        <FormSelect
                          v-if="group.employee"
                          :id="'account-employee-' + group.employee.id"
                          v-model="accountEdits[group.employee.id]"
                          :options="accountOptions" class="w-48"
                          @update:model-value="saveRateAccount(group.employee)"
                        />
                        <span v-else class="text-xs text-[hsl(var(--muted-foreground))]">—</span>
                      </td>
                      <td class="py-2">
                        <Button
                          v-if="group.employee" variant="ghost" size="icon"
                          :aria-label="t('delete') + ' ' + group.name + ' (' + t('deduction_rate_employee') + ')'"
                          class="h-8 w-8 text-[hsl(var(--muted-foreground))] hover:text-[hsl(var(--destructive))]"
                          @click="confirmRemoveLine(group.employee)"
                        >
                          <Trash2 class="h-4 w-4" />
                        </Button>
                      </td>

                      <td class="py-2 pr-2">
                        <FormInput
                          v-if="group.employer"
                          :id="'rate-employer-' + group.employer.id"
                          v-model="rateEdits[group.employer.id]"
                          type="number" step="0.01" min="0" max="100" class="w-24"
                          @blur="maybeSaveRate(group.employer)"
                        />
                        <span v-else class="text-xs text-[hsl(var(--muted-foreground))]">—</span>
                      </td>
                      <td class="py-2 pr-2">
                        <FormSelect
                          v-if="group.employer"
                          :id="'account-employer-' + group.employer.id"
                          v-model="accountEdits[group.employer.id]"
                          :options="accountOptions" class="w-48"
                          @update:model-value="saveRateAccount(group.employer)"
                        />
                        <span v-else class="text-xs text-[hsl(var(--muted-foreground))]">—</span>
                      </td>
                      <td class="py-2">
                        <Button
                          v-if="group.employer" variant="ghost" size="icon"
                          :aria-label="t('delete') + ' ' + group.name + ' (' + t('deduction_rate_employer') + ')'"
                          class="h-8 w-8 text-[hsl(var(--muted-foreground))] hover:text-[hsl(var(--destructive))]"
                          @click="confirmRemoveLine(group.employer)"
                        >
                          <Trash2 class="h-4 w-4" />
                        </Button>
                      </td>
                    </tr>
                  </tbody>
                  <tfoot>
                    <tr class="border-t border-[hsl(var(--border))]">
                      <td class="py-2 pr-2">
                        <FormInput :id="'new-line-name-' + set.id" v-model="newLineFor(set.id).name" :placeholder="t('deduction_rate_name_placeholder')" />
                      </td>
                      <td class="py-2 pr-2">
                        <FormInput :id="'new-line-ee-' + set.id" v-model="newLineFor(set.id).employee_rate" type="number" step="0.01" min="0" max="100" class="w-24" />
                      </td>
                      <td class="py-2 pr-2">
                        <FormSelect :id="'new-line-ee-acc-' + set.id" v-model="newLineFor(set.id).employee_account_id" :options="accountOptions" class="w-48" />
                      </td>
                      <td class="py-2"></td>
                      <td class="py-2 pr-2">
                        <FormInput :id="'new-line-er-' + set.id" v-model="newLineFor(set.id).employer_rate" type="number" step="0.01" min="0" max="100" class="w-24" />
                      </td>
                      <td class="py-2 pr-2">
                        <FormSelect :id="'new-line-er-acc-' + set.id" v-model="newLineFor(set.id).employer_account_id" :options="accountOptions" class="w-48" />
                      </td>
                      <td class="py-2">
                        <Button
                          size="icon"
                          :aria-label="t('add_deduction_rate')"
                          :disabled="addingLine === set.id || !canAddLine(set.id)"
                          @click="addLine(set.id)"
                        >
                          <Plus class="h-4 w-4" />
                        </Button>
                      </td>
                    </tr>
                  </tfoot>
                </table>
              </div>
              <p class="text-xs text-[hsl(var(--muted-foreground))]">{{ t('deduction_rate_add_hint') }}</p>
            </div>
          </div>
        </div>

        <!-- Add a new set (barème) -->
        <div class="mt-6 space-y-2 border-t border-[hsl(var(--border))] pt-4">
          <p class="text-sm font-medium">{{ t('add_deduction_rate_set') }}</p>
          <p class="text-xs text-[hsl(var(--muted-foreground))]">{{ t('deduction_rate_set_add_hint') }}</p>
          <div class="grid grid-cols-1 gap-2 sm:grid-cols-[auto_1fr_auto_auto_auto] sm:items-end">
            <FormInput id="new_set_code" v-model="newSet.code" :label="t('deduction_rate_set_code')" class="w-32" />
            <FormInput id="new_set_title" v-model="newSet.title" :label="t('deduction_rate_set_title')" />
            <FormInput id="new_set_from" v-model="newSet.date_from" type="date" :label="t('from')" />
            <FormInput id="new_set_to" v-model="newSet.date_to" type="date" :label="t('to')" />
            <Button :disabled="addingSet || !canAddSet" @click="addSet">
              <Plus class="mr-1 h-4 w-4" />
              {{ t('add') }}
            </Button>
          </div>
        </div>
      </CardContent>
    </Card>

    <ConfirmDialog
      :open="!!lineToDelete"
      :title="t('delete')"
      :message="t('are_you_sure')"
      :confirm-label="t('delete')"
      @confirm="removeLine"
      @cancel="lineToDelete = null"
    />

    <ConfirmDialog
      :open="!!setToDelete"
      :title="t('delete')"
      :message="t('deduction_rate_set_delete_warning')"
      :confirm-label="t('delete')"
      @confirm="removeSet"
      @cancel="setToDelete = null"
    />
  </AppLayout>
</template>
