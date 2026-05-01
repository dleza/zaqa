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

/** All qualifications submitted on this application (multi-qual wizard + legacy single). */
const qualificationsList = computed<any[]>(() => {
  const multi = props.application?.qualifications
  if (Array.isArray(multi) && multi.length > 0) {
    return multi
  }
  const single = props.application?.qualification
  return single ? [single] : []
})

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

function countryLabel(countryId: number | null, other: string | null) {
  if (countryId) return countryNameById.value.get(countryId) ?? `#${countryId}`
  return other && other.length > 0 ? other : '—'
}

function qualAwardScope(q: any) {
  if (typeof q.is_foreign_qualification === 'boolean') {
    return q.is_foreign_qualification ? 'Foreign' : 'Local (Zambia)'
  }
  const iso = (q.country?.iso_code ?? '').toString().toUpperCase()
  if (iso === 'ZMB' || iso === 'ZM') return 'Local (Zambia)'
  if (iso) return 'Foreign'
  return props.application.is_foreign ? 'Foreign' : 'Local (Zambia)'
}

/** Uses wizard payload when present; falls back for legacy single-qualification applications. */
function qualNeedsForeignConsent(q: any): boolean {
  if (typeof q.requires_foreign_consent === 'boolean') return q.requires_foreign_consent
  const iso = (q.country?.iso_code ?? '').toString().toUpperCase()
  if (iso === 'ZMB' || iso === 'ZM') return false
  if (iso) return true
  return !!props.application.is_foreign && qualificationsList.value.length <= 1
}

function qualConsentLabel(q: any): { text: string; ok: boolean } {
  if (!qualNeedsForeignConsent(q)) {
    return { text: 'Not required (Zambian awarding institution)', ok: true }
  }
  if (typeof q.has_foreign_consent === 'boolean') {
    return q.has_foreign_consent
      ? { text: 'Institution consent on file', ok: true }
      : { text: 'Institution consent pending', ok: false }
  }
  const legacy = !!props.application.consent_form?.uploaded_document_id
  return legacy ? { text: 'Institution consent on file', ok: true } : { text: 'Institution consent pending', ok: false }
}

const currentDocuments = computed(() =>
  (props.application?.documents ?? []).filter((d: any) => d.is_current_version),
)

/** Types normally stored against a qualification row (also used for legacy single-qual uploads without qualification_id). */
const QUALIFICATION_SCOPED_DOC_TYPES = ['certificate_copy', 'transcript', 'consent_form_signed']

function documentsForQualification(q: any, index: number) {
  const qid = Number(q.id)
  return currentDocuments.value.filter((d: any) => {
    const dq = d.qualification_id
    if (dq != null && dq !== '' && Number(dq) === qid) {
      return true
    }
    const singleLegacy =
      qualificationsList.value.length === 1 &&
      index === 0 &&
      (dq == null || dq === '' || Number(dq) === 0) &&
      QUALIFICATION_SCOPED_DOC_TYPES.includes(String(d.document_type ?? ''))
    return singleLegacy
  })
}

/** Identity, payment proof, and any document not tied to a specific qualification card. */
const applicationLevelDocuments = computed(() => {
  return currentDocuments.value.filter((d: any) => {
    const dq = d.qualification_id
    const t = String(d.document_type ?? '')
    if (dq != null && dq !== '' && Number(dq) > 0) {
      return false
    }
    if (qualificationsList.value.length === 1 && QUALIFICATION_SCOPED_DOC_TYPES.includes(t)) {
      return false
    }
    return true
  })
})

