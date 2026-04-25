<script setup lang="ts">
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import ApplicantLayout from '@/Layouts/ApplicantLayout.vue'
import { CheckCircle2 } from 'lucide-vue-next'

const props = defineProps<{
  application: any
  countries: Array<{ id: number; name: string }>
  awardingInstitutions: Array<{ id: number; name: string }>
  localConsent: { title: string; text: string; version: string }
  applicant: any
}>()

const countryNameById = computed(() => new Map(props.countries.map((c) => [c.id, c.name])))

const subjectIdNumber = computed(() => {
  const vs = props.application?.metadata?.verification_subject ?? null
  const nrc = (vs?.nrc_number ?? '').toString().trim()
  const passport = (vs?.passport_number ?? '').toString().trim()
  if (nrc.length) return nrc
  if (passport.length) return passport

  const qualId = (props.application?.qualification?.nrc_passport_number ?? '').toString().trim()
  if (qualId.length) return qualId

  const selfNrc = (props.applicant?.applicant_profile?.nrc_number ?? '').toString().trim()
  const selfPassport = (props.applicant?.applicant_profile?.passport_number ?? '').toString().trim()
  return selfNrc.length ? selfNrc : selfPassport.length ? selfPassport : ''
})

function formatLabel(value: any) {
  return typeof value === 'string' && value.length > 0 ? value : '—'
}

function countryLabel(countryId: number | null, other: string | null) {
  if (countryId) return countryNameById.value.get(countryId) ?? `#${countryId}`
  return other && other.length > 0 ? other : '—'
}

function money(cents: number, currency: string) {
  return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'ZMW' }).format((cents ?? 0) / 100)
}

</script>

