<script setup>
import { computed, ref } from 'vue'
import { router, useForm, usePage, Link } from '@inertiajs/vue3'
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
import { useTranslations } from '@/lib/useTranslations'
import { useFormatters } from '@/lib/useFormatters'
import { Check, Copy, Download, FileText, LayoutTemplate, Pencil, RotateCcw, Send, Trash2, Undo2, X } from 'lucide-vue-next'

const { t } = useTranslations()
const { formatCurrency, formatDate } = useFormatters()

const props = defineProps({
  offer: { type: Object, required: true },
  canManage: { type: Boolean, default: false },
  canDelete: { type: Boolean, default: false },
})

const page = usePage()
const statusError = computed(() => page.props.errors?.status)
const statusVariant = { draft: 'secondary', sent: 'info', accepted: 'success', refused: 'destructive', superseded: 'outline' }
const busy = ref(false)
const confirmDelete = ref(false)
const templateOpen = ref(false)
const templateForm = useForm({ name: props.offer.title })
const previewKey = ref(Date.now())

function act(action) {
  busy.value = true
  router.post(`/offers/${props.offer.id}/${action}`, {}, {
    preserveScroll: true,
    onFinish: () => { busy.value = false; previewKey.value = Date.now() },
  })
}

function destroy() {
  router.delete(`/offers/${props.offer.id}`, { onFinish: () => { confirmDelete.value = false } })
}

function saveTemplate() {
  templateForm.post(`/offers/${props.offer.id}/save-as-template`, { onSuccess: () => { templateOpen.value = false } })
}

const recipientLines = computed(() => {
  const r = props.offer.recipient ?? {}
  return [r.attention, r.email, r.address, [r.postal_code, r.city].filter(Boolean).join(' ')].filter(Boolean)
})
// Invoiced / remaining columns once the offer is accepted or has invoices.
const tracking = computed(() => props.offer.status === 'accepted' || props.offer.invoices.length > 0)
const hasLiveInvoice = computed(() => props.offer.invoices.some(i => i.status !== 'cancelled'))
const qty = value => Number(value).toLocaleString('de-CH', { maximumFractionDigits: 2 })
</script>