function docTypeLabel(documentType: string) {
  return String(documentType || '')
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase())
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

          <!-- 2. Qualifications submitted for verification -->
          <div class="flex items-start justify-between gap-3">
            <div>
              <div class="text-sm font-semibold text-text-primary">2. Qualifications submitted for verification</div>
              <div class="mt-1 text-xs text-text-muted">
                Each programme or certificate you asked ZAQA to verify is listed below.
                <span v-if="qualificationsList.length > 1" class="font-medium text-text-primary"> ({{ qualificationsList.length }} items)</span>
              </div>
            </div>
          </div>

          <div v-if="qualificationsList.length === 0" class="mt-3 rounded-xl border border-border bg-surface-muted px-4 py-3 text-sm text-text-muted">
            No qualifications on this application.
          </div>
          <div v-else class="mt-4 space-y-5">
            <article
              v-for="(q, idx) in qualificationsList"
              :key="q.id ?? idx"
              class="overflow-hidden rounded-2xl border border-border bg-surface-muted/40 ring-1 ring-black/[0.04]"
            >
              <div class="border-b border-border/70 bg-surface-muted px-4 py-3 sm:px-5">
                <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Qualification {{ idx + 1 }}</div>
                <div class="mt-1 text-base font-semibold tracking-tight text-text-primary">
                  {{ q.title_of_qualification || 'Untitled qualification' }}
                </div>
              </div>
              <div class="grid grid-cols-1 gap-4 px-4 py-4 lg:grid-cols-2 lg:items-start sm:px-5">
                <div>
                  <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="rounded-xl border border-border bg-surface px-4 py-3">
                      <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Qualification type</div>
                      <div class="mt-1 text-sm font-semibold text-text-primary">
                        {{
                          q.qualification_type_master
                            ? `${q.qualification_type_master.level_label} — ${q.qualification_type_master.name}`
                            : '—'
                        }}
                      </div>
                    </div>
                    <div class="rounded-xl border border-border bg-surface px-4 py-3">
                      <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Award scope</div>
                      <div class="mt-1 text-sm font-semibold text-text-primary">{{ qualAwardScope(q) }}</div>
                    </div>
                    <div class="rounded-xl border border-border bg-surface px-4 py-3">
                      <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Country of award</div>
                      <div class="mt-1 text-sm font-semibold text-text-primary">
                        {{ q.country?.name || countryLabel(q.country_id, q.country_name_other) }}
                      </div>
                    </div>
                    <div class="rounded-xl border border-border bg-surface px-4 py-3">
                      <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Awarding institution</div>
                      <div class="mt-1 text-sm font-semibold text-text-primary">
                        {{ q.awarding_institution_name_other || q.awarding_institution?.name || q.awarding_institution_name || '—' }}
                      </div>
                    </div>
                    <div class="rounded-xl border border-border bg-surface px-4 py-3">
                      <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Award date</div>
                      <div class="mt-1 text-sm font-semibold text-text-primary">{{ q.award_date || '—' }}</div>
                    </div>
                    <div class="rounded-xl border border-border bg-surface px-4 py-3">
                      <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Certificate / student / exam ID</div>
                      <div class="mt-1 text-sm font-semibold text-text-primary">
                        {{ q.certificate_number || q.student_number || q.examination_number || '—' }}
                      </div>
                    </div>
                  </div>
                  <div v-if="q.notes" class="mt-3 rounded-xl border border-border bg-surface px-4 py-3">
                    <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Notes</div>
                    <div class="mt-1 whitespace-pre-wrap text-sm text-text-primary">{{ q.notes }}</div>
                  </div>
                </div>

                <div class="rounded-xl border border-border bg-surface px-4 py-3 lg:sticky lg:top-4">
                  <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Qualification documents</div>
                  <p class="mt-1 text-xs text-text-muted">Certificate, transcript, and institution consent files for this programme.</p>
                  <div v-if="documentsForQualification(q, idx).length === 0" class="mt-3 rounded-lg border border-dashed border-border bg-surface-muted/40 px-3 py-4 text-center text-sm text-text-muted">
                    No documents uploaded for this qualification.
                  </div>
                  <ul v-else class="mt-3 space-y-2">
                    <li
                      v-for="doc in documentsForQualification(q, idx)"
                      :key="doc.id"
                      class="rounded-lg border border-border/70 bg-surface-muted/50 px-3 py-2.5"
                    >
                      <div class="text-[11px] font-semibold uppercase tracking-wide text-text-muted">{{ docTypeLabel(doc.document_type) }}</div>
                      <div class="mt-0.5 truncate text-sm font-medium text-text-primary" :title="doc.original_name">
                        {{ doc.original_name || '—' }}
                      </div>
                      <div class="mt-2 flex flex-wrap gap-2">
                        <a
                          v-if="doc.preview_url"
                          :href="doc.preview_url"
                          target="_blank"
                          rel="noopener"
                          class="zaqa-link text-xs font-medium"
                        >
                          Preview
                        </a>
                        <a
                          v-if="doc.download_url"
                          :href="doc.download_url"
                          target="_blank"
                          rel="noopener"
                          class="zaqa-link text-xs font-medium"
                        >
                          Download
                        </a>
                      </div>
                    </li>
                  </ul>
                </div>
              </div>

              <div
                v-if="q.subject_results && q.subject_results.length > 0"
                class="border-t border-border/70 px-4 pb-4 pt-2 sm:px-5"
              >
                <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Subject results</div>
                <div class="mt-2 overflow-hidden rounded-xl border border-border">
                  <table class="w-full text-sm">
                    <thead class="bg-surface-muted text-left text-[11px] font-semibold uppercase tracking-wider text-text-muted">
                      <tr>
                        <th class="px-3 py-2">Subject</th>
                        <th class="px-3 py-2">Grade</th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-border/60">
                      <tr v-for="(row, ridx) in q.subject_results" :key="ridx">
                        <td class="px-3 py-2 font-medium text-text-primary">{{ row.subject_name || '—' }}</td>
                        <td class="px-3 py-2 text-text-primary">{{ row.grade || '—' }}</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
            </article>
          </div>

          <p class="mt-3 text-xs text-text-muted">
            Holder identity (NRC / passport) for verification is taken from the applicant / verification subject above; it applies to all listed qualifications.
          </p>

          <div class="my-6 h-px bg-border/70" />

          <!-- 3. Application & identity documents -->
          <div class="flex items-start justify-between gap-3">
            <div>
              <div class="text-sm font-semibold text-text-primary">3. Application & identity documents</div>
              <div class="mt-1 text-xs text-text-muted">
                Identity and other documents that apply to the whole application. Qualification-specific files are listed next to each qualification above.
              </div>
            </div>
          </div>
          <div v-if="applicationLevelDocuments.length === 0" class="mt-3 rounded-xl border border-border bg-surface-muted px-4 py-3 text-sm text-text-muted">
            No application-level documents uploaded.
          </div>
          <div v-else class="mt-3 overflow-hidden rounded-xl border border-border">
            <div class="divide-y divide-border/60">
              <div
                v-for="doc in applicationLevelDocuments"
                :key="doc.id"
                class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
              >
                <div class="min-w-0">
                  <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">{{ docTypeLabel(doc.document_type) }}</div>
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

          <!-- 4. Institution consent (per qualification where applicable) -->
          <div class="flex items-start justify-between gap-3">
            <div>
              <div class="text-sm font-semibold text-text-primary">4. Institution consent</div>
              <div class="mt-1 text-xs text-text-muted">
                Foreign awarding institutions require a signed institution consent. Zambian institutions do not require this step.
              </div>
            </div>
          </div>
          <div v-if="qualificationsList.length === 0" class="mt-3 rounded-xl border border-border bg-surface-muted px-4 py-3 text-sm text-text-muted">
            —
          </div>
          <div v-else class="mt-3 space-y-2">
            <div
              v-for="(q, idx) in qualificationsList"
              :key="`consent-${q.id ?? idx}`"
              class="flex flex-col gap-1 rounded-xl border border-border bg-surface-muted px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
            >
              <div class="min-w-0 text-sm font-semibold text-text-primary">
                {{ idx + 1 }}. {{ q.title_of_qualification || 'Qualification' }}
              </div>
              <span
                class="inline-flex w-fit shrink-0 rounded-full border px-2.5 py-0.5 text-[11px] font-semibold"
                :class="
                  qualConsentLabel(q).ok
                    ? 'border-success/30 bg-success/10 text-emerald-800'
                    : qualNeedsForeignConsent(q)
                      ? 'border-warning/30 bg-warning/10 text-warning'
                      : 'border-border bg-surface text-text-muted'
                "
              >
                {{ qualConsentLabel(q).text }}
              </span>
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
