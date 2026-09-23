<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue'
import { ChevronDown, X } from 'lucide-vue-next'
import { cn } from '@/lib/utils'
import { useTranslations } from '@/lib/useTranslations'

// Dropdown of checkboxes with a "contains" search on label and value.
// v-model is an array of option values (strings).
const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  options: { type: Array, default: () => [] }, // [{ value, label, group? }]
  groupKey: { type: String, default: '' },
  label: { type: String, default: '' },
  id: { type: String, default: '' },
  placeholder: { type: String, default: '' },
  searchPlaceholder: { type: String, default: '' },
  emptyText: { type: String, default: '' },
  class: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue'])
const { t } = useTranslations()

const open = ref(false)
const query = ref('')
const triggerRef = ref(null)
const searchRef = ref(null)
const containerRef = ref(null)

const selected = computed(() => new Set(props.modelValue.map(String)))

const filtered = computed(() => {
  const q = query.value.trim().toLowerCase()
  if (!q) return props.options
  return props.options.filter(o =>
    String(o.label).toLowerCase().includes(q) || String(o.value).toLowerCase().includes(q))
})

// Options grouped in their given order: [{ label, options }]
const groups = computed(() => {
  const out = []
  for (const option of filtered.value) {
    const g = props.groupKey ? option[props.groupKey] ?? '' : ''
    if (!out.length || out[out.length - 1].label !== g) out.push({ label: g, options: [] })
    out[out.length - 1].options.push(option)
  }
  return out
})

const summary = computed(() => {
  if (selected.value.size === 1) {
    const only = props.options.find(o => selected.value.has(String(o.value)))
    if (only) return only.label
  }
  return selected.value.size ? t('n_selected', { count: selected.value.size }) : ''
})

function toggle(option) {
  const value = String(option.value)
  emit('update:modelValue', selected.value.has(value)
    ? props.modelValue.map(String).filter(v => v !== value)
    : [...props.modelValue.map(String), value])
}

function clear() {
  emit('update:modelValue', [])
  triggerRef.value?.focus()
}

function toggleOpen() {
  open.value = !open.value
  if (open.value) nextTick(() => searchRef.value?.focus())
}

function close({ focusTrigger = false } = {}) {
  open.value = false
  if (focusTrigger) triggerRef.value?.focus()
}

// Tabbing out closes; a click on a non-focusable part (relatedTarget null) is left to onClickOutside
function onEscape(e) {
  if (!open.value) return
  e.stopPropagation()
  close({ focusTrigger: true })
}

function onFocusOut(e) {
  if (open.value && e.relatedTarget && !containerRef.value?.contains(e.relatedTarget)) close()
}

function onClickOutside(e) {
  if (containerRef.value && !containerRef.value.contains(e.target)) close()
}

onMounted(() => document.addEventListener('mousedown', onClickOutside))
onUnmounted(() => document.removeEventListener('mousedown', onClickOutside))
</script>

<template>
  <div :class="cn('space-y-2', props.class)">
    <label v-if="label" :for="id" class="block text-sm font-medium leading-none">{{ label }}</label>
    <div ref="containerRef" class="relative" @focusout="onFocusOut" @keydown.esc="onEscape">
      <button
        :id="id"
        ref="triggerRef"
        type="button"
        class="flex h-11 w-full items-center rounded-md border border-[hsl(var(--input))] bg-transparent pl-3 pr-14 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[hsl(var(--ring))] sm:h-9"
        :aria-expanded="open"
        @click="toggleOpen"
      >
        <span :class="['truncate text-left', summary ? '' : 'text-[hsl(var(--muted-foreground))]']">
          {{ summary || placeholder || '—' }}
        </span>
      </button>
      <button
        v-if="selected.size"
        type="button"
        class="absolute right-8 top-1/2 -translate-y-1/2 rounded text-[hsl(var(--muted-foreground))] hover:text-[hsl(var(--foreground))]"
        :aria-label="t('clear_filter')"
        @click="clear"
      >
        <X class="h-3.5 w-3.5" />
      </button>
      <ChevronDown class="pointer-events-none absolute right-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-[hsl(var(--muted-foreground))]" />

      <div
        v-if="open"
        class="absolute z-50 mt-1 w-full min-w-[16rem] rounded-md border border-[hsl(var(--border))] bg-[hsl(var(--popover))] p-1 shadow-lg"
      >
        <input
          ref="searchRef"
          v-model="query"
          type="search"
          class="w-full rounded-sm border-0 bg-transparent px-2 py-1.5 text-sm placeholder:text-[hsl(var(--muted-foreground))] focus:outline-none"
          :placeholder="searchPlaceholder || t('search')"
          :aria-label="searchPlaceholder || t('search')"
        />
        <div class="max-h-72 overflow-y-auto">
          <div v-for="group in groups" :key="'g' + group.label" role="group" :aria-label="group.label || undefined">
            <div
              v-if="group.label"
              class="sticky top-0 bg-[hsl(var(--popover))] px-2 pb-1 pt-2 text-xs font-semibold uppercase tracking-wide text-[hsl(var(--muted-foreground))]"
              aria-hidden="true"
            >
              {{ group.label }}
            </div>
            <label
              v-for="option in group.options"
              :key="'o' + option.value"
              class="flex w-full cursor-pointer items-center gap-2 rounded-sm px-2 py-1.5 text-sm hover:bg-[hsl(var(--accent))] focus-within:bg-[hsl(var(--accent))]"
            >
              <input
                type="checkbox"
                class="h-4 w-4 shrink-0 accent-[hsl(var(--primary))]"
                :checked="selected.has(String(option.value))"
                @change="toggle(option)"
              />
              {{ option.label }}
            </label>
          </div>
          <p v-if="filtered.length === 0" class="px-2 py-4 text-center text-sm text-[hsl(var(--muted-foreground))]">
            {{ emptyText || t('no_records') }}
          </p>
        </div>
      </div>
    </div>
  </div>
</template>
