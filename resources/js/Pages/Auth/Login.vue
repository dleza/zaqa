<script setup lang="ts">
import { ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import InputError from '@/Components/InputError.vue'
import { ArrowRight, Lock, Mail, ShieldCheck, User } from 'lucide-vue-next'
import AuthTopBar from '@/Components/AuthTopBar.vue'

const form = useForm({
  identifier: '',
  password: '',
  remember: false,
})

const showPassword = ref(false)

function submit() {
  form.post('/login')
}
</script>

<template>
  <GuestLayout :card="false" max-width-class="max-w-none" content-padding-class="p-0" :hide-header="true" :center-content="false">
    <div class="relative min-h-screen w-full">
      <AuthTopBar right-href="/register" right-label="Register and Apply" right-label-short="Register" />

      <div class="grid w-full lg:min-h-[calc(100vh-56px)] lg:grid-cols-2">
        <!-- Desktop branding panel (hidden on mobile) -->
        <aside class="relative hidden overflow-hidden bg-gradient-to-br from-[#061a3a] via-[#0b3a66] to-[#092a48] px-10 py-10 text-white sm:px-14 sm:py-14 lg:block lg:px-16 lg:py-16">
            <div aria-hidden="true" class="pointer-events-none absolute inset-0">
              <div class="absolute -top-20 -left-20 h-72 w-72 rounded-full bg-white/10 blur-3xl" />
              <div class="absolute -bottom-28 -right-20 h-[26rem] w-[26rem] rounded-full bg-amber-400/10 blur-3xl" />
              <div class="absolute inset-0 bg-[radial-gradient(circle_at_12%_18%,rgba(255,255,255,0.18),transparent_55%),radial-gradient(circle_at_80%_70%,rgba(255,255,255,0.12),transparent_55%)]" />
              <div class="absolute right-10 top-16 h-20 w-20 rounded-2xl bg-white/5 ring-1 ring-white/10" />
              <div class="absolute right-20 top-24 h-2 w-2 rounded-full bg-white/30" />
              <div class="absolute right-28 top-28 h-2 w-2 rounded-full bg-white/20" />
              <div class="absolute inset-0 opacity-[0.08] [background-image:radial-gradient(rgba(255,255,255,0.8)_1px,transparent_1px)] [background-size:18px_18px]" />
            </div>

            <div class="relative">
              <div class="mt-10">
                <div class="text-xs font-semibold uppercase tracking-wider text-white/55">
                  Zambia Qualifications Authority
                </div>
                <h1 class="mt-3 text-5xl font-semibold tracking-tight">Verification Portal</h1>
                <div class="mt-4 h-0.5 w-10 rounded-full bg-amber-300/80" />
                <p class="mt-6 max-w-md text-sm leading-relaxed text-white/75">
                  The official platform for secure qualification verification.
                </p>
              </div>

              <div class="mt-10 space-y-5">
                <div class="flex gap-4">
                  <span class="inline-flex h-11 w-11 items-center justify-center rounded-full bg-white/10 ring-1 ring-white/15">
                    <ShieldCheck class="h-5 w-5 text-white/90" aria-hidden="true" />
                  </span>
                  <div>
                    <div class="text-sm font-semibold">Trusted & Official</div>
                    <div class="mt-1 text-xs leading-relaxed text-white/70">
                      Verify qualifications with confidence through the official ZAQA system.
                    </div>
                  </div>
                </div>

                <div class="flex gap-4">
                  <span class="inline-flex h-11 w-11 items-center justify-center rounded-full bg-white/10 ring-1 ring-white/15">
                    <ArrowRight class="h-5 w-5 text-white/90" aria-hidden="true" />
                  </span>
                  <div>
                    <div class="text-sm font-semibold">Fast & Reliable</div>
                    <div class="mt-1 text-xs leading-relaxed text-white/70">
                      Quick verification process with real-time results.
                    </div>
                  </div>
                </div>

                <div class="flex gap-4">
                  <span class="inline-flex h-11 w-11 items-center justify-center rounded-full bg-white/10 ring-1 ring-white/15">
                    <Lock class="h-5 w-5 text-white/90" aria-hidden="true" />
                  </span>
                  <div>
                    <div class="text-sm font-semibold">Secure & Protected</div>
                    <div class="mt-1 text-xs leading-relaxed text-white/70">
                      Your data and information are always secure with us.
                    </div>
                  </div>
                </div>
              </div>

  
            </div>
          </aside>

        <section class="relative overflow-hidden bg-white px-4 py-6 sm:bg-[#eef4fb] sm:px-10 sm:py-10 lg:px-16 lg:py-12">
          <!-- Desktop/tablet decorative background (hidden on small screens) -->
          <div aria-hidden="true" class="pointer-events-none absolute inset-0 hidden sm:block">
            <div class="absolute -top-28 left-1/2 h-[46rem] w-[46rem] -translate-x-1/2 rounded-full bg-brand/18 blur-3xl" />
            <div class="absolute -bottom-28 -right-28 h-[40rem] w-[40rem] rounded-full bg-accent/10 blur-3xl" />
            <div class="absolute inset-0 bg-[radial-gradient(circle_at_65%_35%,rgba(11,58,102,0.08),transparent_55%)]" />
          </div>

          <div class="relative flex h-full flex-col">
            <div class="flex flex-1 items-center justify-center py-2 sm:py-6 lg:py-10">
              <div
                class="w-full max-w-[560px] rounded-2xl border border-border/70 bg-white px-5 py-7 text-center shadow-sm sm:rounded-3xl sm:bg-white/70 sm:px-8 sm:py-10 sm:shadow-2xl sm:backdrop-blur-sm"
              >
              <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand/10 sm:h-16 sm:w-16">
                <Lock class="h-7 w-7 text-brand" aria-hidden="true" />
              </div>

              <h2 class="mt-5 text-2xl font-semibold tracking-tight text-text-primary sm:mt-6 sm:text-3xl">Welcome back</h2>
              <p class="mt-2 text-sm text-text-muted">Log in using your email or phone number.</p>

              <form class="mt-6 w-full space-y-5 text-left sm:mt-8" @submit.prevent="submit">
            <div>
              <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Email or phone number</label>
              <div class="relative">
                <Mail class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted" aria-hidden="true" />
                <input
                  v-model="form.identifier"
                  type="text"
                  placeholder="Enter your email or phone number"
                  class="zaqa-input h-12 rounded-xl pl-10 transition focus:shadow-[0_0_0_4px_rgba(11,58,102,0.12)]"
                  autocomplete="username"
                />
              </div>
              <InputError :message="form.errors.identifier" />
            </div>

            <div>
              <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Password</label>
              <div class="relative">
                <Lock class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted" aria-hidden="true" />
                <input
                  v-model="form.password"
                  :type="showPassword ? 'text' : 'password'"
                  placeholder="Enter your password"
                  class="zaqa-input h-12 rounded-xl pl-10 pr-11 transition focus:shadow-[0_0_0_4px_rgba(11,58,102,0.12)]"
                  autocomplete="current-password"
                />
                <button
                  type="button"
                  class="absolute inset-y-0 right-2 inline-flex items-center justify-center rounded-md px-2 text-text-muted transition hover:bg-surface-muted hover:text-text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-surface"
                  :aria-label="showPassword ? 'Hide password' : 'Show password'"
                  @click="showPassword = !showPassword"
                >
                  <svg v-if="!showPassword" viewBox="0 0 24 24" fill="none" class="h-5 w-5" aria-hidden="true">
                    <path
                      d="M2.25 12s3.75-7.5 9.75-7.5S21.75 12 21.75 12s-3.75 7.5-9.75 7.5S2.25 12 2.25 12Z"
                      stroke="currentColor"
                      stroke-width="1.75"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                    />
                    <path
                      d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"
                      stroke="currentColor"
                      stroke-width="1.75"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                    />
                  </svg>
                  <svg v-else viewBox="0 0 24 24" fill="none" class="h-5 w-5" aria-hidden="true">
                    <path
                      d="M3 3l18 18"
                      stroke="currentColor"
                      stroke-width="1.75"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                    />
                    <path
                      d="M10.585 10.585A2.999 2.999 0 0 0 12 15a3 3 0 0 0 2.415-4.415"
                      stroke="currentColor"
                      stroke-width="1.75"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                    />
                    <path
                      d="M6.71 6.71C4.08 8.446 2.25 12 2.25 12s3.75 7.5 9.75 7.5c1.7 0 3.23-.39 4.56-1.03"
                      stroke="currentColor"
                      stroke-width="1.75"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                    />
                    <path
                      d="M9.53 4.77A9.34 9.34 0 0 1 12 4.5c6 0 9.75 7.5 9.75 7.5a16.46 16.46 0 0 1-3.27 4.46"
                      stroke="currentColor"
                      stroke-width="1.75"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                    />
                  </svg>
                </button>
              </div>
              <InputError :message="form.errors.password" />
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
              <label class="flex items-center gap-2 text-sm text-text-primary">
                <input v-model="form.remember" type="checkbox" class="rounded border-border text-brand focus:ring-brand/25" />
                Remember me
              </label>
              <Link href="/forgot-password" class="zaqa-link text-sm">Forgot password?</Link>
            </div>

            <button
              type="submit"
              class="group inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-brand to-brand-dark font-semibold text-brand-foreground shadow-md transition hover:-translate-y-[1px] hover:brightness-105 active:translate-y-[1px] disabled:opacity-70"
              :disabled="form.processing"
            >
              Log in
              <ArrowRight class="h-4 w-4 transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
            </button>
          </form>

              <div class="my-5 flex items-center gap-3 text-xs text-text-muted sm:my-6">
                <div class="h-px flex-1 bg-border" />
                <span>or</span>
                <div class="h-px flex-1 bg-border" />
              </div>

              <div class="text-center text-sm text-text-muted">
                New here?
                <Link href="/register" class="zaqa-link font-semibold">Register and Apply</Link>
                <ArrowRight class="inline h-4 w-4 translate-y-[1px] text-text-muted" aria-hidden="true" />
              </div>

              <div class="mt-8 flex items-center justify-center gap-2 text-xs text-text-muted sm:mt-10">
                <ShieldCheck class="h-4 w-4 text-text-muted" aria-hidden="true" />
                <span>Your information is secure and protected.</span>
              </div>
              </div>
            </div>
          </div>
        </section>
      </div>
    </div>
  </GuestLayout>
</template>
