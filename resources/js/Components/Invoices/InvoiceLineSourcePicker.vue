<script setup>
import { computed, ref, watch } from 'vue'
import Modal from '@/Components/UI/Modal.vue'
import Button from '@/Components/UI/Button.vue'
import { useTranslations } from '@/lib/useTranslations'

// Picks a record an invoice line is taken from (a source registered by a plugin, see
// App\Domains\Invoicing\Services\InvoiceLineSources). The source's endpoint returns
// {title, empty, hide_complete_label?, groups: [{label, options: [{source_id, label, reference, line, complete?}]}]}.
// With hide_complete_label, a checkbox hides the options marked complete (remembered per browser and source).
const props = defineProps({
  open: { type: Boolean, default: false },
  source: { type: Object, default: null },
  customerId: { type: [String, Number], default: '' },
})
const emit = defineEmits(['close', 'pick'])
const { t } = useTranslations()

const data = ref(null)
const loading = ref(false)
const failed = ref(false)
const groupIndex = ref(0)
const optionId = ref('')

let request = 0
watch(() => [props.open, props.source?.type, props.customerId], async ([open]) => {
  if (!open || !props.source) return
  const current = ++request
  loading.value = true
  failed.value = false
  data.value = null
  groupIndex.value = 0
  optionId.value = ''
  try {
    const url = new URL(props.source.picker_url, window.location.origin)
    url.searchParams.set('customer_id', String(props.customerId ?? ''))
    const response = await fetch(url, { headers: { Accept: 'application/json' } })
    if (!response.ok) throw new Error(String(response.status))
    const json = await response.json()
    if (current === request) data.value = json
  } catch {
    if (current === request) failed.value = true
  } finally {
    if (current === request) loading.value = false
  }
}, { immediate: true })

const groups = computed(() => data.value?.groups ?? [])
const hideKey = computed(() => `invoice-line-source:${props.source?.type ?? ''}:hide-complete`)
const hideComplete = ref(false)
watch(hideKey, key => { try { hideComplete.value = localStorage.getItem(key) === '1' } catch { hideComplete.value = false } }, { immediate: true })
watch(hideComplete, value => { try { localStorage.setItem(hideKey.value, value ? '1' : '0') } catch { /* private mode */ } })
const options = computed(() => (groups.value[groupIndex.value]?.options ?? []).filter(o => !(hideComplete.value && data.value?.hide_complete_label && o.complete)))
watch(options, list => { optionId.value = list.length === 1 ? String(list[0].source_id) : '' })

function pick() {
  const option = options.value.find(o => String(o.source_id) === optionId.value)
  if (!option) return
  emit('pick', { source: props.source, option })
}
</script>

<template>
  <Modal :open="open" :title="data?.title ?? source?.label ?? ''" size="lg" @close="emit('close')">
    <div class="space-y-4">
      <p v-if="loading" class="text-sm text-[hsl(var(--muted-foreground))]">{{ t('loading') }}</p>
      <p v-else-if="failed" class="text-sm text-[hsl(var(--destructive))]">{{ t('something_went_wrong') }}</p>
      <p v-else-if="!groups.length" class="text-sm text-[hsl(var(--muted-foreground))]">{{ data?.empty }}</p>
      <template v-else>
        <select
          id="line-source-group"
          v-model.number="groupIndex"
          :aria-label="data?.title"
          class="w-full rounded-md border border-[hsl(var(--input))] bg-[hsl(var(--background))] px-3 py-2 text-sm"
        >
          <option v-for="(group, i) in groups" :key="i" :value="i">{{ group.label }}</option>
        </select>
        <label v-if="data?.hide_complete_label" class="flex items-center gap-2 text-sm">
          <input id="line-source-hide-complete" v-model="hideComplete" type="checkbox" class="h-4 w-4" />
          {{ data.hide_complete_label }}
        </label>
        <p v-if="!options.length" class="text-sm text-[hsl(var(--muted-foreground))]">{{ data?.empty }}</p>
        <div class="max-h-80 space-y-1 overflow-y-auto">
          <label
            v-for="option in options"
            :key="option.source_id"
            class="flex cursor-pointer items-start gap-2 rounded-md border border-[hsl(var(--border))] p-2 text-sm hover:bg-[hsl(var(--accent))]"
          >
            <input v-model="optionId" type="radio" name="line-source-option" :value="String(option.source_id)" class="mt-1" />
            <span class="whitespace-pre-line">{{ option.label }}</span>
          </label>
        </div>
      </template>
      <div class="flex justify-end gap-2">
        <Button type="button" variant="outline" @click="emit('close')">{{ t('cancel') }}</Button>
        <Button id="line-source-add" type="button" :disabled="!optionId" @click="pick">{{ t('add_line') }}</Button>
      </div>
    </div>
  </Modal>
</template>
