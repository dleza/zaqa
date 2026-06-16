<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminActionModal from '@/Components/AdminActionModal.vue'
import AdminExcelImportModal from '@/Components/AdminExcelImportModal.vue'
import AdminPagination from '@/Components/AdminPagination.vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { BadgeCheck, Ban, ExternalLink, FileDown, FileSpreadsheet, Search } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'

const props = defineProps<{
  certificates: any
  filters: { q: string; status?: string; type?: string }
  status_options: Array<{ value: string; label: string }>
  type_options: Array<{ value: string; label: string }>
  excel_import: { template_url: string; import_url: string; can_import: boolean }
  can?: { revoke?: boolean }
}>()

const q = ref(props.filters.q ?? '')
const status = ref(props.filters.status ?? '')
const type = ref(props.filters.type ?? '')
const excelImportOpen = ref(false)
const revokeOpen = ref(false)
const revokeTarget = ref<{ id: number; certificate_number: string; revoke_url: string } | null>(null)

const revokeForm = useForm({
  revocation_reason: '',
  revocation_public_note: '',
  confirm: false,
})

const statusBadgeClass = computed(() => {
  return (value: string | null | undefined) => {
    const s = (value ?? '').toString()
    if (s === 'issued') return 'zaqa-badge-success'
    if (s === 'reissued') return 'zaqa-badge-warning'
    if (s === 'revoked') return 'zaqa-badge-danger'
    return 'zaqa-badge-secondary'
  }
})

watch([q, status, type], () => {
  router.get(
    '/admin/certificates',
    { q: q.value || null, status: status.value || null, type: type.value || null },
    { preserveState: true, replace: true, preserveScroll: true },
  )
})

function formatIssued(iso: string | null | undefined) {
  if (!iso) return '—'
  try {
    return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
  } catch {
    return iso
  }
}

function openRevokeModal(row: { id: number; certificate_number: string; revoke_url: string | null }) {
  if (!row.revoke_url) return
  revokeTarget.value = { id: row.id, certificate_number: row.certificate_number, revoke_url: row.revoke_url }
  revokeForm.reset()
  revokeOpen.value = true
}

