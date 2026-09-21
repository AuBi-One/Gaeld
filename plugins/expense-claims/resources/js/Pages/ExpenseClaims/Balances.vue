<script setup>
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
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
import { useTranslations } from '@/lib/useTranslations'
import { useFormatters } from '@/lib/useFormatters'
import { ArrowRightLeft, Landmark, Undo2 } from 'lucide-vue-next'

const { t } = useTranslations()
const { formatCurrency, formatDate } = useFormatters()

const props = defineProps({
  date: { type: String, required: true },
  people: { type: Array, default: () => [] },
  debts: { type: Array, default: () => [] },
  canManage: { type: Boolean, default: false },
})

const asOf = ref(props.date)
const converting = ref(null)
const cancelling = ref(null)
const repaying = ref(null)
const repayForm = useForm({ date: new Date().toISOString().slice(0, 10), amount: '' })

function changeDate() {
  router.get('/expense-balances', { date: asOf.value }, { preserveState: true, replace: true })
}

function convert() {
  router.post(`/expense-balances/people/${converting.value.id}/convert`, { date: props.date }, {
    preserveScroll: true,
    onFinish: () => { converting.value = null },
  })
}

function cancelDebt() {
  router.delete(`/expense-balances/debts/${cancelling.value.id}`, {
    preserveScroll: true,
    onFinish: () => { cancelling.value = null },
  })
}

function openRepay(debt) {
  repayForm.amount = debt.remaining
  repaying.value = debt
}

function repay() {
  repayForm.post(`/expense-balances/debts/${repaying.value.id}/repay`, {
    preserveScroll: true,
    onSuccess: () => { repaying.value = null },
  })
}
</script>

<template>
  <AppLayout :title="t('ec_nav_balances')" help-page="expenses">
    <p class="mb-6 max-w-3xl text-sm text-[hsl(var(--muted-foreground))]">{{ t('ec_balances_intro') }}</p>

    <div class="space-y-6">
      <Card>
        <CardHeader>
          <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <CardTitle>{{ t('ec_open_claims') }}</CardTitle>
            <div class="flex items-end gap-2">
              <FormInput v-model="asOf" id="ec-asOf" type="date" :label="t('ec_as_of')" />
              <Button variant="outline" @click="changeDate">{{ t('apply') }}</Button>
            </div>
          </div>
        </CardHeader>
        <CardContent class="overflow-x-auto p-0">
          <table class="w-full min-w-[640px] text-sm">
            <thead class="text-left text-xs uppercase text-[hsl(var(--muted-foreground))]">
              <tr>
                <th class="px-4 py-2">{{ t('ec_person') }}</th>
                <th class="px-4 py-2 text-right">{{ t('ec_open_claims') }}</th>
                <th class="px-4 py-2 text-right">{{ t('ec_up_to_date') }}</th>
                <th class="px-4 py-2 text-right">{{ t('ec_debt_balance') }}</th>
                <th class="px-4 py-2" />
              </tr>
            </thead>
            <tbody>
              <tr v-for="person in people" :key="person.id" class="border-t border-[hsl(var(--border))]">
                <td class="px-4 py-2">
                  <a :href="`/expense-claims?person_id=${person.id}`" class="hover:underline">{{ person.name }}</a>
                  <Badge v-if="person.is_owner" variant="outline" class="ml-2">{{ t('ec_owner') }}</Badge>
                </td>
                <td class="px-4 py-2 text-right font-mono">{{ formatCurrency(person.open_total) }} <span class="text-xs text-[hsl(var(--muted-foreground))]">({{ person.open_count }})</span></td>
                <td class="px-4 py-2 text-right font-mono">{{ formatCurrency(person.convertible_total) }}</td>
                <td class="px-4 py-2 text-right font-mono">{{ formatCurrency(person.debt_total) }}</td>
                <td class="px-4 py-2 text-right">
                  <Button v-if="canManage && Number(person.convertible_total) > 0" size="sm" variant="outline" @click="converting = person">
                    <ArrowRightLeft class="mr-2 h-4 w-4" />{{ t('ec_convert_to_debt') }}
                  </Button>
                </td>
              </tr>
            </tbody>
          </table>
        </CardContent>
      </Card>

      <Card>
        <CardHeader><CardTitle>{{ t('ec_debts') }}</CardTitle></CardHeader>
        <CardContent class="overflow-x-auto p-0">
          <p v-if="!debts.length" class="px-4 pb-4 text-sm text-[hsl(var(--muted-foreground))]">{{ t('ec_no_debts') }}</p>
          <table v-else class="w-full min-w-[640px] text-sm">
            <thead class="text-left text-xs uppercase text-[hsl(var(--muted-foreground))]">
              <tr>
                <th class="px-4 py-2">{{ t('date') }}</th>
                <th class="px-4 py-2">{{ t('ec_person') }}</th>
                <th class="px-4 py-2">{{ t('account') }}</th>
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
                  <p v-for="(r, i) in debt.repayments" :key="i" class="text-xs text-[hsl(var(--muted-foreground))]">
                    {{ formatDate(r.date) }} · {{ t(`ec_via_${r.via}`) }} · {{ formatCurrency(r.amount) }}
                  </p>
                </td>
                <td class="px-4 py-2 font-mono">{{ debt.account_code }}</td>
                <td class="px-4 py-2 text-right font-mono">{{ formatCurrency(debt.amount) }}</td>
                <td class="px-4 py-2 text-right font-mono">{{ formatCurrency(debt.remaining) }}</td>
                <td class="whitespace-nowrap px-4 py-2 text-right">
                  <template v-if="canManage">
                    <Button v-if="Number(debt.remaining) > 0" size="sm" variant="outline" @click="openRepay(debt)">
                      <Landmark class="mr-2 h-4 w-4" />{{ t('ec_repay_bank') }}
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
      :open="!!converting"
      :title="t('ec_convert_to_debt')"
      :message="converting ? t('ec_convert_confirm', { name: converting.name, date: formatDate(date), amount: converting.convertible_total }) : ''"
      :confirm-label="t('ec_confirm')"
      confirm-variant="default"
      @confirm="convert"
      @cancel="converting = null"
    />

    <ConfirmDialog
      :open="!!cancelling"
      :title="t('ec_cancel_debt')"
      :confirm-label="t('ec_confirm')"
      @confirm="cancelDebt"
      @cancel="cancelling = null"
    />

    <Modal :open="!!repaying" :title="t('ec_repay_bank')" size="sm" @close="repaying = null">
      <form class="space-y-4" @submit.prevent="repay">
        <FormInput v-model="repayForm.date" id="ec-repayForm-date" type="date" :label="t('ec_payment_date')" :error="repayForm.errors.date" required />
        <FormInput v-model="repayForm.amount" id="ec-repayForm-amount" type="number" step="0.01" min="0" :label="t('ec_amount')" :error="repayForm.errors.amount" required />
        <div class="flex justify-end gap-2">
          <Button type="button" variant="outline" @click="repaying = null">{{ t('cancel') }}</Button>
          <Button type="submit" :loading="repayForm.processing">{{ t('ec_confirm') }}</Button>
        </div>
      </form>
    </Modal>
  </AppLayout>
</template>
