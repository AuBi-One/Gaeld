<script setup>
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/AppLayout.vue'
import Button from '@/Components/UI/Button.vue'
import Card from '@/Components/UI/Card.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import CardHeader from '@/Components/UI/CardHeader.vue'
import CardTitle from '@/Components/UI/CardTitle.vue'
import FormInput from '@/Components/UI/FormInput.vue'
import FormSelect from '@/Components/UI/FormSelect.vue'
import FormTextarea from '@/Components/UI/FormTextarea.vue'
import { useTranslations } from '@/lib/useTranslations'
import { computed, ref } from 'vue'

const props = defineProps({
  summary: {
    type: Object,
    required: true,
  },
})

const { t } = useTranslations()

const form = useForm({
  name: '',
  description: '',
  state_translator: '',
  unit: '',
  threshold: '',
  threshold_direction: '',
  source_reference: '',
})

const activityForm = useForm({
  name: '',
  purpose: '',
  inputs: '',
  outputs: '',
  source_reference: '',
})

const observationForm = useForm({
  preservation_capital_id: '',
  observed_on: '',
  value: '',
  unit: '',
  source_reference: '',
  confidence: '',
  notes: '',
})

const impactForm = useForm({
  preservation_capital_id: '',
  activity_name: '',
  impact_type: '',
  impact_direction: '',
  occurred_on: '',
  value: '',
  unit: '',
  source_reference: '',
  notes: '',
})

const actionForm = useForm({
  preservation_capital_id: '',
  title: '',
  description: '',
  action_type: '',
  status: 'planned',
  due_on: '',
  source_reference: '',
})

const editingObservationId = ref(null)
const editObservationForm = useForm({
  observed_on: '',
  value: '',
  unit: '',
  source_reference: '',
  confidence: '',
  notes: '',
})

const editingActionId = ref(null)
const editActionForm = useForm({
  title: '',
  description: '',
  action_type: '',
  status: 'planned',
  due_on: '',
  source_reference: '',
})

const thresholdOptions = [
  { value: '', label: t('none') },
  { value: 'maximum', label: t('impact_accounting_threshold_maximum') },
  { value: 'minimum', label: t('impact_accounting_threshold_minimum') },
  { value: 'target', label: t('impact_accounting_threshold_target') },
]

const capitalOptions = computed(() => props.summary.capitals?.map(capital => ({
  value: capital.id,
  label: capital.name,
})) ?? [])

const confidenceOptions = [
  { value: '', label: t('none') },
  { value: 'measured', label: t('impact_accounting_confidence_measured') },
  { value: 'estimated', label: t('impact_accounting_confidence_estimated') },
  { value: 'professional', label: t('impact_accounting_confidence_professional') },
  { value: 'judgment', label: t('impact_accounting_confidence_judgment') },
]

const impactDirectionOptions = [
  { value: '', label: t('none') },
  { value: 'adverse', label: t('impact_accounting_adverse') },
  { value: 'beneficial', label: t('impact_accounting_beneficial') },
  { value: 'uncertain', label: t('impact_accounting_uncertain') },
]

const actionTypeOptions = [
  { value: '', label: t('none') },
  { value: 'prevention', label: t('impact_accounting_prevention') },
  { value: 'restoration', label: t('impact_accounting_restoration') },
  { value: 'avoidance', label: t('impact_accounting_avoidance') },
]

const actionStatusOptions = [
  { value: 'planned', label: t('impact_accounting_status_planned') },
  { value: 'in_progress', label: t('impact_accounting_status_in_progress') },
  { value: 'completed', label: t('impact_accounting_status_completed') },
  { value: 'cancelled', label: t('impact_accounting_status_cancelled') },
]

function createCapital() {
  form.post('/impact-accounting/capitals', {
    preserveScroll: true,
    onSuccess: () => form.reset(),
  })
}

function recordActivity() {
  activityForm.post('/impact-accounting/activities', {
    preserveScroll: true,
    onSuccess: () => activityForm.reset(),
  })
}

function recordObservation() {
  observationForm.post(`/impact-accounting/capitals/${observationForm.preservation_capital_id}/observations`, {
    preserveScroll: true,
    onSuccess: () => observationForm.reset(
      'observed_on',
      'value',
      'unit',
      'source_reference',
      'confidence',
      'notes',
    ),
  })
}

