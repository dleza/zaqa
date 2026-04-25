<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminCenteredFormPage from '@/Components/AdminCenteredFormPage.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { ShieldCheck } from 'lucide-vue-next'

const props = defineProps<{
  permissions: Array<{ name: string }>
}>()

const form = useForm({
  name: '',
  permissions: [] as string[],
})

function togglePermission(name: string) {
  const set = new Set(form.permissions)
  if (set.has(name)) set.delete(name)
  else set.add(name)
  form.permissions = Array.from(set)
}

function submit() {
  form.post('/admin/roles', { preserveScroll: true })
}
</script>

<template>
  <AdminLayout>
    <AdminCenteredFormPage max-width="5xl">
      <template #header>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
              <ShieldCheck class="h-4 w-4" aria-hidden="true" />
              User management
            </div>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Create role</h1>
            <p class="mt-1 text-sm text-text-muted">Create a new role and attach permissions.</p>
          </div>

          <div class="flex items-center gap-2">
            <Link href="/admin/roles" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Back</Link>
          </div>
        </div>
      </template>

      <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-1">
          <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
            <form class="space-y-5" @submit.prevent="submit">
              <div>
                <label class="text-sm font-semibold text-text-primary">Role name</label>
                <input v-model="form.name" class="zaqa-input" type="text" autocomplete="off" />
                <div v-if="form.errors.name" class="mt-1 text-xs text-danger">{{ form.errors.name }}</div>
              </div>

              <div class="rounded-xl border border-border bg-surface-muted px-4 py-3 text-xs text-text-muted">
                Tip: include <span class="font-semibold text-text-primary">dashboard.view</span> for roles that must access the admin portal.
              </div>

              <div class="flex items-center justify-end gap-2">
                <button type="submit" class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm" :disabled="form.processing">
                  Create role
                </button>
              </div>
            </form>
          </div>
        </div>

        <div class="lg:col-span-2">
          <div class="overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
            <div class="border-b border-border bg-surface-muted px-5 py-4">
              <div class="text-sm font-semibold text-text-primary">Permissions</div>
              <div class="mt-1 text-xs text-text-muted">Select the permissions this role should have.</div>
            </div>

            <div class="p-5">
              <div class="grid gap-2 sm:grid-cols-2">
                <button
                  v-for="p in permissions"
                  :key="p.name"
                  type="button"
                  class="flex items-center justify-between gap-3 rounded-xl border px-3 py-2 text-left text-sm transition"
                  :class="form.permissions.includes(p.name) ? 'border-brand/30 bg-brand/10' : 'border-border bg-surface hover:bg-surface-muted'"
                  @click="togglePermission(p.name)"
                >
                  <span class="min-w-0 truncate font-semibold text-text-primary">{{ p.name }}</span>
                  <span class="zaqa-badge" :class="form.permissions.includes(p.name) ? 'zaqa-badge-success' : ''">
                    {{ form.permissions.includes(p.name) ? 'On' : 'Off' }}
                  </span>
                </button>
              </div>
              <div v-if="form.errors.permissions" class="mt-2 text-xs text-danger">{{ form.errors.permissions }}</div>
            </div>
          </div>
        </div>
      </div>
    </AdminCenteredFormPage>
  </AdminLayout>
</template>

