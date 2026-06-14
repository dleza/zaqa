<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminCenteredFormPage from '@/Components/AdminCenteredFormPage.vue'
import PermissionSelector from '@/Components/Admin/PermissionSelector.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { KeyRound, ShieldCheck, Sparkles } from 'lucide-vue-next'
import { computed } from 'vue'

const props = defineProps<{
  role: { id: number; name: string; permissions: string[] }
  permissions: Array<{ name: string }>
}>()

const form = useForm({
  name: props.role.name,
  permissions: [...(props.role.permissions ?? [])] as string[],
})

const moduleCount = computed(() => new Set(props.permissions.map((permission) => permission.name.split('.')[0] ?? 'other')).size)

function save() {
  form.put(`/admin/roles/${props.role.id}`, { preserveScroll: true })
}
</script>

<template>
  <AdminLayout>
    <AdminCenteredFormPage max-width="8xl">
      <template #header>
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
          <div>
            <div class="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.18em] text-text-muted">
              <ShieldCheck class="h-4 w-4" aria-hidden="true" />
              User management / Roles
            </div>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight text-text-primary">Edit role</h1>
            <p class="mt-1 text-sm text-text-muted">Update the role name and reorganize permissions by module.</p>
          </div>

          <Link href="/admin/roles" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">
            Back to roles
          </Link>
        </div>
      </template>

      <form class="space-y-5" @submit.prevent="save">
        <div class="grid gap-5 xl:grid-cols-[380px_minmax(0,1fr)] 2xl:gap-6 2xl:grid-cols-[400px_minmax(0,1fr)]">
          <aside class="space-y-5 xl:sticky xl:top-24 xl:self-start">
            <section class="rounded-3xl border border-border/70 bg-surface p-6 shadow-[0_20px_50px_-34px_rgba(15,23,42,0.38)]">
              <div class="flex items-start justify-between gap-4">
                <div>
                  <div class="text-xs font-semibold uppercase tracking-[0.18em] text-text-muted">Role details</div>
                  <h2 class="mt-2 text-lg font-semibold text-text-primary">Identity & access</h2>
                </div>
                <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-surface-muted/70 text-text-primary ring-1 ring-border/60">
                  <KeyRound class="h-5 w-5" aria-hidden="true" />
                </span>
              </div>

              <div class="mt-5 space-y-5">
                <div>
                  <label class="text-sm font-semibold text-text-primary">Role name</label>
                  <input v-model="form.name" class="zaqa-input mt-2" type="text" autocomplete="off" />
                  <div class="mt-2 text-xs text-text-muted">Keep role names short and specific to the team or capability.</div>
                  <div v-if="form.errors.name" class="mt-2 text-xs text-danger">{{ form.errors.name }}</div>
                </div>

                <div class="rounded-2xl border border-border/70 bg-surface-muted/35 px-4 py-4 text-sm text-text-muted">
                  Ensure staff roles include <span class="font-semibold text-text-primary">dashboard.view</span> when they need to access the admin workspace.
                </div>
              </div>
            </section>

            <section class="rounded-3xl border border-border/70 bg-surface p-6 shadow-[0_20px_50px_-34px_rgba(15,23,42,0.38)]">
              <div class="flex items-center gap-3">
                <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-brand/10 text-brand">
                  <Sparkles class="h-5 w-5" aria-hidden="true" />
                </span>
                <div>
                  <div class="text-sm font-semibold text-text-primary">Selection summary</div>
                  <div class="mt-1 text-xs text-text-muted">Review coverage before saving changes.</div>
                </div>
              </div>

              <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2">
                <div class="rounded-2xl bg-surface-muted/35 px-4 py-3">
                  <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-text-muted">Selected</div>
                  <div class="mt-1 text-lg font-semibold text-text-primary">{{ form.permissions.length }}</div>
                </div>
                <div class="rounded-2xl bg-surface-muted/35 px-4 py-3">
                  <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-text-muted">Available modules</div>
                  <div class="mt-1 text-lg font-semibold text-text-primary">{{ moduleCount }}</div>
                </div>
              </div>
            </section>
          </aside>

          <section class="min-w-0 rounded-3xl border border-border/70 bg-surface p-6 shadow-[0_20px_50px_-34px_rgba(15,23,42,0.38)] sm:p-7">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
              <div>
                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-text-muted">Permission assignment</div>
                <h2 class="mt-2 text-lg font-semibold text-text-primary">Manage permissions by module</h2>
                <p class="mt-1 text-sm text-text-muted">Preserve only the permissions this role needs, grouped for easier review.</p>
              </div>
              <span class="inline-flex items-center rounded-full border border-brand/15 bg-brand/10 px-3 py-1 text-xs font-semibold text-brand">
                Selected: {{ form.permissions.length }}
              </span>
            </div>

            <div class="mt-6">
              <PermissionSelector v-model="form.permissions" :permissions="permissions" />
            </div>
            <div v-if="form.errors.permissions" class="mt-3 text-xs text-danger">{{ form.errors.permissions }}</div>
          </section>
        </div>

        <div class="sticky bottom-4 z-10 pt-1">
          <div class="flex w-full flex-col gap-3 rounded-2xl border border-border/70 bg-surface/95 px-4 py-4 shadow-[0_18px_44px_-26px_rgba(15,23,42,0.38)] backdrop-blur sm:flex-row sm:items-center sm:justify-between sm:px-5">
            <div class="text-sm text-text-muted">
              <span class="font-semibold text-text-primary">{{ form.permissions.length }}</span> permissions selected for <span class="font-semibold text-text-primary">{{ form.name || props.role.name }}</span>.
            </div>

            <div class="flex flex-wrap items-center gap-2">
              <Link href="/admin/roles" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">
                Cancel
              </Link>
              <button type="submit" class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm" :disabled="form.processing">
                Save changes
              </button>
            </div>
          </div>
        </div>
      </form>
    </AdminCenteredFormPage>
  </AdminLayout>
</template>
