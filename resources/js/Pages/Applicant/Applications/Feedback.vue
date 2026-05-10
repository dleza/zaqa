<script setup lang="ts">
import ApplicantLayout from '@/Layouts/ApplicantLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import Swal from 'sweetalert2'
import { CheckCircle2, Star } from 'lucide-vue-next'

const props = defineProps<{
  application: any
  existingFeedback: any | null
}>()

const labels = [
  { value: 1, label: 'Poor', range: 'Below 50%' },
  { value: 2, label: 'Average', range: '51–64%' },
  { value: 3, label: 'Commendable', range: '65–84%' },
  { value: 4, label: 'Exceptional', range: 'Above 85%' },
]

const selected = ref<number>(props.existingFeedback?.rating_value ?? 0)
const selectedLabel = computed(() => labels.find((l) => l.value === selected.value)?.label ?? '')

const form = useForm({
  rating_value: selected.value || null,
  rating_label: selectedLabel.value || null,
  feedback_text: props.existingFeedback?.feedback_text ?? '',
})

function setRating(v: number) {
  selected.value = v
  form.rating_value = v
  form.rating_label = labels.find((l) => l.value === v)?.label ?? null
}

async function skip() {
  const res = await Swal.fire({
    icon: 'question',
    title: 'Skip feedback for now?',
    text: 'You can still continue to your application details.',
    showCancelButton: true,
    confirmButtonText: 'Skip',
    cancelButtonText: 'Cancel',
    confirmButtonColor: '#0B3A66',
  })
  if (!res.isConfirmed) return
  form.post(`/applicant/applications/${props.application.id}/feedback/skip`)
}

function submit() {
  form.post(`/applicant/applications/${props.application.id}/feedback`, {
    preserveScroll: true,
    onError: () => {
      // keep inline errors
    },
  })
}
</script>

<template>
  <ApplicantLayout>
    <div class="zaqa-wizard-shell mx-auto max-w-4xl px-4 pb-10 pt-2 sm:px-6">
      <!-- Narrow context strip — feedback form below is the primary focus -->
      <div class="mx-auto mb-8 max-w-sm">
        <div class="flex items-center gap-3 rounded-xl border border-success/25 bg-success/[0.07] px-4 py-3">
          <div class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-success/20 bg-surface">
            <CheckCircle2 class="h-4 w-4 text-success" aria-hidden="true" />
          </div>
          <div class="min-w-0">
            <div class="text-sm font-semibold text-text-primary">Application submitted</div>
            <div class="truncate font-mono text-xs text-text-muted">{{ application.application_number }}</div>
          </div>
        </div>
      </div>

      <div class="overflow-hidden rounded-2xl border border-border bg-surface shadow-sm ring-1 ring-black/[0.03]">
        <div class="border-b border-border bg-surface-muted px-6 py-5 sm:px-8">
          <div class="text-base font-semibold tracking-tight text-text-primary">How was your submission experience?</div>
          <div class="mt-1 text-sm text-text-muted">Your feedback helps ZAQA improve the applicant portal.</div>
        </div>

        <div class="px-6 py-6 sm:px-8">
          <div v-if="existingFeedback" class="rounded-xl border border-success/20 bg-success/10 px-4 py-3 text-sm text-success">
            Feedback already submitted on {{ existingFeedback.submitted_at }}. Thank you.
          </div>

          <div v-else class="space-y-5">
            <div>
              <div class="text-sm font-semibold text-text-primary">Rating</div>
              <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4">
                <button
                  v-for="l in labels"
                  :key="l.value"
                  type="button"
                  class="inline-flex w-full items-start justify-start gap-2 rounded-xl border px-3 py-2 text-left text-sm font-semibold transition"
                  :class="selected === l.value ? 'border-brand/30 bg-brand/10 text-brand' : 'border-border bg-surface text-text-muted hover:bg-surface-muted'"
                  @click="setRating(l.value)"
                >
                  <Star class="mt-0.5 h-4 w-4" :class="selected >= l.value ? 'text-accent' : 'text-text-muted'" aria-hidden="true" />
                  <span class="flex flex-col leading-tight">
                    <span>{{ l.label }}</span>
                    <span
                      class="text-[11px] font-medium"
                      :class="selected === l.value ? 'text-brand/80' : 'text-text-muted'"
                    >
                      {{ l.range }}
                    </span>
                  </span>
                </button>
              </div>
              <div v-if="form.errors.rating_value" class="mt-2 text-sm text-danger">{{ form.errors.rating_value }}</div>
            </div>

            <div>
              <label class="text-sm font-semibold text-text-primary">Comments (optional)</label>
              <div class="mt-1 text-xs text-text-muted">Share what worked well or what could be improved.</div>
              <textarea v-model="form.feedback_text" class="zaqa-input mt-2 min-h-[120px]" placeholder="Type your feedback…" />
              <div v-if="form.errors.feedback_text" class="mt-2 text-sm text-danger">{{ form.errors.feedback_text }}</div>
            </div>
          </div>
        </div>

        <div class="border-t border-border bg-surface-muted px-6 py-4 sm:px-8">
          <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <button type="button" class="zaqa-btn zaqa-btn-secondary w-full sm:w-auto" @click="skip">Skip for now</button>
            <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:justify-end">
              <Link :href="`/applicant/applications/${application.id}`" class="zaqa-btn zaqa-btn-ghost w-full sm:w-auto">View application</Link>
              <button
                v-if="!existingFeedback"
                type="button"
                class="zaqa-btn zaqa-btn-primary w-full sm:w-auto"
                :disabled="form.processing || !form.rating_value"
                @click="submit"
              >
                Submit feedback
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </ApplicantLayout>
</template>
