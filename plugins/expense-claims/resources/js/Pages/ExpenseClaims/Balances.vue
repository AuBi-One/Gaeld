<script setup>
import { computed, ref, watch } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/AppLayout.vue'
import Card from '@/Components/UI/Card.vue'
import CardHeader from '@/Components/UI/CardHeader.vue'
import CardTitle from '@/Components/UI/CardTitle.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import Button from '@/Components/UI/Button.vue'
import Badge from '@/Components/UI/Badge.vue'
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue'
import Modal from '@/Components/UI/Modal.vue'
import FormInput from '@/Components/UI/FormInput.vue'
import FormSelect from '@/Components/UI/FormSelect.vue'
import FormTextarea from '@/Components/UI/FormTextarea.vue'
import { useTranslations } from '@/lib/useTranslations'
import { useFormatters } from '@/lib/useFormatters'
import { ArrowRightLeft, Check, Landmark, Undo2 } from 'lucide-vue-next'

const { t } = useTranslations()
const { formatCurrency, formatDate } = useFormatters()

// Managers' view (docs/DESIGN-expense-claims.md §8.2): unpaid claims of everyone,
// grouped actions, balances per person and debt records.
const props = defineProps({
  filters: { type: Object, default: () => ({}) },
  claims: { type: Array, default: () => [] },
  truncated: { type: Boolean, default: false },
  people: { type: Array, default: () => [] },
  debts: { type: Array, default: () => [] },
  accounts: { type: Array, default: () => [] },
  defaultAccount: { type: String, default: '' },
  defaultDebtDate: { type: String, default: '' },
  canManage: { type: Boolean, default: false },
})

const statusVariant = { draft: 'secondary', approved: 'warning' }
const today = new Date().toISOString().slice(0, 10)

// ── filters ──
const filterForm = ref({ status: props.filters.status || '', person_id: props.filters.person_id || '', from: props.filters.from || '', to: props.filters.to || '' })
function applyFilters() {
  const query = Object.fromEntries(Object.entries(filterForm.value).filter(([, v]) => v))
  router.get('/expense-balances', query, { preserveState: true, preserveScroll: true, replace: true })
}
function filterPerson(id) {
  filterForm.value.person_id = id
  applyFilters()
}
const statusOptions = computed(() => [
  { value: '', label: t('ec_all_unpaid') },
  { value: 'draft', label: t('ec_status_draft') },
  { value: 'approved', label: t('ec_status_approved') },
])
const personOptions = computed(() => [{ value: '', label: t('ec_all_people') }, ...props.people.map(p => ({ value: p.id, label: p.name }))])
const accountOptions = computed(() => props.accounts.map(a => ({ value: a.code, label: `${a.code} ${a.name}` })))

// ── selection ──
const selected = ref([])
watch(() => props.claims, (claims) => {
  const ids = new Set(claims.map(c => c.id))
  selected.value = selected.value.filter(id => ids.has(id))
})
const allSelected = computed(() => props.claims.length > 0 && props.claims.every(c => selected.value.includes(c.id)))
function toggleAll() {
  selected.value = allSelected.value ? [] : props.claims.map(c => c.id)
}
function toggle(id) {
  selected.value = selected.value.includes(id) ? selected.value.filter(x => x !== id) : [...selected.value, id]
}
const selectedClaims = computed(() => props.claims.filter(c => selected.value.includes(c.id)))
const selectedDrafts = computed(() => selectedClaims.value.filter(c => c.status === 'draft'))
const selectedApproved = computed(() => selectedClaims.value.filter(c => c.status === 'approved'))
const sum = claims => claims.reduce((s, c) => s + Number(c.total), 0)

// ── actions ──
const confirmApprove = ref(false)
const payOpen = ref(false)
const debtOpen = ref(false)
const busy = ref(false)
const payForm = useForm({ ids: [], date: today, account_code: props.defaultAccount })
const debtForm = useForm({ ids: [], date: props.defaultDebtDate, notes: '' })

function approve() {
  busy.value = true
  router.post('/expense-balances/approve', { ids: selectedDrafts.value.map(c => c.id) }, {
    preserveScroll: true,
    onSuccess: () => { selected.value = [] },
    onFinish: () => { busy.value = false; confirmApprove.value = false },
  })
}
function pay() {
  payForm.ids = selectedApproved.value.map(c => c.id)
  payForm.post('/expense-balances/pay', { preserveScroll: true, onSuccess: () => { payOpen.value = false; selected.value = [] } })
}
function openDebt() {
  debtForm.date = props.filters.to || props.defaultDebtDate
  debtOpen.value = true
}
function passToDebt() {
  debtForm.ids = selectedApproved.value.map(c => c.id)
  debtForm.post('/expense-balances/debt', { preserveScroll: true, onSuccess: () => { debtOpen.value = false; selected.value = [] } })
}

