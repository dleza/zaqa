<script setup lang="ts">
import ApplicantLayout from '@/Layouts/ApplicantLayout.vue'
import InputError from '@/Components/InputError.vue'
import { useForm } from '@inertiajs/vue3'
import { computed } from 'vue'
import { AlertTriangle, CheckCircle2, ExternalLink, FileSearch, Info, Search, XCircle } from 'lucide-vue-next'

type ReferenceType = 'application_reference' | 'qualification_reference' | 'certificate_reference'

type LookupQualificationRow = {
  qualification_reference: string
  application_reference: string
  holder_name: string
  qualification_title: string
  awarding_institution: string
  country: string
  award_date: string | null
  status: string
  status_label: string
  message: string
  tone: string
  certificate_number: string | null
  public_verification_url: string | null
  qualification?: {
    holder_name: string
    title: string
    awarding_institution: string
    country: string
    award_date: string | null
  }
  certificate?: {
    exists: boolean
    type_label: string | null
    number: string | null
    issued_at: string | null
    revoked: boolean
    revoked_at: string | null
    public_verification_url: string | null
  } | null
}

type LookupResult = {
  found: boolean
  searched_by: string | null
  application_reference: string | null
  qualification_reference: string | null
  status: string
  status_label: string
  message: string
  tone: string
  qualifications: LookupQualificationRow[]
  qualification: LookupQualificationRow['qualification'] | null
  certificate: LookupQualificationRow['certificate'] | null
}

const referenceTypeOptions: Array<{ value: ReferenceType; label: string; placeholder: string }> = [
  {
    value: 'application_reference',
    label: 'Application reference',
    placeholder: 'e.g. 2026-000245',
  },
  {
    value: 'qualification_reference',
    label: 'Qualification reference',
    placeholder: 'e.g. 2026-000245-01',
  },
  {
    value: 'certificate_reference',
    label: 'Certificate reference',
    placeholder: 'e.g. CERT-2026-000008',
  },
]

const props = defineProps<{
  filters: {
    reference_type: ReferenceType
    reference: string
  }
  result: LookupResult | null
}>()

const form = useForm({
  reference_type: props.filters.reference_type ?? 'application_reference',
  reference: props.filters.reference ?? '',
})

const hasResult = computed(() => props.result !== null)
const multipleRows = computed(() => (props.result?.qualifications?.length ?? 0) > 1)
const singleRow = computed(() => props.result?.qualifications?.[0] ?? null)
const showDetailCard = computed(() => hasResult.value && props.result?.found && !multipleRows.value)

const selectedReferenceType = computed(
  () => referenceTypeOptions.find((option) => option.value === form.reference_type) ?? referenceTypeOptions[0],
)

function submitSearch() {
  form.post('/applicant/institution/verification-lookup', {
    preserveScroll: true,
  })
}

function clearForm() {
  form.reference_type = 'application_reference'
  form.reference = ''
}

function statusBadgeClass(tone: string) {
  if (tone === 'success') return 'zaqa-badge-success'
  if (tone === 'warning') return 'zaqa-badge-warning'
  if (tone === 'danger') return 'zaqa-badge-danger'
  return 'zaqa-badge-secondary'
}

function alertClass(tone: string) {
  if (tone === 'success') return 'border-success/20 bg-success/10 text-success'
  if (tone === 'warning') return 'border-warning/30 bg-warning/10 text-warning'
  if (tone === 'danger') return 'border-danger/20 bg-danger/10 text-danger'
  return 'border-border bg-surface-muted text-text-primary'
}
</script>

