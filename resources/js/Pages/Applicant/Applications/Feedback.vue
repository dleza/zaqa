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
  { value: 1, label: 'Very poor' },
  { value: 2, label: 'Poor' },
  { value: 3, label: 'Fair' },
  { value: 4, label: 'Good' },
  { value: 5, label: 'Excellent' },
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

function money(cents: number, currency: string) {
  return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'ZMW' }).format((cents ?? 0) / 100)
}
</script>

<template>
  <ApplicantLayout>
    <div class="zaqa-wizard-shell">
      <div class="rounded-2xl border border-success/20 bg-success/10 p-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
          <div class="flex items-start gap-3">
            <div class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-success/20 bg-surface">
              <CheckCircle2 class="h-5 w-5 text-success" aria-hidden="true" />
            </div>
            <div>
              <div class="text-base font-semibold text-text-primary">Application submitted</div>
              <div class="mt-1 text-sm text-text-muted">
                Reference: <span class="font-semibold text-text-primary">{{ application.application_number }}</span>
              </div>
              <div class="mt-1 text-xs text-text-muted">{{ application.status_label }}</div>
            </div>
          </div>

          <div class="rounded-xl border border-border bg-surface px-4 py-3 text-right">
            <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Invoice</div>
            <div class="mt-1 text-sm font-semibold text-text-primary">{{ application.invoice?.invoice_number ?? '—' }}</div>
            <div class="mt-1 text-xs text-text-muted">
              {{ money(application.invoice?.amount_cents ?? 0, application.invoice?.currency ?? 'ZMW') }}
              <span class="ml-2 inline-flex rounded-full border border-border bg-surface-muted px-2 py-0.5 text-[11px] font-semibold text-text-muted">
                {{ application.invoice?.status ?? '—' }}
              </span>
            </div>
          </div>
        </div>
      </div>

      <div class="mt-6 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
        <div class="border-b border-border bg-surface-muted px-6 py-4">
          <div class="text-sm font-semibold text-text-primary">How was your submission experience?</div>
          <div class="mt-1 text-xs text-text-muted">Your feedback helps ZAQA improve the applicant portal.</div>
        </div>

        <div class="px-6 py-5">
          <div v-if="existingFeedback" class="rounded-xl border border-success/20 bg-success/10 px-4 py-3 text-sm text-success">
            Feedback already submitted on {{ existingFeedback.submitted_at }}. Thank you.
          </div>

          <div v-else class="space-y-5">
            <div>
              <div class="text-sm font-semibold text-text-primary">Rating</div>
              <div class="mt-2 flex flex-wrap items-center gap-2">
                <button
                  v-for="l in labels"
                  :key="l.value"
                  type="button"
                  class="inline-flex items-center gap-2 rounded-xl border px-3 py-2 text-sm font-semibold transition"
                  :class="selected === l.value ? 'border-brand/30 bg-brand/10 text-brand' : 'border-border bg-surface text-text-muted hover:bg-surface-muted'"
                  @click="setRating(l.value)"
                >
                  <Star class="h-4 w-4" :class="selected >= l.value ? 'text-accent' : 'text-text-muted'" aria-hidden="true" />
                  <span>{{ l.label }}</span>
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

        <div class="border-t border-border bg-surface-muted px-6 py-4">
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