// ── debt records ──
const cancelling = ref(null)
const repaying = ref(null)
const repayForm = useForm({ date: today, amount: '', account_code: props.defaultAccount })
function cancelDebt() {
  router.delete(`/expense-balances/debts/${cancelling.value.id}`, { preserveScroll: true, onFinish: () => { cancelling.value = null } })
}
function openRepay(debt) {
  repayForm.amount = debt.remaining
  repaying.value = debt
}
function repay() {
  repayForm.post(`/expense-balances/debts/${repaying.value.id}/repay`, { preserveScroll: true, onSuccess: () => { repaying.value = null } })
}
function debtState(debt) {
  if (Number(debt.remaining) <= 0) return 'repaid'
  return debt.repayments.length ? 'partly_repaid' : 'open'
}
</script>

<template>
  <AppLayout :title="t('ec_nav_balances')" help-page="expenses">
    <p class="mb-6 max-w-3xl text-sm text-[hsl(var(--muted-foreground))]">{{ t('ec_balances_intro') }}</p>

    <div class="space-y-6">
      <!-- Balances per person -->
      <Card>
        <CardHeader><CardTitle>{{ t('ec_balances_per_person') }}</CardTitle></CardHeader>
        <CardContent class="overflow-x-auto p-0">
          <table class="w-full min-w-[560px] text-sm">
            <thead class="text-left text-xs uppercase text-[hsl(var(--muted-foreground))]">
              <tr>
                <th class="px-4 py-2">{{ t('ec_person') }}</th>
                <th class="px-4 py-2 text-right">{{ t('ec_status_draft') }}</th>
                <th class="px-4 py-2 text-right">{{ t('ec_open_unpaid') }}</th>
                <th class="px-4 py-2 text-right">{{ t('ec_debt_balance') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="person in people" :key="person.id" class="border-t border-[hsl(var(--border))]">
                <td class="px-4 py-2">
                  <button type="button" class="hover:underline" @click="filterPerson(person.id)">{{ person.name }}</button>
                  <Badge v-if="person.is_owner" variant="outline" class="ml-2">{{ t('ec_owner') }}</Badge>
                </td>
                <td class="px-4 py-2 text-right font-mono">{{ formatCurrency(person.draft_total) }}</td>
                <td class="px-4 py-2 text-right font-mono">{{ formatCurrency(person.approved_total) }}</td>
                <td class="px-4 py-2 text-right font-mono">{{ formatCurrency(person.debt_total) }}</td>
              </tr>
            </tbody>
          </table>
        </CardContent>
      </Card>

      <!-- Unpaid claims -->
      <Card>
        <CardHeader>
          <CardTitle>{{ t('ec_open_claims') }}</CardTitle>
          <form class="mt-4 grid gap-3 sm:grid-cols-[1fr_1fr_1fr_1fr_auto] sm:items-end" @submit.prevent="applyFilters">
            <FormSelect v-model="filterForm.status" id="ec-filter-status" :label="t('status')" :options="statusOptions" />
            <FormSelect v-model="filterForm.person_id" id="ec-filter-person" :label="t('ec_person')" :options="personOptions" />
            <FormInput v-model="filterForm.from" id="ec-filter-from" type="date" :label="t('ec_dated_from')" />
            <FormInput v-model="filterForm.to" id="ec-filter-to" type="date" :label="t('ec_dated_up_to')" />
            <Button type="submit" variant="outline">{{ t('apply') }}</Button>
          </form>
        </CardHeader>
        <CardContent class="space-y-3 p-0">
          <div v-if="canManage" class="flex flex-wrap items-center gap-2 px-4">
            <span class="text-sm text-[hsl(var(--muted-foreground))]">
              {{ t('ec_selected', { count: selectedClaims.length }) }} · {{ formatCurrency(sum(selectedClaims)) }}
            </span>
            <Button size="sm" :disabled="!selectedDrafts.length" @click="confirmApprove = true">
              <Check class="mr-2 h-4 w-4" />{{ t('ec_approve_selected', { count: selectedDrafts.length }) }}
            </Button>
            <Button size="sm" variant="outline" :disabled="!selectedApproved.length" @click="payOpen = true">
              <Landmark class="mr-2 h-4 w-4" />{{ t('ec_pay_selected', { count: selectedApproved.length }) }}
            </Button>
            <Button size="sm" variant="outline" :disabled="!selectedApproved.length" @click="openDebt">
              <ArrowRightLeft class="mr-2 h-4 w-4" />{{ t('ec_debt_selected', { count: selectedApproved.length }) }}
            </Button>
          </div>
          <div class="overflow-x-auto">
            <p v-if="!claims.length" class="px-4 pb-4 text-sm text-[hsl(var(--muted-foreground))]">{{ t('ec_no_unpaid') }}</p>
            <table v-else class="w-full min-w-[720px] text-sm">
              <thead class="text-left text-xs uppercase text-[hsl(var(--muted-foreground))]">
                <tr>
                  <th v-if="canManage" class="w-10 px-4 py-2">
                    <input type="checkbox" :checked="allSelected" :aria-label="t('select_all')" class="h-4 w-4 accent-[hsl(var(--primary))]" @change="toggleAll" />
                  </th>
                  <th class="px-4 py-2">{{ t('ec_reference') }}</th>
                  <th class="px-4 py-2">{{ t('date') }}</th>
                  <th class="px-4 py-2">{{ t('ec_person') }}</th>
                  <th class="px-4 py-2">{{ t('ec_title') }}</th>
                  <th class="px-4 py-2">{{ t('status') }}</th>
                  <th class="px-4 py-2 text-right">{{ t('ec_total') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="claim in claims" :key="claim.id" class="border-t border-[hsl(var(--border))] hover:bg-[hsl(var(--accent))]">
                  <td v-if="canManage" class="px-4 py-2">
                    <input type="checkbox" :checked="selected.includes(claim.id)" :aria-label="claim.reference" class="h-4 w-4 accent-[hsl(var(--primary))]" @change="toggle(claim.id)" />
                  </td>
                  <td class="px-4 py-2 font-mono"><Link :href="`/expense-claims/${claim.id}?from=balances`" class="hover:underline">{{ claim.reference }}</Link></td>
                  <td class="whitespace-nowrap px-4 py-2">{{ formatDate(claim.date) }}</td>
                  <td class="px-4 py-2">{{ claim.person }}</td>
                  <td class="px-4 py-2"><Link :href="`/expense-claims/${claim.id}?from=balances`" class="hover:underline">{{ claim.title }}</Link></td>
                  <td class="px-4 py-2"><Badge :variant="statusVariant[claim.status] ?? 'secondary'">{{ t(`ec_status_${claim.status}`) }}</Badge></td>
                  <td class="whitespace-nowrap px-4 py-2 text-right font-mono">{{ formatCurrency(claim.total) }}</td>
                </tr>
              </tbody>
            </table>
            <p v-if="truncated" class="px-4 py-2 text-xs text-[hsl(var(--muted-foreground))]">{{ t('ec_truncated') }}</p>
          </div>
        </CardContent>
      </Card>

      <!-- Debt records -->
      <Card>
        <CardHeader><CardTitle>{{ t('ec_debts') }}</CardTitle></CardHeader>
        <CardContent class="overflow-x-auto p-0">
          <p v-if="!debts.length" class="px-4 pb-4 text-sm text-[hsl(var(--muted-foreground))]">{{ t('ec_no_debts') }}</p>
          <table v-else class="w-full min-w-[720px] text-sm">
            <thead class="text-left text-xs uppercase text-[hsl(var(--muted-foreground))]">
              <tr>
                <th class="px-4 py-2">{{ t('date') }}</th>
                <th class="px-4 py-2">{{ t('ec_person') }}</th>
                <th class="px-4 py-2">{{ t('account') }}</th>
                <th class="px-4 py-2">{{ t('status') }}</th>
                <th class="px-4 py-2 text-right">{{ t('ec_amount') }}</th>
                <th class="px-4 py-2 text-right">{{ t('ec_remaining') }}</th>
                <th class="px-4 py-2" />
              </tr>
            </thead>
            <tbody>
              <tr v-for="debt in debts" :key="debt.id" class="border-t border-[hsl(var(--border))] align-top">
                <td class="whitespace-nowrap px-4 py-2">{{ formatDate(debt.date) }}</td>
                <td class="px-4 py-2">
                  {{ debt.person }}
                  <p v-if="debt.entry_lost" class="text-xs text-[hsl(var(--destructive))]">{{ t('ec_debt_entry_lost') }}</p>
                  <p v-for="(r, i) in debt.repayments" :key="i" class="text-xs text-[hsl(var(--muted-foreground))]">
                    {{ formatDate(r.date) }} · {{ t(`ec_via_${r.via}`) }} · {{ formatCurrency(r.amount) }}
                  </p>
                </td>
                <td class="px-4 py-2 font-mono">{{ debt.account_code }}</td>
                <td class="px-4 py-2"><Badge variant="outline">{{ t(`ec_debt_${debtState(debt)}`) }}</Badge></td>
                <td class="px-4 py-2 text-right font-mono">{{ formatCurrency(debt.amount) }}</td>
                <td class="px-4 py-2 text-right font-mono">{{ formatCurrency(debt.remaining) }}</td>
                <td class="whitespace-nowrap px-4 py-2 text-right">
                  <template v-if="canManage">
                    <Button v-if="Number(debt.remaining) > 0" size="sm" variant="outline" @click="openRepay(debt)">
                      <Landmark class="mr-2 h-4 w-4" />{{ t('ec_repay') }}
                    </Button>
                    <Button v-if="!debt.repayments.length" size="icon" variant="ghost" :title="t('ec_cancel_debt')" @click="cancelling = debt">
                      <Undo2 class="h-4 w-4" />
                    </Button>
                  </template>
                </td>
              </tr>
            </tbody>
          </table>
        </CardContent>
      </Card>
    </div>

    <ConfirmDialog
      :open="confirmApprove"
      :title="t('ec_approve')"
      :message="t('ec_approve_confirm', { count: selectedDrafts.length, amount: formatCurrency(sum(selectedDrafts)) })"
      :confirm-label="t('ec_confirm')"
      confirm-variant="default"
      @confirm="approve"
      @cancel="confirmApprove = false"
    />

    <Modal :open="payOpen" :title="t('ec_pay_from_account')" size="sm" @close="payOpen = false">
      <form class="space-y-4" @submit.prevent="pay">
        <p class="text-sm">{{ t('ec_pay_confirm', { count: selectedApproved.length, amount: formatCurrency(sum(selectedApproved)) }) }}</p>
        <FormInput v-model="payForm.date" id="ec-payForm-date" type="date" :label="t('ec_payment_date')" :error="payForm.errors.date" required />
        <FormSelect v-model="payForm.account_code" id="ec-payForm-account" :label="t('ec_pay_account')" :options="accountOptions" :error="payForm.errors.account_code" required />
        <p v-if="payForm.errors.ids" class="text-sm text-[hsl(var(--destructive))]">{{ payForm.errors.ids }}</p>
        <div class="flex justify-end gap-2">
          <Button type="button" variant="outline" @click="payOpen = false">{{ t('cancel') }}</Button>
          <Button type="submit" :loading="payForm.processing">{{ t('ec_confirm') }}</Button>
        </div>
      </form>
    </Modal>

    <Modal :open="debtOpen" :title="t('ec_convert_to_debt')" size="sm" @close="debtOpen = false">
      <form class="space-y-4" @submit.prevent="passToDebt">
        <p class="text-sm">{{ t('ec_debt_confirm', { count: selectedApproved.length, amount: formatCurrency(sum(selectedApproved)) }) }}</p>
        <FormInput v-model="debtForm.date" id="ec-debtForm-date" type="date" :label="t('ec_debt_date')" :error="debtForm.errors.date" required />
        <FormTextarea v-model="debtForm.notes" id="ec-debtForm-notes" :label="t('ec_notes')" :rows="2" :error="debtForm.errors.notes" />
        <div class="flex justify-end gap-2">
          <Button type="button" variant="outline" @click="debtOpen = false">{{ t('cancel') }}</Button>
          <Button type="submit" :loading="debtForm.processing">{{ t('ec_confirm') }}</Button>
        </div>
      </form>
    </Modal>

    <ConfirmDialog
      :open="!!cancelling"
      :title="t('ec_cancel_debt')"
      :confirm-label="t('ec_confirm')"
      @confirm="cancelDebt"
      @cancel="cancelling = null"
    />

    <Modal :open="!!repaying" :title="t('ec_repay')" size="sm" @close="repaying = null">
      <form class="space-y-4" @submit.prevent="repay">
        <FormInput v-model="repayForm.date" id="ec-repayForm-date" type="date" :label="t('ec_payment_date')" :error="repayForm.errors.date" required />
        <FormInput v-model="repayForm.amount" id="ec-repayForm-amount" type="number" step="0.01" min="0" :label="t('ec_amount')" :error="repayForm.errors.amount" required />
        <FormSelect v-model="repayForm.account_code" id="ec-repayForm-account" :label="t('ec_pay_account')" :options="accountOptions" :error="repayForm.errors.account_code" required />
        <div class="flex justify-end gap-2">
          <Button type="button" variant="outline" @click="repaying = null">{{ t('cancel') }}</Button>
          <Button type="submit" :loading="repayForm.processing">{{ t('ec_confirm') }}</Button>
        </div>
      </form>
    </Modal>
  </AppLayout>
</template>