function recordImpact() {
  impactForm.post(`/impact-accounting/capitals/${impactForm.preservation_capital_id}/impacts`, {
    preserveScroll: true,
    onSuccess: () => impactForm.reset(
      'activity_name',
      'impact_type',
      'impact_direction',
      'occurred_on',
      'value',
      'unit',
      'source_reference',
      'notes',
    ),
  })
}

function planAction() {
  actionForm.post(`/impact-accounting/capitals/${actionForm.preservation_capital_id}/actions`, {
    preserveScroll: true,
    onSuccess: () => actionForm.reset(
      'title',
      'description',
      'action_type',
      'status',
      'due_on',
      'source_reference',
    ),
  })
}

function beginObservationEdit(observation) {
  editingObservationId.value = observation.id
  editObservationForm.defaults({
    observed_on: observation.observed_on,
    value: observation.value,
    unit: observation.unit ?? '',
    source_reference: observation.source_reference,
    confidence: observation.confidence ?? '',
    notes: observation.notes ?? '',
  })
  editObservationForm.reset()
}

function cancelObservationEdit() {
  editingObservationId.value = null
  editObservationForm.reset()
  editObservationForm.clearErrors()
}

function updateObservation() {
  editObservationForm.put(`/impact-accounting/observations/${editingObservationId.value}`, {
    preserveScroll: true,
    onSuccess: cancelObservationEdit,
  })
}

function beginActionEdit(action) {
  editingActionId.value = action.id
  editActionForm.defaults({
    title: action.title,
    description: action.description ?? '',
    action_type: action.action_type,
    status: action.status,
    due_on: action.due_on ?? '',
    source_reference: action.source_reference ?? '',
  })
  editActionForm.reset()
}

function cancelActionEdit() {
  editingActionId.value = null
  editActionForm.reset()
  editActionForm.clearErrors()
}

function updateAction() {
  editActionForm.put(`/impact-accounting/actions/${editingActionId.value}`, {
    preserveScroll: true,
    onSuccess: cancelActionEdit,
  })
}

function actionLabel(type) {
  return t(`impact_accounting_${type}`)
}
</script>