function submitRevoke() {
  if (!revokeTarget.value) return
  revokeForm.post(revokeTarget.value.revoke_url, {
    preserveScroll: true,
    onSuccess: () => {
      revokeOpen.value = false
      revokeTarget.value = null
      revokeForm.reset()
    },
  })
}
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <BadgeCheck class="h-4 w-4" aria-hidden="true" />
          Certificates
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">CVEQ registry</h1>
        <p class="mt-1 text-sm text-text-muted">
          Certificates of Verification and Evaluation of Qualification issued in the portal. Search by CVEQ number, ZAQA
          reference, application number, qualification title, or holder name.
        </p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <button
          type="button"
          class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-2 px-4 py-2 text-sm"
          @click="excelImportOpen = true"
        >
          <FileSpreadsheet class="h-4 w-4" aria-hidden="true" />
          Bulk issue (Excel)
        </button>
        <Link href="/admin/verification/pool" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Verification pool</Link>
      </div>
    </div>

    <AdminExcelImportModal
      v-model="excelImportOpen"
      title="Bulk issue CVEQs from Excel"
      description="One column: qualification_id (internal ID from each qualification task). Each row runs the same checks as Issue Certificate (paid, approved for certificate). Applicants are emailed when issuance succeeds."
      :template-url="excel_import.template_url"
      :import-url="excel_import.import_url"
      :can-import="excel_import.can_import"
    />

    <div class="mt-6 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
      <div class="border-b border-border bg-surface-muted px-5 py-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <div class="text-sm font-semibold text-text-primary">Issued certificates</div>
            <div class="mt-1 text-xs text-text-muted">Includes superseded and revoked rows for full certificate history.</div>
          </div>
          <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <select v-model="type" class="zaqa-input h-10 min-w-[180px]">
              <option v-for="opt in type_options" :key="opt.value || 'all-types'" :value="opt.value">{{ opt.label }}</option>
            </select>
            <select v-model="status" class="zaqa-input h-10 min-w-[200px]">
              <option v-for="opt in status_options" :key="opt.value || 'all'" :value="opt.value">{{ opt.label }}</option>
            </select>
            <div class="relative">
              <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted" aria-hidden="true" />
              <input v-model="q" class="zaqa-input h-10 pl-9" placeholder="Search…" />
            </div>
          </div>
        </div>
      </div>

      <div v-if="certificates.data.length === 0" class="px-5 py-8">
        <div class="rounded-2xl border border-border bg-surface-muted p-8 text-center">
          <div class="text-sm font-semibold text-text-primary">No certificates found</div>
          <div class="mt-1 text-xs text-text-muted">
            Nothing matches your filters yet — certificates appear here after staff issue a CVEQ for a verified, paid
            qualification.
          </div>
        </div>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
            <tr>
              <th class="px-5 py-3 text-left">CVEQ number</th>
              <th class="px-5 py-3 text-left">Type</th>
              <th class="px-5 py-3 text-left">Status</th>
              <th class="px-5 py-3 text-left">Issued</th>
              <th class="px-5 py-3 text-left">Revoked</th>
              <th class="px-5 py-3 text-left">Application</th>
              <th class="px-5 py-3 text-left">Qualification</th>
              <th class="px-5 py-3 text-left">Holder</th>
              <th class="px-5 py-3 text-left">ZAQA ref</th>
              <th class="px-5 py-3 text-left">Issued by</th>
              <th class="px-5 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr v-for="row in certificates.data" :key="row.id" class="hover:bg-surface-muted/60">
              <td class="px-5 py-3">
                <span class="font-mono font-semibold text-text-primary">{{ row.certificate_number }}</span>
              </td>
              <td class="px-5 py-3">
                <span class="zaqa-badge zaqa-badge-secondary">{{ row.certificate_type_label ?? 'Verification' }}</span>
              </td>
              <td class="px-5 py-3">
                <span class="zaqa-badge" :class="statusBadgeClass(row.status)">{{ row.status_label ?? row.status }}</span>
              </td>
              <td class="px-5 py-3 text-text-primary">{{ formatIssued(row.issued_at) }}</td>
              <td class="px-5 py-3 text-text-primary">
                <div>{{ formatIssued(row.revoked_at) }}</div>
                <div v-if="row.revoked_by_name" class="text-xs text-text-muted">{{ row.revoked_by_name }}</div>
              </td>
              <td class="px-5 py-3">
                <span class="font-mono font-semibold text-text-primary">{{ row.application_number ?? '—' }}</span>
              </td>
              <td class="max-w-[220px] px-5 py-3 text-text-primary">
                <span class="line-clamp-2">{{ row.qualification_title ?? '—' }}</span>
              </td>
              <td class="px-5 py-3 text-text-primary">{{ row.holder_name ?? '—' }}</td>
              <td class="px-5 py-3 font-mono text-xs text-text-muted">{{ row.zaqa_reference_number ?? '—' }}</td>
              <td class="px-5 py-3 text-text-primary">{{ row.issued_by_name ?? '—' }}</td>
              <td class="px-5 py-3 text-right">
                <div class="flex flex-wrap justify-end gap-2">
                  <a
                    :href="row.download_url"
                    class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold"
                    :title="row.status === 'revoked' ? 'Downloads the original PDF as issued (historical record).' : undefined"
                  >
                    <FileDown class="h-3.5 w-3.5" aria-hidden="true" />
                    PDF
                  </a>
                  <a
                    v-if="row.verification_url"
                    :href="row.verification_url"
                    target="_blank"
                    rel="noopener"
                    class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold"
                  >
                    <BadgeCheck class="h-3.5 w-3.5" aria-hidden="true" />
                    Verify page
                  </a>
                  <button
                    v-if="row.revoke_url && can?.revoke"
                    type="button"
                    class="zaqa-btn zaqa-btn-danger inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold"
                    @click="openRevokeModal(row)"
                  >
                    <Ban class="h-3.5 w-3.5" aria-hidden="true" />
                    Revoke
                  </button>
                  <Link
                    v-if="row.verification_task_url"
                    :href="row.verification_task_url"
                    class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold"
                  >
                    <ExternalLink class="h-3.5 w-3.5" aria-hidden="true" />
                    Task
                  </Link>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="certificates.data.length > 0" class="border-t border-border px-5 py-4">
        <AdminPagination :links="certificates.links ?? []" />
      </div>
    </div>

    <AdminActionModal
      v-model="revokeOpen"
      title="Revoke certificate"
      :description="
        revokeTarget
          ? `Revoke CVEQ ${revokeTarget.certificate_number}. The certificate will remain in history but will no longer verify as valid publicly.`
          : ''
      "
    >
      <div class="space-y-4">
        <div>
          <label class="text-sm font-semibold text-text-primary" for="revocation_reason">Internal reason (required)</label>
          <textarea
            id="revocation_reason"
            v-model="revokeForm.revocation_reason"
            rows="3"
            class="zaqa-input mt-2 w-full"
            placeholder="Why is this certificate being revoked?"
          />
          <div v-if="revokeForm.errors.revocation_reason" class="mt-1 text-xs text-danger">{{ revokeForm.errors.revocation_reason }}</div>
        </div>
        <div>
          <label class="text-sm font-semibold text-text-primary" for="revocation_public_note">Public note (optional)</label>
          <textarea
            id="revocation_public_note"
            v-model="revokeForm.revocation_public_note"
            rows="2"
            class="zaqa-input mt-2 w-full"
            placeholder="Shown on the public verification page if provided."
          />
          <div v-if="revokeForm.errors.revocation_public_note" class="mt-1 text-xs text-danger">{{ revokeForm.errors.revocation_public_note }}</div>
        </div>
        <label class="flex items-start gap-2 text-sm text-text-secondary">
          <input v-model="revokeForm.confirm" type="checkbox" class="mt-1 rounded border-border" />
          <span>I understand this certificate will no longer verify as valid publicly.</span>
        </label>
        <div v-if="revokeForm.errors.confirm" class="text-xs text-danger">{{ revokeForm.errors.confirm }}</div>
        <div v-if="revokeForm.errors.certificate" class="text-xs text-danger">{{ revokeForm.errors.certificate }}</div>
      </div>
      <template #footer>
        <button type="button" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm" @click="revokeOpen = false">Cancel</button>
        <button
          type="button"
          class="zaqa-btn zaqa-btn-danger px-4 py-2 text-sm"
          :disabled="revokeForm.processing"
          @click="submitRevoke"
        >
          Revoke certificate
        </button>
      </template>
    </AdminActionModal>
  </AdminLayout>
</template>
