<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ApiError, api } from '../ResourcesCore/api'

type AcceptedTerm = {
  version: string
  accepted_at: string
  accepted_by_user_id: string
  document_url: string | null
}

type SettingsProjection = {
  version: number
  basic_data: {
    first_name: string
    last_name: string
    email: string
  }
  company_data: {
    company_name: string
    street: string | null
    house_number: string | null
    unit_number: string | null
    city: string | null
    postal_code: string | null
    phone: string | null
  }
  accepted_terms: AcceptedTerm[]
}

type SettingsForm = {
  first_name: string
  last_name: string
  company_name: string
  street: string
  house_number: string
  unit_number: string
  city: string
  postal_code: string
  phone: string
}

const loading = ref(true)
const saving = ref(false)
const error = ref('')
const notice = ref('')
const version = ref(0)
const email = ref('')
const acceptedTerms = ref<AcceptedTerm[]>([])
const form = ref<SettingsForm>(emptyForm())
const loadedForm = ref<SettingsForm>(emptyForm())

function emptyForm(): SettingsForm {
  return {
    first_name: '',
    last_name: '',
    company_name: '',
    street: '',
    house_number: '',
    unit_number: '',
    city: '',
    postal_code: '',
    phone: '',
  }
}

function text(value: string | null): string {
  return value ?? ''
}

function applyProjection(data: SettingsProjection): void {
  version.value = data.version
  email.value = data.basic_data.email
  acceptedTerms.value = data.accepted_terms
  const next: SettingsForm = {
    first_name: data.basic_data.first_name,
    last_name: data.basic_data.last_name,
    company_name: data.company_data.company_name,
    street: text(data.company_data.street),
    house_number: text(data.company_data.house_number),
    unit_number: text(data.company_data.unit_number),
    city: text(data.company_data.city),
    postal_code: text(data.company_data.postal_code),
    phone: text(data.company_data.phone),
  }
  form.value = { ...next }
  loadedForm.value = { ...next }
}

function describeError(cause: unknown): string {
  if (cause instanceof ApiError) {
    if (cause.status === 409) {
      return 'Ustawienia zmieniły się od czasu ich wczytania. Wczytaj dane ponownie przed kolejnym zapisem.'
    }

    if (cause.status === 403) {
      return 'Nie masz uprawnień do wykonania tej operacji.'
    }

    const firstFieldError = Object.values(cause.fields).flat()[0]
    return firstFieldError ?? cause.message
  }

  return 'Nie udało się wykonać operacji. Spróbuj ponownie.'
}

async function loadSettings(): Promise<void> {
  loading.value = true
  error.value = ''
  notice.value = ''

  try {
    const response = await api<SettingsProjection>('/api/v1/organization/settings')
    applyProjection(response.data)
  } catch (cause) {
    error.value = describeError(cause)
  } finally {
    loading.value = false
  }
}

function trimmed(field: keyof SettingsForm): string {
  return form.value[field].trim()
}

function nullable(field: 'unit_number' | 'phone'): string | null {
  const value = trimmed(field)
  return value === '' ? null : value
}

function buildChanges(): {
  basic_data?: Record<string, string>
  company_data?: Record<string, string | null>
} {
  const basic: Record<string, string> = {}
  const company: Record<string, string | null> = {}

  for (const field of ['first_name', 'last_name'] as const) {
    const value = trimmed(field)
    if (value !== loadedForm.value[field]) {
      basic[field] = value
    }
  }

  for (const field of ['company_name', 'street', 'house_number', 'city', 'postal_code'] as const) {
    const value = trimmed(field)
    if (value !== loadedForm.value[field]) {
      company[field] = value
    }
  }

  for (const field of ['unit_number', 'phone'] as const) {
    const value = nullable(field)
    const previous = loadedForm.value[field].trim() === '' ? null : loadedForm.value[field].trim()
    if (value !== previous) {
      company[field] = value
    }
  }

  return {
    ...(Object.keys(basic).length > 0 ? { basic_data: basic } : {}),
    ...(Object.keys(company).length > 0 ? { company_data: company } : {}),
  }
}

async function saveSettings(): Promise<void> {
  error.value = ''
  notice.value = ''

  const payload = buildChanges()
  if (Object.keys(payload).length === 0) {
    notice.value = 'Nie ma zmian do zapisania.'
    return
  }

  saving.value = true

  try {
    const response = await api<SettingsProjection>('/api/v1/organization/settings', {
      method: 'PATCH',
      idempotent: true,
      headers: {
        'If-Match': `"v${version.value}"`,
      },
      body: JSON.stringify(payload),
    })
    applyProjection(response.data)
    notice.value = 'Ustawienia zostały zapisane.'
  } catch (cause) {
    error.value = describeError(cause)
  } finally {
    saving.value = false
  }
}

function safeDocumentUrl(value: string | null): string | null {
  if (value === null || !value.startsWith('/') || value.startsWith('//')) {
    return null
  }

  return value
}

