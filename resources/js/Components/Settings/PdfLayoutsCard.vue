<script setup>
import { useForm } from '@inertiajs/vue3'
import Card from '@/Components/UI/Card.vue'
import CardHeader from '@/Components/UI/CardHeader.vue'
import CardTitle from '@/Components/UI/CardTitle.vue'
import CardDescription from '@/Components/UI/CardDescription.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import Button from '@/Components/UI/Button.vue'
import FormSelect from '@/Components/UI/FormSelect.vue'
import { useTranslations } from '@/lib/useTranslations'

// Layouts registered by plugins (App\Support\Pdf\PdfLayouts::forSettings); '' = standard layout.
const props = defineProps({
  layouts: { type: Array, required: true },
})

const { t } = useTranslations()

const form = useForm({
  pdf_layouts: Object.fromEntries(props.layouts.map((doc) => [doc.document, doc.current || ''])),
})

function options(doc) {
  return [
    { value: '', label: t('pdf_layout_standard') },
    ...doc.options.map((option) => ({ value: option.key, label: option.label })),
  ]
}

function submit() {
  form.put('/settings/pdf-layouts', { preserveScroll: true })
}
</script>

<template>
  <Card class="mb-6">
    <CardHeader>
      <CardTitle>{{ t('settings_pdf_layouts_title') }}</CardTitle>
      <CardDescription>{{ t('settings_pdf_layouts_desc') }}</CardDescription>
    </CardHeader>
    <CardContent>
      <form class="space-y-4" @submit.prevent="submit">
        <FormSelect
          v-for="doc in layouts"
          :id="`pdf_layout_${doc.document}`"
          :key="doc.document"
          v-model="form.pdf_layouts[doc.document]"
          :label="t(`pdf_layout_document_${doc.document}`)"
          :options="options(doc)"
          :error="form.errors[`pdf_layouts.${doc.document}`]"
        />
        <div class="flex justify-end">
          <Button type="submit" :disabled="form.processing" :loading="form.processing">
            {{ t('save_changes') }}
          </Button>
        </div>
      </form>
    </CardContent>
  </Card>
</template>
