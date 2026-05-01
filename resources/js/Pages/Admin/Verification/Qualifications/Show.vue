<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'
import AdminActionModal from '@/Components/AdminActionModal.vue'
import { computed, ref } from 'vue'

const props = defineProps<{
  qualification: any
  viewerUserId: number | null
  level1Users: Array<{ id: number; name: string; email: string }>
  can: { assign: boolean }
}>()

const assignOpen = ref(false)
const assignForm = useForm({ assigned_to_user_id: props.qualification.assigned_verifier_id ?? '', comment: '' })

const isForeign = computed(() => !!props.qualification.is_foreign)
const appNum = computed(() => props.qualification.application?.application_number ?? '—')
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <div class="text-xs font-semibold text-text-muted">Verification</div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">
          Qualification task #{{ qualification.id }} — {{ appNum }}
        </h1>
        <p class="mt-1 text-sm text-text-muted">Review and assign a single qualification verification item.</p>
      </div>
      <div class="flex items-center gap-2">
        <Link href="/admin/verification/pool" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Back to pool</Link>
        <button v-if="can.assign" class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm" @click="assignOpen = true">Assign</button>
      </div>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
      <div class="lg:col-span-2 space-y-6">
        <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="text-sm font-semibold text-text-primary">Applicant & parent application</div>
          <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <div>
              <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Application</div>
              <div class="mt-1 text-sm font-semibold text-text-primary">{{ qualification.application?.application_number ?? '—' }}</div>
              <div class="mt-1 text-xs text-text-muted">Payment: {{ qualification.application?.payment_status ?? '—' }}</div>
              <div class="mt-1 text-xs text-text-muted">Submitted: {{ qualification.application?.submitted_at ?? '—' }}</div>
            </div>
            <div>
              <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Applicant</div>
              <div class="mt-1 text-sm font-semibold text-text-primary">{{ qualification.application?.applicant_name ?? '—' }}</div>
            </div>
          </div>
        </div>

        <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="text-sm font-semibold text-text-primary">Qualification item</div>
          <div class="mt-4 grid gap-3 sm:grid-cols-2">
            <div>
              <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Title</div>
              <div class="mt-1 text-sm font-semibold text-text-primary">{{ qualification.title ?? '—' }}</div>
              <div class="mt-1 text-xs text-text-muted">Type: {{ qualification.qualification_type ?? '—' }}</div>
            </div>
            <div>
              <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Awarding Institution</div>
              <div class="mt-1 text-sm font-semibold text-text-primary">{{ qualification.awarding_institution ?? '—' }}</div>
              <div class="mt-1 text-xs text-text-muted">Country: {{ qualification.country ?? '—' }}</div>
              <div class="mt-1 text-xs text-text-muted">{{ isForeign ? 'Foreign' : 'Local' }}</div>
            </div>
          </div>
        </div>

        <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="text-sm font-semibold text-text-primary">Documents</div>
          <div v-if="qualification.documents?.length" class="mt-4 overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
                <tr>
                  <th class="px-4 py-3 text-left">Type</th>
                  <th class="px-4 py-3 text-left">File</th>
                  <th class="px-4 py-3 text-left">Version</th>
                  <th class="px-4 py-3 text-right">Actions</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border/60">
                <tr v-for="d in qualification.documents" :key="d.id">
                  <td class="px-4 py-3">{{ d.document_type }}</td>
                  <td class="px-4 py-3">{{ d.original_name }}</td>
                  <td class="px-4 py-3">v{{ d.version_number }}</td>
                  <td class="px-4 py-3 text-right">
                    <a :href="d.preview_url" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs">Preview</a>
                    <a :href="d.download_url" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs">Download</a>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-else class="mt-3 text-sm text-text-muted">No documents uploaded for this qualification item yet.</div>
        </div>
      </div>

      <div class="space-y-6">
        <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="text-sm font-semibold text-text-primary">Assignment</div>
          <div class="mt-3 text-sm text-text-primary">
            Assigned to: <span class="font-semibold">{{ qualification.assigned_verifier_id ? qualification.assigned_verifier_id : '—' }}</span>
          </div>
          <div class="mt-1 text-xs text-text-muted">Assigned at: {{ qualification.assigned_at ?? '—' }}</div>
        </div>
      </div>
    </div>

    <AdminActionModal v-model:open="assignOpen" title="Assign qualification task">
      <form
        @submit.prevent="
          assignForm.post(`/admin/verification/qualifications/${qualification.id}/assign`, {
            preserveScroll: true,
            onSuccess: () => {
              assignForm.reset('comment')
              assignOpen = false
            },
          })
        "
      >
        <div class="space-y-3">
          <select v-model="assignForm.assigned_to_user_id" class="zaqa-input h-10 w-full">
            <option value="">Select verifier</option>
            <option v-for="u in level1Users" :key="u.id" :value="u.id">{{ u.name }} ({{ u.email }})</option>
          </select>
          <textarea v-model="assignForm.comment" class="zaqa-input w-full" rows="3" placeholder="Optional comment" />
        </div>
        <div class="mt-4 flex justify-end gap-2">
          <button type="button" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm" @click="assignOpen = false">Cancel</button>
          <button type="submit" class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm" :disabled="assignForm.processing">Assign</button>
        </div>
      </form>
    </AdminActionModal>
  </AdminLayout>
</template>