<template>
  <ApplicantLayout container-max-width-class="max-w-5xl">
    <template #pageHeader>
      <div>
        <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">Institution tools</p>
        <h1 class="text-2xl font-semibold tracking-tight text-text-primary">Verification Lookup</h1>
        <p class="mt-1 max-w-2xl text-sm text-text-muted">
          Search ZAQA records using an application reference, qualification reference, or certificate reference.
        </p>
      </div>
    </template>

    <div class="space-y-6">
      <section class="rounded-2xl border border-border bg-surface p-6">
        <form class="space-y-5" @submit.prevent="submitSearch">
          <div class="grid gap-5 sm:grid-cols-[minmax(0,14rem)_minmax(0,1fr)]">
            <div>
              <label for="reference_type" class="text-sm font-medium text-text-primary">Reference type</label>
              <select id="reference_type" v-model="form.reference_type" class="zaqa-input mt-1">
                <option v-for="option in referenceTypeOptions" :key="option.value" :value="option.value">
                  {{ option.label }}
                </option>
              </select>
              <InputError :message="form.errors.reference_type" class="mt-1" />
            </div>

            <div>
              <label for="reference" class="text-sm font-medium text-text-primary">{{ selectedReferenceType.label }}</label>
              <input
                id="reference"
                v-model="form.reference"
                type="text"
                class="zaqa-input mt-1 font-mono"
                :placeholder="selectedReferenceType.placeholder"
                autocomplete="off"
              />
              <InputError :message="form.errors.reference" class="mt-1" />
            </div>
          </div>

          <div class="flex flex-wrap gap-3">
            <button type="submit" class="zaqa-btn zaqa-btn-primary inline-flex items-center gap-2 px-4 py-2" :disabled="form.processing">
              <Search class="h-4 w-4" aria-hidden="true" />
              Search
            </button>
            <button type="button" class="zaqa-btn zaqa-btn-secondary px-4 py-2" @click="clearForm">Clear</button>
          </div>

          <p class="text-xs text-text-muted">
            Enter one reference only. Values are matched exactly or by prefix (minimum three characters). Names, NRC, and
            institution names are not searched.
          </p>
        </form>
      </section>

      <section v-if="hasResult && result" class="space-y-4">
        <div
          class="rounded-2xl border px-5 py-4"
          :class="alertClass(result.found ? result.tone : 'neutral')"
        >
          <div class="flex items-start gap-3">
            <component
              :is="result.found ? (result.tone === 'warning' ? AlertTriangle : result.tone === 'success' ? CheckCircle2 : Info) : XCircle"
              class="mt-0.5 h-5 w-5 shrink-0"
              aria-hidden="true"
            />
            <div>
              <div class="font-semibold">{{ result.status_label }}</div>
              <p class="mt-1 text-sm">{{ result.message }}</p>
            </div>
          </div>
        </div>

        <div v-if="multipleRows" class="overflow-hidden rounded-2xl border border-border bg-surface">
          <div class="border-b border-border px-5 py-4">
            <h2 class="text-sm font-semibold text-text-primary">Qualification records</h2>
            <p class="mt-1 text-xs text-text-muted">{{ result.qualifications.length }} records matched this search.</p>
          </div>
          <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead class="bg-surface-muted text-left text-[10px] font-semibold uppercase tracking-wider text-text-muted">
                <tr>
                  <th class="px-4 py-3">Holder name</th>
                  <th class="px-4 py-3">Qualification reference</th>
                  <th class="px-4 py-3">Qualification</th>
                  <th class="px-4 py-3">Awarding institution</th>
                  <th class="px-4 py-3">Status</th>
                  <th class="px-4 py-3">Certificate</th>
                  <th class="px-4 py-3 text-right">Action</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border">
                <tr v-for="row in result.qualifications" :key="row.qualification_reference">
                  <td class="px-4 py-3 font-medium">{{ row.holder_name }}</td>
                  <td class="px-4 py-3 font-mono text-xs">{{ row.qualification_reference || '—' }}</td>
                  <td class="px-4 py-3">{{ row.qualification_title }}</td>
                  <td class="px-4 py-3">{{ row.awarding_institution }}</td>
                  <td class="px-4 py-3">
                    <span class="zaqa-badge text-xs" :class="statusBadgeClass(row.tone)">{{ row.status_label }}</span>
                  </td>
                  <td class="px-4 py-3 font-mono text-xs">{{ row.certificate_number || '—' }}</td>
                  <td class="px-4 py-3 text-right">
                    <a
                      v-if="row.public_verification_url"
                      :href="row.public_verification_url"
                      target="_blank"
                      rel="noopener noreferrer"
                      class="zaqa-link inline-flex items-center gap-1 text-xs font-semibold"
                    >
                      Verify
                      <ExternalLink class="h-3.5 w-3.5" aria-hidden="true" />
                    </a>
                    <span v-else class="text-xs text-text-muted">—</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div v-else-if="showDetailCard && singleRow" class="rounded-2xl border border-border bg-surface p-6">
          <div class="flex items-start justify-between gap-4">
            <div>
              <h2 class="text-lg font-semibold text-text-primary">{{ singleRow.holder_name }}</h2>
              <p class="mt-1 text-sm text-text-muted">{{ singleRow.qualification_title }}</p>
            </div>
            <span class="zaqa-badge shrink-0 text-xs" :class="statusBadgeClass(singleRow.tone)">{{ singleRow.status_label }}</span>
          </div>

          <dl class="mt-6 grid gap-4 sm:grid-cols-2">
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wider text-text-muted">Application reference</dt>
              <dd class="mt-1 font-mono text-sm">{{ singleRow.application_reference || '—' }}</dd>
            </div>
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wider text-text-muted">Qualification reference</dt>
              <dd class="mt-1 font-mono text-sm">{{ singleRow.qualification_reference || '—' }}</dd>
            </div>
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wider text-text-muted">Awarding institution</dt>
              <dd class="mt-1 text-sm">{{ singleRow.awarding_institution }}</dd>
            </div>
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wider text-text-muted">Country of award</dt>
              <dd class="mt-1 text-sm">{{ singleRow.country }}</dd>
            </div>
            <div v-if="singleRow.award_date">
              <dt class="text-xs font-semibold uppercase tracking-wider text-text-muted">Award date</dt>
              <dd class="mt-1 text-sm">{{ singleRow.award_date }}</dd>
            </div>
          </dl>

          <div v-if="singleRow.certificate?.exists" class="mt-6 rounded-xl border border-border bg-surface-muted/40 p-4">
            <h3 class="text-sm font-semibold text-text-primary">Certificate</h3>
            <dl class="mt-3 grid gap-3 sm:grid-cols-2 text-sm">
              <div>
                <dt class="text-xs font-semibold uppercase tracking-wider text-text-muted">Type</dt>
                <dd class="mt-1">{{ singleRow.certificate.type_label || '—' }}</dd>
              </div>
              <div>
                <dt class="text-xs font-semibold uppercase tracking-wider text-text-muted">Certificate number</dt>
                <dd class="mt-1 font-mono">{{ singleRow.certificate.number || '—' }}</dd>
              </div>
              <div v-if="singleRow.certificate.issued_at">
                <dt class="text-xs font-semibold uppercase tracking-wider text-text-muted">Issue date</dt>
                <dd class="mt-1">{{ singleRow.certificate.issued_at }}</dd>
              </div>
              <div v-if="singleRow.certificate.revoked_at">
                <dt class="text-xs font-semibold uppercase tracking-wider text-text-muted">Recalled date</dt>
                <dd class="mt-1">{{ singleRow.certificate.revoked_at }}</dd>
              </div>
            </dl>
            <a
              v-if="singleRow.certificate.public_verification_url"
              :href="singleRow.certificate.public_verification_url"
              target="_blank"
              rel="noopener noreferrer"
              class="zaqa-btn zaqa-btn-secondary mt-4 inline-flex items-center gap-2 px-4 py-2 text-sm"
            >
              <ExternalLink class="h-4 w-4" aria-hidden="true" />
              View public verification
            </a>
          </div>
        </div>

        <div v-else-if="!result.found" class="rounded-2xl border border-dashed border-border bg-surface-muted/30 px-6 py-10 text-center">
          <FileSearch class="mx-auto h-10 w-10 text-text-muted" aria-hidden="true" />
          <p class="mt-3 text-sm text-text-muted">No matching verification record was found. Check the reference and try again.</p>
        </div>
      </section>
    </div>
  </ApplicantLayout>
</template>