<template>
  <ApplicantLayout>
    <div class="zaqa-wizard-shell">
      <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 class="text-base font-semibold text-text-primary">Application details</h2>
          <p class="mt-1 text-xs text-text-muted">A formal view of your submitted application.</p>
        </div>

        <div class="flex flex-wrap gap-2">
          <Link href="/applicant/applications" class="zaqa-btn zaqa-btn-secondary px-3 py-2 text-sm">Back</Link>
          <Link :href="`/applicant/applications/${application.id}/track`" class="zaqa-btn zaqa-btn-secondary px-3 py-2 text-sm">
            Track
          </Link>
          <Link v-if="application.can_edit" :href="`/applicant/applications/${application.id}/edit`" class="zaqa-btn zaqa-btn-primary px-3 py-2 text-sm">
            Edit draft
          </Link>
        </div>
      </div>

      <!-- Paper-like application sheet (mirrors Step 6 review styling) -->
      <div class="mt-4 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
        <div class="border-b border-border bg-surface-muted px-5 py-4">
          <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-start gap-3">
              <div class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-border bg-surface">
                <CheckCircle2 class="h-5 w-5 text-success" aria-hidden="true" />
              </div>
              <div>
                <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Application</div>
                <div class="mt-1 text-lg font-semibold tracking-tight text-text-primary">
                  {{ application.application_number }}
                </div>
                <div class="mt-1 text-xs text-text-muted">{{ application.status_label }}</div>
              </div>
            </div>

            <div class="flex flex-col items-start gap-2 text-xs text-text-muted sm:items-end">
              <div>
                Submitted: <span class="font-semibold text-text-primary">{{ application.submitted_at ?? '—' }}</span>
              </div>
              <div>
                Service deadline: <span class="font-semibold text-text-primary">{{ application.service_deadline_at ?? '—' }}</span>
              </div>
            </div>
          </div>
        </div>

        <div class="px-5 py-5">
          <!-- 1. Applicant information -->
          <div class="flex items-start justify-between gap-3">
            <div>
              <div class="text-sm font-semibold text-text-primary">1. Applicant information</div>
              <div class="mt-1 text-xs text-text-muted">Contact and identity details.</div>
            </div>
          </div>
          <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
              <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Submitting for</div>
              <div class="mt-1 text-sm font-semibold text-text-primary">
                {{ (application.metadata?.submitting_for ?? 'self') === 'other' ? 'On behalf of someone' : 'Myself' }}
              </div>
            </div>
            <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
              <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Verification subject</div>
              <div class="mt-1 text-sm font-semibold text-text-primary">
                {{ application.metadata?.verification_subject?.full_name || applicant?.name || '—' }}
              </div>
            </div>
            <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
              <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Subject NRC / Passport</div>
              <div class="mt-1 text-sm font-semibold text-text-primary">{{ subjectIdNumber || '—' }}</div>
            </div>
            <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
              <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Email</div>
              <div class="mt-1 text-sm font-semibold text-text-primary">{{ applicant?.email ?? '—' }}</div>
            </div>
            <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
              <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Primary phone</div>
              <div class="mt-1 text-sm font-semibold text-text-primary">{{ applicant?.phone_primary ?? '—' }}</div>
            </div>
          </div>

          <div class="my-6 h-px bg-border/70" />

          <!-- 2. Qualification information -->
          <div class="flex items-start justify-between gap-3">
            <div>
              <div class="text-sm font-semibold text-text-primary">2. Qualification information</div>
              <div class="mt-1 text-xs text-text-muted">Qualification details as provided.</div>
            </div>
          </div>

          <div v-if="!application.qualification" class="mt-3 rounded-xl border border-border bg-surface-muted px-4 py-3 text-sm text-text-muted">
            Qualification details are missing.
          </div>
          <div v-else class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
              <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Qualification type</div>
              <div class="mt-1 text-sm font-semibold text-text-primary">
                {{ application.qualification.qualification_type_master?.level_label }} — {{ application.qualification.qualification_type_master?.name }}
              </div>
            </div>
            <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
              <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Local / foreign</div>
              <div class="mt-1 text-sm font-semibold text-text-primary">{{ application.is_foreign ? 'Foreign' : 'Local' }}</div>
            </div>
            <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
              <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Country of award</div>
              <div class="mt-1 text-sm font-semibold text-text-primary">
                {{ countryLabel(application.qualification.country_id, application.qualification.country_name_other) }}
              </div>
            </div>
            <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
              <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Awarding institution</div>
              <div class="mt-1 text-sm font-semibold text-text-primary">
                {{
                  application.qualification.awarding_institution_name_other ||
                  application.qualification.awarding_institution?.name ||
                  application.qualification.awarding_institution_name ||
                  '—'
                }}
              </div>
            </div>
            <div class="rounded-xl border border-border bg-surface-muted px-4 py-3 sm:col-span-2">
              <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Title of qualification</div>
              <div class="mt-1 text-sm font-semibold text-text-primary">{{ application.qualification.title_of_qualification || '—' }}</div>
            </div>
            <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
              <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Award date</div>
              <div class="mt-1 text-sm font-semibold text-text-primary">{{ application.qualification.award_date || '—' }}</div>
            </div>
            <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
              <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">NRC / Passport (as entered)</div>
              <div class="mt-1 text-sm font-semibold text-text-primary">{{ application.qualification.nrc_passport_number || '—' }}</div>
            </div>
          </div>

          <div class="my-6 h-px bg-border/70" />

          <!-- 3. Supporting documents -->
          <div class="flex items-start justify-between gap-3">
            <div>
              <div class="text-sm font-semibold text-text-primary">3. Supporting documents</div>
              <div class="mt-1 text-xs text-text-muted">Uploaded documents for processing.</div>
            </div>
          </div>
          <div v-if="application.documents.length === 0" class="mt-3 rounded-xl border border-border bg-surface-muted px-4 py-3 text-sm text-text-muted">
            No documents uploaded.
          </div>
          <div v-else class="mt-3 overflow-hidden rounded-xl border border-border">
            <div class="divide-y divide-border/60">
              <div
                v-for="doc in application.documents.filter((d: any) => d.is_current_version)"
                :key="doc.id"
                class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
              >
                <div class="min-w-0">
                  <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">{{ doc.document_type }}</div>
                  <div class="mt-1 text-sm font-semibold text-text-primary">{{ doc.original_name || '—' }}</div>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                  <span class="zaqa-badge zaqa-badge-success">Uploaded</span>
                  <a v-if="doc.preview_url" :href="doc.preview_url" target="_blank" rel="noopener" class="zaqa-link text-xs">Preview</a>
                  <a v-if="doc.download_url" :href="doc.download_url" target="_blank" rel="noopener" class="zaqa-link text-xs">Download</a>
                </div>
              </div>
            </div>
          </div>

          <div class="my-6 h-px bg-border/70" />

          <!-- 4. Consent -->
          <div class="flex items-start justify-between gap-3">
            <div>
              <div class="text-sm font-semibold text-text-primary">4. Consent</div>
              <div class="mt-1 text-xs text-text-muted">Consent status for this application.</div>
            </div>
          </div>
          <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
              <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Status</div>
              <div class="mt-1 text-sm font-semibold text-text-primary">
                {{ application.is_foreign ? (application.consent_form?.uploaded_document_id ? 'Uploaded' : 'Pending') : (application.consent_form?.agreed_at ? 'Accepted' : 'Pending') }}
              </div>
            </div>
            <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
              <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Consent type</div>
              <div class="mt-1 text-sm font-semibold text-text-primary">{{ application.consent_form?.consent_type ?? '—' }}</div>
            </div>
          </div>

          <div class="my-6 h-px bg-border/70" />

          <!-- 5. Payment -->
          <div class="flex items-start justify-between gap-3">
            <div>
              <div class="text-sm font-semibold text-text-primary">5. Payment</div>
              <div class="mt-1 text-xs text-text-muted">Invoice and payment confirmation.</div>
            </div>
          </div>
          <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
              <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Invoice number</div>
              <div class="mt-1 text-sm font-semibold text-text-primary">{{ application.invoice?.invoice_number ?? '—' }}</div>
            </div>
            <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
              <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Amount</div>
              <div class="mt-1 text-sm font-semibold text-text-primary">
                {{ money(application.invoice?.amount_cents ?? 0, application.invoice?.currency ?? 'ZMW') }}
              </div>
            </div>
            <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
              <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Method</div>
              <div class="mt-1 text-sm font-semibold text-text-primary">{{ application.payment?.method ?? '—' }}</div>
            </div>
            <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
              <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Payment status</div>
              <div class="mt-1 text-sm font-semibold text-text-primary">{{ application.payment?.status ?? '—' }}</div>
            </div>
          </div>

          <div class="my-6 h-px bg-border/70" />

          <!-- Status timeline -->
          <div class="flex items-start justify-between gap-3">
            <div>
              <div class="text-sm font-semibold text-text-primary">Status timeline</div>
              <div class="mt-1 text-xs text-text-muted">History of status changes on your application.</div>
            </div>
          </div>
          <div v-if="application.status_histories.length === 0" class="mt-3 rounded-xl border border-border bg-surface-muted px-4 py-3 text-sm text-text-muted">
            No status changes yet.
          </div>
          <div v-else class="mt-3 space-y-3">
            <div v-for="h in application.status_histories" :key="h.id" class="rounded-xl border border-border bg-surface-muted px-4 py-3">
              <div class="text-xs font-semibold text-text-primary">
                {{ h.from_status ?? '—' }} → {{ h.to_status }}
              </div>
              <div v-if="h.comment" class="mt-1 text-xs text-text-primary">{{ h.comment }}</div>
              <div class="mt-1 text-[11px] text-text-muted">{{ h.changed_at }}</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </ApplicantLayout>
</template>
