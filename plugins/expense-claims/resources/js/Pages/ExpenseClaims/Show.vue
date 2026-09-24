<script setup>
import { computed, ref } from 'vue'
import { router, useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/AppLayout.vue'
import Card from '@/Components/UI/Card.vue'
import CardHeader from '@/Components/UI/CardHeader.vue'
import CardTitle from '@/Components/UI/CardTitle.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import Button from '@/Components/UI/Button.vue'
import Badge from '@/Components/UI/Badge.vue'
import Breadcrumb from '@/Components/UI/Breadcrumb.vue'
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue'
import Modal from '@/Components/UI/Modal.vue'
import FormInput from '@/Components/UI/FormInput.vue'
import FormSelect from '@/Components/UI/FormSelect.vue'
import { useTranslations } from '@/lib/useTranslations'
import { useFormatters } from '@/lib/useFormatters'
import { Check, Landmark, Paperclip, Pencil, Trash2, Undo2 } from 'lucide-vue-next'

const { t } = useTranslations()
const { formatCurrency, formatDate } = useFormatters()

const props = defineProps({
  claim: { type: Object, required: true },
  entries: { type: Array, default: () => [] },
  from: { type: String, default: 'claims' },
  accounts: { type: Array, default: () => [] },
  defaultAccount: { type: String, default: '' },
  canManage: { type: Boolean, default: false },
  canEdit: { type: Boolean, default: false },
  canAttach: { type: Boolean, default: false },
})

const back = computed(() => props.from === 'balances'
  ? { label: t('ec_nav_balances'), href: '/expense-balances' }
  : { label: t('ec_title_claims'), href: '/expense-claims' })
const accountOptions = computed(() => props.accounts.map(a => ({ value: a.code, label: `${a.code} ${a.name}` })))

const statusVariant = { draft: 'secondary', approved: 'warning', settled: 'success', debt: 'info' }
const busy = ref(false)
const confirmDelete = ref(false)
const payOpen = ref(false)
const payForm = useForm({ date: new Date().toISOString().slice(0, 10), account_code: props.defaultAccount })
const fileInput = ref(null)

function act(action) {
  busy.value = true
  router.post(`/expense-claims/${props.claim.id}/${action}`, {}, { preserveScroll: true, onFinish: () => { busy.value = false } })
}

function destroy() {
  router.delete(`/expense-claims/${props.claim.id}`, { onFinish: () => { confirmDelete.value = false } })
}

function pay() {
  payForm.post(`/expense-claims/${props.claim.id}/pay-bank`, { preserveScroll: true, onSuccess: () => { payOpen.value = false } })
}

function upload(event) {
  const file = event.target.files?.[0]
  if (!file) return
  router.post(`/expense-claims/${props.claim.id}/attachments`, { file }, { forceFormData: true, preserveScroll: true })
  event.target.value = ''
}

function lineLabel(line) {
  if (line.type !== 'km') return line.description || t(`ec_type_${line.type}`)
  const route = [line.from, line.to].filter(Boolean).join(' → ')
  return [route, line.description].filter(Boolean).join(' — ') || t('ec_type_km')
}
</script>

<template>
  <AppLayout :title="`${t('ec_title_claims')} ${claim.reference}`" help-page="expenses">
    <Breadcrumb
      :items="[
        { label: t('expenses'), href: '/expenses' },
        back,
        { label: claim.reference },
      ]"
      class="mb-4"
    />

    <div class="max-w-4xl space-y-6">
      <Card>
        <CardHeader>
          <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
              <CardTitle>{{ claim.reference }} — {{ claim.title }}</CardTitle>
              <p class="mt-1 text-sm text-[hsl(var(--muted-foreground))]">
                {{ claim.person }} · {{ formatDate(claim.date) }}
                <span v-if="claim.source === 'airtable'"> · {{ t('ec_migrated') }}</span>
              </p>
              <p v-if="claim.approved_at" class="mt-1 text-sm text-[hsl(var(--muted-foreground))]">
                {{ claim.approved_by
                  ? t('ec_approved_by', { name: claim.approved_by, date: formatDate(claim.approved_at) })
                  : t(claim.source === 'airtable' ? 'ec_approved_by_migration' : 'ec_approved_on', { date: formatDate(claim.approved_at) }) }}
              </p>
              <p v-if="claim.debt" class="mt-1 text-sm text-[hsl(var(--muted-foreground))]">
                {{ t('ec_in_debt_record', { date: formatDate(claim.debt.date), remaining: formatCurrency(claim.debt.remaining) }) }}
              </p>
              <p v-else-if="claim.settled_on" class="mt-1 text-sm text-[hsl(var(--muted-foreground))]">
                {{ t('ec_paid_on', { date: formatDate(claim.settled_on), via: t(`ec_via_${claim.settled_via}`) }) }}
                <Link v-if="claim.salary_slip_id" :href="`/payroll/salary-slips/${claim.salary_slip_id}`" class="ml-1 text-[hsl(var(--primary))] underline">{{ t('salary_slip') }}</Link>
              </p>
            </div>
            <div class="flex items-center gap-3">
              <Badge :variant="statusVariant[claim.status] ?? 'secondary'">{{ t(`ec_status_${claim.status}`) }}</Badge>
              <span class="font-mono text-lg font-semibold">{{ formatCurrency(claim.total) }}</span>
            </div>
          </div>
        </CardHeader>
        <CardContent v-if="canManage || canEdit" class="flex flex-wrap gap-2">
          <template v-if="claim.status === 'draft'">
            <Button v-if="canManage" :loading="busy" @click="act('approve')"><Check class="mr-2 h-4 w-4" />{{ t('ec_approve') }}</Button>
            <template v-if="canEdit">
              <Button as="a" :href="`/expense-claims/${claim.id}/edit`" variant="outline"><Pencil class="mr-2 h-4 w-4" />{{ t('edit') }}</Button>
              <Button variant="ghost" @click="confirmDelete = true"><Trash2 class="mr-2 h-4 w-4" />{{ t('delete') }}</Button>
            </template>
          </template>
          <template v-else-if="canManage && claim.status === 'approved'">
            <Button variant="outline" @click="payOpen = true"><Landmark class="mr-2 h-4 w-4" />{{ t('ec_pay_bank') }}</Button>
            <Button variant="ghost" :loading="busy" @click="act('unapprove')"><Undo2 class="mr-2 h-4 w-4" />{{ t('ec_unapprove') }}</Button>
          </template>
          <Button v-else-if="canManage && claim.status === 'settled' && claim.settled_via === 'bank'" variant="ghost" :loading="busy" @click="act('cancel-bank')">
            <Undo2 class="mr-2 h-4 w-4" />{{ t('ec_cancel_bank') }}
          </Button>
        </CardContent>
      </Card>

      <Card>
        <CardHeader><CardTitle>{{ t('ec_lines') }}</CardTitle></CardHeader>
        <CardContent class="p-0">
          <table class="w-full text-sm">
            <thead class="text-left text-xs uppercase text-[hsl(var(--muted-foreground))]">
              <tr>
                <th class="px-4 py-2">{{ t('type') }}</th>
                <th class="px-4 py-2">{{ t('ec_description') }}</th>
                <th class="px-4 py-2 text-right">{{ t('ec_km') }}</th>
                <th class="px-4 py-2 text-right">{{ t('ec_rate') }}</th>
                <th class="px-4 py-2 text-right">{{ t('ec_amount') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(line, i) in claim.lines" :key="i" class="border-t border-[hsl(var(--border))]">
                <td class="whitespace-nowrap px-4 py-2">{{ t(`ec_type_${line.type}`) }}</td>
                <td class="px-4 py-2">
                  {{ lineLabel(line) }}
                  <span v-if="line.type === 'km' && line.round_trip" class="text-xs text-[hsl(var(--muted-foreground))]"> · {{ t('ec_round_trip') }}</span>
                  <p v-if="line.km_override_reason" class="text-xs text-[hsl(var(--muted-foreground))]">
                    {{ t('ec_km_lookup_hint', { km: line.km_lookup }) }} — {{ line.km_override_reason }}
                  </p>
                </td>
                <td class="px-4 py-2 text-right font-mono">{{ line.km ?? '' }}</td>
                <td class="px-4 py-2 text-right font-mono">{{ line.rate ? Number(line.rate).toFixed(2) : '' }}</td>
                <td class="px-4 py-2 text-right font-mono">{{ formatCurrency(line.amount) }}</td>
              </tr>
            </tbody>
            <tfoot>
              <tr class="border-t border-[hsl(var(--border))] font-semibold">
                <td colspan="4" class="px-4 py-2">{{ t('ec_total') }}</td>
                <td class="px-4 py-2 text-right font-mono">{{ formatCurrency(claim.total) }}</td>
              </tr>
            </tfoot>
          </table>
        </CardContent>
      </Card>

      <div class="grid gap-6 md:grid-cols-2">
        <Card>
          <CardHeader><CardTitle>{{ t('ec_attachments') }}</CardTitle></CardHeader>
          <CardContent class="space-y-2">
            <a
              v-for="file in claim.attachments"
              :key="file.index"
              :href="`/expense-claims/${claim.id}/attachments/${file.index}`"
              class="flex items-center gap-2 text-sm text-[hsl(var(--primary))] hover:underline"
            >
              <Paperclip class="h-4 w-4" />{{ file.name }}
            </a>
            <template v-if="canAttach">
              <input ref="fileInput" type="file" accept=".pdf,.jpg,.jpeg,.png,.heic" class="hidden" @change="upload" />
              <Button variant="outline" size="sm" @click="fileInput?.click()"><Paperclip class="mr-2 h-4 w-4" />{{ t('ec_attach') }}</Button>
            </template>
          </CardContent>
        </Card>

        <Card>
          <CardHeader><CardTitle>{{ t('ec_entries') }}</CardTitle></CardHeader>
          <CardContent class="space-y-2 text-sm">
            <p v-if="claim.is_booked" class="text-[hsl(var(--muted-foreground))]">{{ t('ec_booked_to') }} {{ claim.liability_account_code }}</p>
            <p v-else-if="claim.liability_account_code" class="text-[hsl(var(--destructive))]">{{ t('ec_entry_lost') }}</p>
            <Link
              v-for="entry in entries"
              :key="entry.id"
              :href="`/accounting/journal-entries/${entry.id}`"
              class="block text-[hsl(var(--primary))] hover:underline"
            >
              {{ entry.reference }} · {{ formatDate(entry.date) }}<span v-if="!entry.is_posted"> · {{ t('ec_entry_draft') }}</span>
            </Link>
            <p v-if="claim.notes" class="whitespace-pre-line pt-2">{{ claim.notes }}</p>
          </CardContent>
        </Card>
      </div>
    </div>

    <Modal :open="payOpen" :title="t('ec_pay_bank')" size="sm" @close="payOpen = false">
      <form class="space-y-4" @submit.prevent="pay">
        <FormInput v-model="payForm.date" id="ec-payForm-date" type="date" :label="t('ec_payment_date')" :error="payForm.errors.date" required />
        <FormSelect v-model="payForm.account_code" id="ec-payForm-account" :label="t('ec_pay_account')" :options="accountOptions" :error="payForm.errors.account_code" required />
        <div class="flex justify-end gap-2">
          <Button type="button" variant="outline" @click="payOpen = false">{{ t('cancel') }}</Button>
          <Button type="submit" :loading="payForm.processing">{{ t('ec_confirm') }}</Button>
        </div>
      </form>
    </Modal>

    <ConfirmDialog
      :open="confirmDelete"
      :title="t('ec_delete_claim')"
      :message="t('ec_delete_claim_confirm')"
      @confirm="destroy"
      @cancel="confirmDelete = false"
    />
  </AppLayout>
</template>