<template>
  <AppLayout :title="t('impact_accounting')">
    <div class="space-y-6">
      <header class="space-y-2">
        <h1 class="text-2xl font-semibold tracking-tight">{{ t('impact_accounting') }}</h1>
        <p class="max-w-3xl text-sm text-[hsl(var(--muted-foreground))]">
          {{ t('impact_accounting_desc') }}
        </p>
      </header>

      <Card>
        <CardHeader>
          <CardTitle>{{ t('impact_accounting_organization_model') }}</CardTitle>
          <p class="text-sm text-[hsl(var(--muted-foreground))]">
            {{ t('impact_accounting_organization_model_desc') }}
          </p>
        </CardHeader>
        <CardContent class="space-y-6">
          <form class="space-y-5" @submit.prevent="recordActivity">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
              <FormInput
                id="activity_name"
                v-model="activityForm.name"
                :label="t('impact_accounting_activity_name')"
                :error="activityForm.errors.name"
                required
              />
              <FormTextarea
                id="activity_purpose"
                v-model="activityForm.purpose"
                :label="t('impact_accounting_purpose')"
                :error="activityForm.errors.purpose"
                :rows="2"
              />
            </div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
              <FormTextarea
                id="activity_inputs"
                v-model="activityForm.inputs"
                :label="t('impact_accounting_inputs')"
                :error="activityForm.errors.inputs"
              />
              <FormTextarea
                id="activity_outputs"
                v-model="activityForm.outputs"
                :label="t('impact_accounting_outputs')"
                :error="activityForm.errors.outputs"
              />
            </div>
            <FormTextarea
              id="activity_source_reference"
              v-model="activityForm.source_reference"
              :label="t('impact_accounting_source_reference')"
              :error="activityForm.errors.source_reference"
              required
            />
            <div class="flex justify-end">
              <Button type="submit" :disabled="activityForm.processing">
                {{ activityForm.processing ? t('saving') : t('save') }}
              </Button>
            </div>
          </form>

          <div v-if="props.summary.activities?.length" class="space-y-3 border-t border-[hsl(var(--border))] pt-5">
            <h3 class="text-sm font-semibold">{{ t('impact_accounting_activity_history') }}</h3>
            <ul class="grid grid-cols-1 gap-3 md:grid-cols-2">
              <li
                v-for="activity in props.summary.activities"
                :key="activity.id"
                class="rounded-lg border border-[hsl(var(--border))] p-4 text-sm"
              >
                <div class="flex items-start justify-between gap-3">
                  <p class="font-medium">{{ activity.name }}</p>
                  <span class="text-xs text-[hsl(var(--muted-foreground))]">
                    {{ activity.status === 'draft' ? t('impact_accounting_draft') : activity.status }}
                  </span>
                </div>
                <p v-if="activity.purpose" class="mt-2 text-[hsl(var(--muted-foreground))]">{{ activity.purpose }}</p>
                <p class="mt-2 text-xs text-[hsl(var(--muted-foreground))]">{{ activity.source_reference }}</p>
              </li>
            </ul>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader><CardTitle>{{ t('impact_accounting_add_capital') }}</CardTitle></CardHeader>
        <CardContent>
          <form class="space-y-5" @submit.prevent="createCapital">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
              <FormInput
                id="capital_name"
                v-model="form.name"
                :label="t('impact_accounting_capital_name')"
                :error="form.errors.name"
                required
              />
              <FormInput
                id="state_translator"
                v-model="form.state_translator"
                :label="t('impact_accounting_state_translator')"
                :error="form.errors.state_translator"
              />
              <FormInput
                id="unit"
                v-model="form.unit"
                :label="t('impact_accounting_unit')"
                :error="form.errors.unit"
              />
              <FormInput
                id="threshold"
                v-model="form.threshold"
                :label="t('impact_accounting_threshold')"
                :error="form.errors.threshold"
              />
              <FormSelect
                id="threshold_direction"
                v-model="form.threshold_direction"
                :label="t('impact_accounting_threshold_direction')"
                :options="thresholdOptions"
                :error="form.errors.threshold_direction"
              />
            </div>
            <FormTextarea
              id="capital_description"
              v-model="form.description"
              :label="t('description')"
              :error="form.errors.description"
            />
            <FormTextarea
              id="source_reference"
              v-model="form.source_reference"
              :label="t('impact_accounting_source_reference')"
              :error="form.errors.source_reference"
              required
            />
            <div class="flex justify-end">
              <Button type="submit" :disabled="form.processing">
                {{ form.processing ? t('saving') : t('save') }}
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>

      <div
        v-if="!props.summary.capitals?.length"
        class="rounded-xl border border-dashed border-[hsl(var(--border))] p-8 text-center text-sm text-[hsl(var(--muted-foreground))]"
      >
        {{ t('impact_accounting_no_capitals') }}
      </div>

      <div v-else class="grid grid-cols-1 gap-6 xl:grid-cols-2">
        <Card v-for="capital in props.summary.capitals" :key="capital.id">
          <CardHeader>
            <div class="flex items-start justify-between gap-4">
              <CardTitle>{{ capital.name }}</CardTitle>
              <span class="rounded-full border border-[hsl(var(--border))] px-2.5 py-1 text-xs font-medium">
                {{ capital.status }}
              </span>
            </div>
          </CardHeader>
          <CardContent>
            <dl class="grid grid-cols-2 gap-x-6 gap-y-4 text-sm">
              <div>
                <dt class="text-[hsl(var(--muted-foreground))]">{{ t('impact_accounting_latest_observation') }}</dt>
                <dd v-if="capital.latest_observation" class="mt-1 font-medium">
                  {{ capital.latest_observation.observed_on }}:
                  {{ capital.latest_observation.value }}
                  <span v-if="capital.latest_observation.unit" class="font-normal">
                    {{ capital.latest_observation.unit }}
                  </span>
                </dd>
                <dd v-else class="mt-1 text-[hsl(var(--muted-foreground))]">
                  {{ t('impact_accounting_no_observation') }}
                </dd>
              </div>
              <div>
                <dt class="text-[hsl(var(--muted-foreground))]">{{ t('impact_accounting_impacts') }}</dt>
                <dd class="mt-1 font-medium">{{ capital.impact_count }}</dd>
              </div>
              <div>
                <dt class="text-[hsl(var(--muted-foreground))]">{{ t('impact_accounting_open_actions') }}</dt>
                <dd class="mt-1 font-medium">{{ capital.open_action_count }}</dd>
              </div>
              <div>
                <dt class="text-[hsl(var(--muted-foreground))]">{{ t('impact_accounting_actions') }}</dt>
                <dd class="mt-1 flex flex-wrap gap-x-3 gap-y-1 font-medium">
                  <span v-for="(count, type) in capital.action_counts" :key="type">
                    {{ actionLabel(type) }}: {{ count }}
                  </span>
                </dd>
              </div>
            </dl>

            <div v-if="capital.observations?.length" class="mt-6 space-y-3 border-t border-[hsl(var(--border))] pt-4">
              <h3 class="text-sm font-semibold">{{ t('impact_accounting_observation_history') }}</h3>
              <ul class="space-y-3">
                <li
                  v-for="observation in capital.observations"
                  :key="observation.id"
                  class="flex items-start justify-between gap-4 text-sm"
                >
                  <div>
                    <p class="font-medium">
                      {{ observation.observed_on }} · {{ observation.value }}
                      <span v-if="observation.unit" class="font-normal">{{ observation.unit }}</span>
                    </p>
                    <p class="text-xs text-[hsl(var(--muted-foreground))]">
                      {{ observation.source_reference }}
                      <span v-if="observation.confidence"> · {{ observation.confidence }}</span>
                    </p>
                  </div>
                  <Button variant="link" size="sm" @click="beginObservationEdit(observation)">
                    {{ t('edit') }}
                  </Button>
                </li>
              </ul>
            </div>

            <div v-if="capital.impacts?.length" class="mt-6 space-y-3 border-t border-[hsl(var(--border))] pt-4">
              <h3 class="text-sm font-semibold">{{ t('impact_accounting_impact_history') }}</h3>
              <ul class="space-y-3">
                <li v-for="impact in capital.impacts" :key="impact.id" class="text-sm">
                  <p class="font-medium">
                    {{ impact.occurred_on }} · {{ impact.activity_name }} · {{ impact.impact_type }}
                  </p>
                  <p class="text-xs text-[hsl(var(--muted-foreground))]">
                    {{ impact.impact_direction }} · {{ impact.source_reference }}
                  </p>
                </li>
              </ul>
            </div>

            <div v-if="capital.actions?.length" class="mt-6 space-y-3 border-t border-[hsl(var(--border))] pt-4">
              <h3 class="text-sm font-semibold">{{ t('impact_accounting_action_history') }}</h3>
              <ul class="space-y-3">
                <li
                  v-for="action in capital.actions"
                  :key="action.id"
                  class="flex items-start justify-between gap-4 text-sm"
                >
                  <div>
                    <p class="font-medium">{{ action.title }}</p>
                    <p class="text-xs text-[hsl(var(--muted-foreground))]">
                      {{ actionLabel(action.action_type) }} · {{ action.status }}
                      <span v-if="action.due_on"> · {{ action.due_on }}</span>
                    </p>
                  </div>
                  <Button variant="link" size="sm" @click="beginActionEdit(action)">
                    {{ t('edit') }}
                  </Button>
                </li>
              </ul>
            </div>
          </CardContent>
        </Card>
      </div>

      <Card v-if="editingObservationId">
        <CardHeader><CardTitle>{{ t('impact_accounting_edit_observation') }}</CardTitle></CardHeader>
        <CardContent>
          <form class="space-y-5" @submit.prevent="updateObservation">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
              <FormInput
                id="edit_observed_on"
                v-model="editObservationForm.observed_on"
                type="date"
                :label="t('impact_accounting_observed_on')"
                :error="editObservationForm.errors.observed_on"
                required
              />
              <FormInput
                id="edit_observation_value"
                v-model="editObservationForm.value"
                :label="t('impact_accounting_value')"
                :error="editObservationForm.errors.value"
                required
              />
              <FormInput
                id="edit_observation_unit"
                v-model="editObservationForm.unit"
                :label="t('impact_accounting_unit')"
                :error="editObservationForm.errors.unit"
              />
              <FormSelect
                id="edit_observation_confidence"
                v-model="editObservationForm.confidence"
                :label="t('impact_accounting_confidence')"
                :options="confidenceOptions"
                :error="editObservationForm.errors.confidence"
              />
            </div>
            <FormTextarea
              id="edit_observation_source"
              v-model="editObservationForm.source_reference"
              :label="t('impact_accounting_source_reference')"
              :error="editObservationForm.errors.source_reference"
              required
            />
            <FormTextarea
              id="edit_observation_notes"
              v-model="editObservationForm.notes"
              :label="t('impact_accounting_notes')"
              :error="editObservationForm.errors.notes"
            />
            <div class="flex justify-end gap-3">
              <Button type="button" variant="outline" @click="cancelObservationEdit">{{ t('cancel') }}</Button>
              <Button type="submit" :disabled="editObservationForm.processing">
                {{ editObservationForm.processing ? t('saving') : t('save') }}
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>

      <Card v-if="editingActionId">
        <CardHeader><CardTitle>{{ t('impact_accounting_edit_action') }}</CardTitle></CardHeader>
        <CardContent>
          <form class="space-y-5" @submit.prevent="updateAction">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
              <FormInput
                id="edit_action_title"
                v-model="editActionForm.title"
                :label="t('impact_accounting_action_title')"
                :error="editActionForm.errors.title"
                required
              />
              <FormSelect
                id="edit_action_type"
                v-model="editActionForm.action_type"
                :label="t('impact_accounting_action_type')"
                :options="actionTypeOptions"
                :error="editActionForm.errors.action_type"
                required
              />
              <FormSelect
                id="edit_action_status"
                v-model="editActionForm.status"
                :label="t('impact_accounting_action_status')"
                :options="actionStatusOptions"
                :error="editActionForm.errors.status"
                required
              />
              <FormInput
                id="edit_action_due_on"
                v-model="editActionForm.due_on"
                type="date"
                :label="t('impact_accounting_due_on')"
                :error="editActionForm.errors.due_on"
              />
            </div>
            <FormTextarea
              id="edit_action_description"
              v-model="editActionForm.description"
              :label="t('description')"
              :error="editActionForm.errors.description"
            />
            <FormTextarea
              id="edit_action_source"
              v-model="editActionForm.source_reference"
              :label="t('source')"
              :error="editActionForm.errors.source_reference"
            />
            <div class="flex justify-end gap-3">
              <Button type="button" variant="outline" @click="cancelActionEdit">{{ t('cancel') }}</Button>
              <Button type="submit" :disabled="editActionForm.processing">
                {{ editActionForm.processing ? t('saving') : t('save') }}
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>

      <Card v-if="props.summary.capitals?.length">
        <CardHeader><CardTitle>{{ t('impact_accounting_add_observation') }}</CardTitle></CardHeader>
        <CardContent>
          <form class="space-y-5" @submit.prevent="recordObservation">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
              <FormSelect
                id="observation_capital"
                v-model="observationForm.preservation_capital_id"
                :label="t('impact_accounting_capital')"
                :options="capitalOptions"
                :error="observationForm.errors.preservation_capital_id"
                required
              />
              <FormInput
                id="observed_on"
                v-model="observationForm.observed_on"
                type="date"
                :label="t('impact_accounting_observed_on')"
                :error="observationForm.errors.observed_on"
                required
              />
              <FormInput
                id="observation_value"
                v-model="observationForm.value"
                :label="t('impact_accounting_value')"
                :error="observationForm.errors.value"
                required
              />
              <FormInput
                id="observation_unit"
                v-model="observationForm.unit"
                :label="t('impact_accounting_unit')"
                :error="observationForm.errors.unit"
              />
              <FormSelect
                id="observation_confidence"
                v-model="observationForm.confidence"
                :label="t('impact_accounting_confidence')"
                :options="confidenceOptions"
                :error="observationForm.errors.confidence"
              />
            </div>
            <FormTextarea
              id="observation_source_reference"
              v-model="observationForm.source_reference"
              :label="t('impact_accounting_source_reference')"
              :error="observationForm.errors.source_reference"
              required
            />
            <FormTextarea
              id="observation_notes"
              v-model="observationForm.notes"
              :label="t('impact_accounting_notes')"
              :error="observationForm.errors.notes"
            />
            <div class="flex justify-end">
              <Button type="submit" :disabled="observationForm.processing">
                {{ observationForm.processing ? t('saving') : t('save') }}
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>

      <div v-if="props.summary.capitals?.length" class="grid grid-cols-1 gap-6 xl:grid-cols-2">
        <Card>
          <CardHeader><CardTitle>{{ t('impact_accounting_add_impact') }}</CardTitle></CardHeader>
          <CardContent>
            <form class="space-y-5" @submit.prevent="recordImpact">
              <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <FormSelect
                  id="impact_capital"
                  v-model="impactForm.preservation_capital_id"
                  :label="t('impact_accounting_capital')"
                  :options="capitalOptions"
                  :error="impactForm.errors.preservation_capital_id"
                  required
                />
                <FormInput
                  id="impact_activity"
                  v-model="impactForm.activity_name"
                  :label="t('impact_accounting_activity')"
                  :error="impactForm.errors.activity_name"
                  required
                />
                <FormInput
                  id="impact_type"
                  v-model="impactForm.impact_type"
                  :label="t('impact_accounting_impact_type')"
                  :error="impactForm.errors.impact_type"
                  required
                />
                <FormSelect
                  id="impact_direction"
                  v-model="impactForm.impact_direction"
                  :label="t('impact_accounting_impact_direction')"
                  :options="impactDirectionOptions"
                  :error="impactForm.errors.impact_direction"
                  required
                />
                <FormInput
                  id="impact_occurred_on"
                  v-model="impactForm.occurred_on"
                  type="date"
                  :label="t('impact_accounting_observed_on')"
                  :error="impactForm.errors.occurred_on"
                  required
                />
                <FormInput
                  id="impact_value"
                  v-model="impactForm.value"
                  :label="t('impact_accounting_value')"
                  :error="impactForm.errors.value"
                />
                <FormInput
                  id="impact_unit"
                  v-model="impactForm.unit"
                  :label="t('impact_accounting_unit')"
                  :error="impactForm.errors.unit"
                />
              </div>
              <FormTextarea
                id="impact_source_reference"
                v-model="impactForm.source_reference"
                :label="t('impact_accounting_source_reference')"
                :error="impactForm.errors.source_reference"
                required
              />
              <FormTextarea
                id="impact_notes"
                v-model="impactForm.notes"
                :label="t('impact_accounting_notes')"
                :error="impactForm.errors.notes"
              />
              <div class="flex justify-end">
                <Button type="submit" :disabled="impactForm.processing">
                  {{ impactForm.processing ? t('saving') : t('save') }}
                </Button>
              </div>
            </form>
          </CardContent>
        </Card>

        <Card>
          <CardHeader><CardTitle>{{ t('impact_accounting_add_action') }}</CardTitle></CardHeader>
          <CardContent>
            <form class="space-y-5" @submit.prevent="planAction">
              <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <FormSelect
                  id="action_capital"
                  v-model="actionForm.preservation_capital_id"
                  :label="t('impact_accounting_capital')"
                  :options="capitalOptions"
                  :error="actionForm.errors.preservation_capital_id"
                  required
                />
                <FormInput
                  id="action_title"
                  v-model="actionForm.title"
                  :label="t('impact_accounting_action_title')"
                  :error="actionForm.errors.title"
                  required
                />
                <FormSelect
                  id="action_type"
                  v-model="actionForm.action_type"
                  :label="t('impact_accounting_action_type')"
                  :options="actionTypeOptions"
                  :error="actionForm.errors.action_type"
                  required
                />
                <FormSelect
                  id="action_status"
                  v-model="actionForm.status"
                  :label="t('impact_accounting_action_status')"
                  :options="actionStatusOptions"
                  :error="actionForm.errors.status"
                  required
                />
                <FormInput
                  id="action_due_on"
                  v-model="actionForm.due_on"
                  type="date"
                  :label="t('impact_accounting_due_on')"
                  :error="actionForm.errors.due_on"
                />
              </div>
              <FormTextarea
                id="action_description"
                v-model="actionForm.description"
                :label="t('description')"
                :error="actionForm.errors.description"
              />
              <FormTextarea
                id="action_source_reference"
                v-model="actionForm.source_reference"
                :label="t('source')"
                :error="actionForm.errors.source_reference"
              />
              <div class="flex justify-end">
                <Button type="submit" :disabled="actionForm.processing">
                  {{ actionForm.processing ? t('saving') : t('save') }}
                </Button>
              </div>
            </form>
          </CardContent>
        </Card>
      </div>
    </div>
  </AppLayout>
</template>