function acceptedAt(value: string): string {
  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) {
    return value
  }

  return new Intl.DateTimeFormat('pl-PL', {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(parsed)
}

onMounted(loadSettings)
</script>

<template>
  <div class="app-shell">
    <aside class="sidebar">
      <a
        class="brand"
        href="/"
      >
        OSK
        <strong>Panel</strong>
      </a>

      <nav
        class="main-nav"
        aria-label="Główna nawigacja"
      >
        <a href="/">Panel główny</a>
        <a href="/kursanci">Kursanci</a>
        <a href="/kalendarz">Kalendarz</a>
        <a href="/historia-zakupow">Historia zakupów</a>
        <a
          class="active"
          href="/ustawienia"
        >
          Ustawienia
        </a>
      </nav>

      <div class="sidebar-foot">
        Stage 5 · Core v1
      </div>
    </aside>

    <main class="workspace settings-workspace">
      <header class="workspace-header">
        <div>
          <div class="eyebrow">
            Konto OSK
          </div>
          <h1>Ustawienia konta</h1>
          <p>
            Dane podstawowe i firmowe działają niezależnie od opcjonalnej integracji PKK.
          </p>
        </div>

        <div class="header-actions">
          <button
            class="button ghost"
            type="button"
            :disabled="loading || saving"
            @click="loadSettings"
          >
            Wczytaj ponownie
          </button>
          <button
            class="button primary"
            type="button"
            :disabled="loading || saving"
            @click="saveSettings"
          >
            {{ saving ? 'Zapisywanie…' : 'Zapisz' }}
          </button>
        </div>
      </header>

      <div
        v-if="notice"
        class="notice success"
        role="status"
      >
        {{ notice }}
      </div>

      <div
        v-if="error"
        class="notice error"
        role="alert"
      >
        {{ error }}
      </div>

      <div
        v-if="loading"
        class="loading-card"
      >
        Ładowanie ustawień…
      </div>

      <div
        v-else
        class="settings-grid"
      >
        <section class="settings-card">
          <header class="settings-card-head">
            <div>
              <span>Dane podstawowe</span>
              <h2>Właściciel konta</h2>
            </div>
            <span class="settings-version">wersja {{ version }}</span>
          </header>

          <div class="settings-fields two-columns">
            <label>
              Imię
              <input
                v-model="form.first_name"
                autocomplete="given-name"
                maxlength="120"
                type="text"
              >
            </label>

            <label>
              Nazwisko
              <input
                v-model="form.last_name"
                autocomplete="family-name"
                maxlength="120"
                type="text"
              >
            </label>

            <label class="settings-field-wide">
              Email
              <input
                :value="email"
                autocomplete="email"
                readonly
                type="email"
              >
              <small>
                Adres e-mail jest tylko do odczytu. Zmiana wymaga osobnego, zweryfikowanego procesu tożsamości.
              </small>
            </label>
          </div>
        </section>

        <section class="settings-card">
          <header class="settings-card-head">
            <div>
              <span>Dane firmy</span>
              <h2>Profil OSK</h2>
            </div>
          </header>

          <div class="settings-fields two-columns">
            <label class="settings-field-wide">
              Nazwa firmy
              <input
                v-model="form.company_name"
                maxlength="255"
                type="text"
              >
            </label>

            <label>
              Ulica
              <input
                v-model="form.street"
                autocomplete="address-line1"
                maxlength="255"
                type="text"
              >
            </label>

            <label>
              Nr domu
              <input
                v-model="form.house_number"
                maxlength="32"
                type="text"
              >
            </label>

            <label>
              Nr lokalu
              <input
                v-model="form.unit_number"
                autocomplete="address-line2"
                maxlength="32"
                type="text"
              >
            </label>

            <label>
              Miejscowość
              <input
                v-model="form.city"
                autocomplete="address-level2"
                maxlength="160"
                type="text"
              >
            </label>

            <label>
              Kod pocztowy
              <input
                v-model="form.postal_code"
                autocomplete="postal-code"
                maxlength="20"
                type="text"
              >
            </label>

            <label>
              Telefon
              <input
                v-model="form.phone"
                autocomplete="tel"
                maxlength="40"
                type="tel"
              >
            </label>
          </div>
        </section>

        <section class="settings-card settings-pkk-card">
          <header class="settings-card-head">
            <div>
              <span>Integracje</span>
              <h2>Dane API PKK</h2>
            </div>
            <span class="status-pill muted">Zamrożone</span>
          </header>

          <p class="settings-explanation">
            PKK/PWPW jest opcjonalnym bounded contextem i nie jest wymagane do działania panelu.
            Ten ekran nie odczytuje ani nie zapisuje konfiguracji PKK, dopóki integracja nie zostanie jawnie odblokowana.
          </p>
        </section>

        <section class="settings-card">
          <header class="settings-card-head">
            <div>
              <span>Regulamin</span>
              <h2>Zaakceptowane wersje</h2>
            </div>
          </header>

          <p
            v-if="acceptedTerms.length === 0"
            class="muted-copy"
          >
            Brak zapisanej historii zaakceptowanego regulaminu.
          </p>

          <div
            v-else
            class="settings-terms"
          >
            <article
              v-for="term in acceptedTerms"
              :key="`${term.version}-${term.accepted_at}`"
              class="settings-term-row"
            >
              <div>
                <strong>{{ term.version }}</strong>
                <span>Zaakceptowano {{ acceptedAt(term.accepted_at) }}</span>
              </div>

              <a
                v-if="safeDocumentUrl(term.document_url)"
                class="button ghost small"
                :href="safeDocumentUrl(term.document_url) ?? undefined"
              >
                Zobacz mój regulamin
              </a>
              <span
                v-else
                class="settings-document-unavailable"
              >
                Treść tej wersji nie jest jeszcze dostępna online
              </span>
            </article>
          </div>
        </section>
      </div>
    </main>
  </div>
</template>
