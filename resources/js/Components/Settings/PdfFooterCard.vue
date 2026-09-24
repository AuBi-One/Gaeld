<script setup>
import { useForm } from '@inertiajs/vue3'
import Card from '@/Components/UI/Card.vue'
import CardHeader from '@/Components/UI/CardHeader.vue'
import CardTitle from '@/Components/UI/CardTitle.vue'
import CardDescription from '@/Components/UI/CardDescription.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import Button from '@/Components/UI/Button.vue'
import FormInput from '@/Components/UI/FormInput.vue'
import { useTranslations } from '@/lib/useTranslations'

const props = defineProps({
  organization: { type: Object, required: true },
})

const { t } = useTranslations()

const form = useForm({
  pdf_footer_text: props.organization.pdf_footer_text || '',
})

function submit() {
  form.put('/settings/pdf-footer', { preserveScroll: true })
}
</script>

<template>
  <Card class="mb-6">
    <CardHeader>
      <CardTitle>{{ t('settings_pdf_footer_title') }}</CardTitle>
      <CardDescription>{{ t('settings_pdf_footer_desc') }}</CardDescription>
    </CardHeader>
    <CardContent>
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput
          id="pdf_footer_text"
          v-model="form.pdf_footer_text"
          :label="t('settings_pdf_footer_label')"
          :placeholder="t('settings_pdf_footer_placeholder')"
          :error="form.errors.pdf_footer_text"
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