<template>
  <AppLayout :title="`${t('of_title_offers')} ${offer.number}`" help-page="invoices">
    <Breadcrumb
      :items="[
        { label: t('of_title_offers'), href: '/offers' },
        { label: offer.number },
      ]"
      class="mb-4"
    />

    <div class="grid gap-6 2xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
      <div class="space-y-6">
        <Card>
          <CardHeader>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
              <div>
                <CardTitle>{{ offer.number }} — {{ offer.title }}</CardTitle>
                <p class="mt-1 text-sm text-[hsl(var(--muted-foreground))]">
                  {{ formatDate(offer.offer_date) }}
                  <span v-if="offer.valid_until"> · {{ t('of_valid_until') }} {{ formatDate(offer.valid_until) }}</span>
                  <span v-if="offer.source === 'airtable'"> · {{ t('of_migrated') }}</span>
                </p>
                <p class="mt-1 space-x-3 text-sm text-[hsl(var(--muted-foreground))]">
                  <span v-if="offer.sent_at">{{ t('of_sent_on', { date: formatDate(offer.sent_at) }) }}</span>
                  <span v-if="offer.decided_at">{{ t('of_decided_on', { date: formatDate(offer.decided_at) }) }}</span>
                </p>
              </div>
              <div class="flex items-center gap-2">
                <Badge :variant="statusVariant[offer.status] ?? 'secondary'">{{ t(`of_status_${offer.status}`) }}</Badge>
                <Badge v-if="offer.expired" variant="warning">{{ t('of_status_expired') }}</Badge>
                <span class="font-mono text-lg font-semibold">{{ formatCurrency(offer.total, offer.currency) }}</span>
              </div>
            </div>
          </CardHeader>
          <CardContent class="space-y-3">
            <p v-if="statusError" class="text-sm text-[hsl(var(--destructive))]">{{ statusError }}</p>
            <div v-if="canManage" class="flex flex-wrap gap-2">
              <template v-if="offer.status === 'draft'">
                <Button :loading="busy" @click="act('send')"><Send class="mr-2 h-4 w-4" />{{ t('of_action_send') }}</Button>
                <Button as="a" :href="`/offers/${offer.id}/edit`" variant="outline"><Pencil class="mr-2 h-4 w-4" />{{ t('edit') }}</Button>
                <Button v-if="canDelete" variant="ghost" @click="confirmDelete = true"><Trash2 class="mr-2 h-4 w-4" />{{ t('delete') }}</Button>
              </template>
              <template v-else-if="offer.status === 'sent'">
                <Button :loading="busy" @click="act('accept')"><Check class="mr-2 h-4 w-4" />{{ t('of_action_accept') }}</Button>
                <Button variant="outline" :loading="busy" @click="act('refuse')"><X class="mr-2 h-4 w-4" />{{ t('of_action_refuse') }}</Button>
                <Button variant="outline" :loading="busy" @click="act('revise')"><Copy class="mr-2 h-4 w-4" />{{ t('of_action_revise') }}</Button>
                <Button variant="ghost" :loading="busy" @click="act('revert')"><Undo2 class="mr-2 h-4 w-4" />{{ t('of_action_revert') }}</Button>
              </template>
              <template v-else-if="offer.status === 'accepted'">
                <Button v-if="offer.can_invoice" as="a" :href="`/offers/${offer.id}/invoice`"><FileText class="mr-2 h-4 w-4" />{{ t('of_action_invoice') }}</Button>
                <Button v-if="!hasLiveInvoice" variant="ghost" :loading="busy" @click="act('reopen')"><RotateCcw class="mr-2 h-4 w-4" />{{ t('of_action_reopen') }}</Button>
              </template>
              <template v-else-if="offer.status === 'refused'">
                <Button variant="outline" :loading="busy" @click="act('revise')"><Copy class="mr-2 h-4 w-4" />{{ t('of_action_revise') }}</Button>
                <Button variant="ghost" :loading="busy" @click="act('reopen')"><RotateCcw class="mr-2 h-4 w-4" />{{ t('of_action_reopen') }}</Button>
              </template>
              <Button variant="ghost" @click="templateOpen = true"><LayoutTemplate class="mr-2 h-4 w-4" />{{ t('of_action_save_template') }}</Button>
            </div>
            <p v-if="canManage && offer.status === 'draft'" class="text-xs text-[hsl(var(--muted-foreground))]">{{ t('of_send_hint') }}</p>
            <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
              <Link v-if="offer.supersedes" :href="`/offers/${offer.supersedes.id}`" class="text-[hsl(var(--primary))] hover:underline">{{ t('of_supersedes', { number: offer.supersedes.number }) }}</Link>
              <Link v-if="offer.superseded_by" :href="`/offers/${offer.superseded_by.id}`" class="text-[hsl(var(--primary))] hover:underline">{{ t('of_superseded_by', { number: offer.superseded_by.number }) }}</Link>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader><CardTitle>{{ t('of_recipient') }}</CardTitle></CardHeader>
          <CardContent class="text-sm">
            <Link v-if="offer.contact_uuid" :href="`/contacts/${offer.contact_uuid}`" class="font-medium text-[hsl(var(--primary))] hover:underline">{{ offer.recipient?.company }}</Link>
            <p v-else class="font-medium">{{ offer.recipient?.company }}</p>
            <p v-for="(line, i) in recipientLines" :key="i">{{ line }}</p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader><CardTitle>{{ t('of_lines') }}</CardTitle></CardHeader>
          <CardContent class="overflow-x-auto p-0">
            <table class="w-full text-sm">
              <thead class="text-left text-xs uppercase text-[hsl(var(--muted-foreground))]">
                <tr>
                  <th class="px-4 py-2">{{ t('of_pos') }}</th>
                  <th class="px-4 py-2">{{ t('of_description') }}</th>
                  <th class="px-4 py-2 text-right">{{ t('of_quantity') }}</th>
                  <th class="px-4 py-2">{{ t('of_unit') }}</th>
                  <th class="px-4 py-2 text-right">{{ t('of_unit_price') }}</th>
                  <th class="px-4 py-2 text-right">{{ t('of_amount') }}</th>
                  <template v-if="tracking">
                    <th class="px-4 py-2 text-right">{{ t('of_invoiced_amount') }}</th>
                    <th class="px-4 py-2 text-right">{{ t('of_remaining') }}</th>
                  </template>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(line, i) in offer.lines" :key="i" class="border-t border-[hsl(var(--border))]">
                  <td class="px-4 py-2 align-top">{{ line.label }}</td>
                  <td class="whitespace-pre-line px-4 py-2" :class="line.type === 'text' ? 'italic' : ''" :colspan="line.type === 'text' ? (tracking ? 7 : 5) : 1">{{ line.description }}</td>
                  <template v-if="line.type === 'item'">
                    <td class="px-4 py-2 text-right font-mono align-top">{{ qty(line.quantity) }}</td>
                    <td class="px-4 py-2 align-top">{{ line.unit }}</td>
                    <td class="px-4 py-2 text-right font-mono align-top">{{ formatCurrency(line.unit_price, offer.currency) }}</td>
                    <td class="px-4 py-2 text-right font-mono align-top">{{ formatCurrency(line.amount, offer.currency) }}</td>
                    <template v-if="tracking">
                      <td class="px-4 py-2 text-right font-mono align-top">{{ formatCurrency(offer.balance[line.id]?.invoiced ?? 0, offer.currency) }}</td>
                      <td class="px-4 py-2 text-right font-mono align-top">{{ formatCurrency(offer.balance[line.id]?.remaining ?? 0, offer.currency) }}</td>
                    </template>
                  </template>
                </tr>
              </tbody>
              <tfoot class="text-sm">
                <tr class="border-t border-[hsl(var(--border))]"><td colspan="5" class="px-4 py-1 text-right">{{ t('of_subtotal') }}</td><td class="px-4 py-1 text-right font-mono">{{ formatCurrency(offer.subtotal, offer.currency) }}</td>
                  <template v-if="tracking">
                    <td class="px-4 py-1 text-right font-mono font-semibold">{{ formatCurrency(offer.invoiced, offer.currency) }}</td>
                    <td class="px-4 py-1 text-right font-mono font-semibold">{{ formatCurrency(offer.remaining, offer.currency) }}</td>
                  </template>
                </tr>
                <tr v-if="offer.vat_rate !== null"><td colspan="5" class="px-4 py-1 text-right">{{ t('of_vat') }} {{ Number(offer.vat_rate) }} %</td><td class="px-4 py-1 text-right font-mono">{{ formatCurrency(offer.vat_amount, offer.currency) }}</td><td v-if="tracking" colspan="2" /></tr>
                <tr class="font-semibold"><td colspan="5" class="px-4 py-2 text-right">{{ t('of_total') }}</td><td class="px-4 py-2 text-right font-mono">{{ formatCurrency(offer.total, offer.currency) }}</td><td v-if="tracking" colspan="2" /></tr>
              </tfoot>
            </table>
          </CardContent>
        </Card>

        <Card v-if="tracking">
          <CardHeader><CardTitle>{{ t('of_invoices_title') }}</CardTitle></CardHeader>
          <CardContent class="p-0">
            <p v-if="!offer.invoices.length" class="px-6 pb-4 text-sm text-[hsl(var(--muted-foreground))]">{{ t('of_no_invoices_yet') }}</p>
            <table v-else class="w-full text-sm">
              <thead class="text-left text-xs uppercase text-[hsl(var(--muted-foreground))]">
                <tr>
                  <th class="px-4 py-2">{{ t('of_number') }}</th>
                  <th class="px-4 py-2">{{ t('date') }}</th>
                  <th class="px-4 py-2">{{ t('status') }}</th>
                  <th class="px-4 py-2 text-right">{{ t('of_net_from_offer') }}</th>
                  <th class="px-4 py-2 text-right">{{ t('of_total') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="invoice in offer.invoices" :key="invoice.id" class="border-t border-[hsl(var(--border))]" :class="invoice.status === 'cancelled' ? 'text-[hsl(var(--muted-foreground))] line-through' : ''">
                  <td class="px-4 py-2"><Link :href="`/invoices/${invoice.id}`" class="font-mono text-[hsl(var(--primary))] hover:underline">{{ invoice.number }}</Link></td>
                  <td class="px-4 py-2">{{ formatDate(invoice.issue_date) }}</td>
                  <td class="px-4 py-2">{{ t(`invoice_status_${invoice.status}`) }}</td>
                  <td class="px-4 py-2 text-right font-mono">{{ formatCurrency(invoice.net_from_offer, offer.currency) }}</td>
                  <td class="px-4 py-2 text-right font-mono">{{ formatCurrency(invoice.total, offer.currency) }}</td>
                </tr>
              </tbody>
            </table>
          </CardContent>
        </Card>

        <Card v-if="offer.notes">
          <CardHeader><CardTitle>{{ t('of_notes') }}</CardTitle></CardHeader>
          <CardContent class="whitespace-pre-line text-sm">{{ offer.notes }}</CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <div class="flex items-center justify-between gap-2">
            <div>
              <CardTitle>{{ t('of_document') }}</CardTitle>
              <p class="mt-1 text-xs text-[hsl(var(--muted-foreground))]">{{ offer.has_document ? t('of_stored_document') : t('of_live_preview') }}</p>
            </div>
            <Button as="a" :href="`/offers/${offer.id}/document?download=1`" variant="outline" size="sm"><Download class="mr-2 h-4 w-4" />{{ t('of_action_download') }}</Button>
          </div>
        </CardHeader>
        <CardContent>
          <iframe :key="previewKey" :src="`/offers/${offer.id}/document`" class="h-[800px] w-full rounded border border-[hsl(var(--border))]" :title="offer.number" />
        </CardContent>
      </Card>
    </div>

    <Modal :open="templateOpen" :title="t('of_action_save_template')" size="sm" @close="templateOpen = false">
      <form class="space-y-4" @submit.prevent="saveTemplate">
        <FormInput v-model="templateForm.name" id="of-template-name" :label="t('of_template_name')" :error="templateForm.errors.name" required />
        <div class="flex justify-end gap-2">
          <Button type="button" variant="outline" @click="templateOpen = false">{{ t('cancel') }}</Button>
          <Button type="submit" :loading="templateForm.processing">{{ t('save') }}</Button>
        </div>
      </form>
    </Modal>

    <ConfirmDialog
      :open="confirmDelete"
      :title="t('of_delete_offer')"
      :message="t('of_delete_offer_confirm')"
      @confirm="destroy"
      @cancel="confirmDelete = false"
    />
  </AppLayout>
</template>
