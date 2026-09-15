<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ApiError, api } from '../ResourcesCore/api'

type CurrentTerms = {
  document_type: 'terms'
  version: string
  document_url: string
  sample_data: boolean
}

const firstName = ref('')
const lastName = ref('')
const email = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const organizationName = ref('')
const nip = ref('')
const phone = ref('')
const street = ref('')
const houseNumber = ref('')
const unitNumber = ref('')
const postalCode = ref('')
const city = ref('')

const terms = ref<CurrentTerms | null>(null)
const termsAccepted = ref(false)
const pending = ref(false)
const loadingTerms = ref(false)
const registrationCompleted = ref(false)
const errorMessage = ref('')

function describeError(error: unknown): string {
  if (error instanceof ApiError) {
    if (error.status === 409) {
      return 'Konto z tym adresem e-mail już istnieje.'
    }

    if (error.status === 422) {
      return 'Sprawdź poprawność danych formularza i spróbuj ponownie.'
    }

    if (error.status === 429) {
      return 'Wykonano zbyt wiele prób. Spróbuj ponownie później.'
    }

    return error.message
  }

  return 'Nie udało się wykonać operacji. Spróbuj ponownie.'
}

function localDocumentUrl(value: string): boolean {
  return value.startsWith('/') && !value.startsWith('//')
}

async function loadTerms(): Promise<void> {
  loadingTerms.value = true
  errorMessage.value = ''

  try {
    const result = await api<CurrentTerms>('/api/v1/development/sample/legal/terms/current')
    const discovered = result.data

    if (
      discovered.document_type !== 'terms'
      || discovered.version.trim() === ''
      || !localDocumentUrl(discovered.document_url)
    ) {
      throw new Error('Invalid legal-document discovery response.')
    }

    terms.value = discovered
  } catch (error) {
    terms.value = null

    if (error instanceof ApiError && error.status === 404) {
      errorMessage.value = 'Rejestracja online jest chwilowo niedostępna, ponieważ nie opublikowano jeszcze aktywnej wersji regulaminu dla tego środowiska.'
      return
    }

    errorMessage.value = describeError(error)
  } finally {
    loadingTerms.value = false
  }
}

function companyAddressPayload(): {
  street: string
  house_number: string
  unit_number?: string
  postal_code: string
  city: string
  country_code: string
} | undefined {
  const values = [
    street.value,
    houseNumber.value,
    unitNumber.value,
    postalCode.value,
    city.value,
  ].map(value => value.trim())

  if (values.every(value => value === '')) {
    return undefined
  }

  if (
    street.value.trim() === ''
    || houseNumber.value.trim() === ''
    || postalCode.value.trim() === ''
    || city.value.trim() === ''
  ) {
    throw new Error('Uzupełnij ulicę, numer budynku, kod pocztowy i miejscowość albo pozostaw cały adres pusty.')
  }

  const address: {
    street: string
    house_number: string
    unit_number?: string
    postal_code: string
    city: string
    country_code: string
  } = {
    street: street.value.trim(),
    house_number: houseNumber.value.trim(),
    postal_code: postalCode.value.trim(),
    city: city.value.trim(),
    country_code: 'PL',
  }

  if (unitNumber.value.trim() !== '') {
    address.unit_number = unitNumber.value.trim()
  }

  return address
}

async function submitRegistration(): Promise<void> {
  errorMessage.value = ''

  if (terms.value === null) {
    errorMessage.value = 'Nie można utworzyć konta bez aktywnej wersji regulaminu.'
    return
  }

  if (!termsAccepted.value) {
    errorMessage.value = 'Zaakceptuj regulamin, aby utworzyć konto.'
    return
  }

  if (password.value !== passwordConfirmation.value) {
    errorMessage.value = 'Hasła nie są takie same.'
    return
  }

  let companyAddress
  try {
    companyAddress = companyAddressPayload()
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : 'Sprawdź adres firmy.'
    return
  }

  const payload: {
    first_name: string
    last_name: string
    email: string
    password: string
    organization_name: string
    accepted_terms_version: string
    marketing_consent: false
    nip?: string
    phone?: string
    company_address?: NonNullable<ReturnType<typeof companyAddressPayload>>
  } = {
    first_name: firstName.value,
    last_name: lastName.value,
    email: email.value,
    password: password.value,
    organization_name: organizationName.value,
    accepted_terms_version: terms.value.version,
    marketing_consent: false,
  }

  if (nip.value.trim() !== '') {
    payload.nip = nip.value.trim()
  }

  if (phone.value.trim() !== '') {
    payload.phone = phone.value.trim()
  }

  if (companyAddress !== undefined) {
    payload.company_address = companyAddress
  }

  pending.value = true

  try {
    await api<void>('/api/v1/auth/register', {
      method: 'POST',
      body: JSON.stringify(payload),
    })

    registrationCompleted.value = true
    password.value = ''
    passwordConfirmation.value = ''
  } catch (error) {
    errorMessage.value = describeError(error)
  } finally {
    pending.value = false
  }
}

onMounted(() => {
  void loadTerms()
})
</script>

<template>
  <main class="auth-page">
    <section class="auth-shell auth-shell-wide">
      <a
        class="auth-brand"
        href="/"
        aria-label="PrawkoNaRaz"
      >
        <strong>prawkonaraz</strong><span>.pl</span>
      </a>

      <div class="auth-card">
        <p class="auth-kicker">
          Panel OSK
        </p>
        <h1>Załóż konto</h1>
        <p class="auth-copy">
          Utwórz konto właściciela i podstawową organizację OSK. Rejestracja nie loguje automatycznie do panelu.
        </p>

        <p
          v-if="errorMessage"
          class="auth-alert error"
          role="alert"
        >
          {{ errorMessage }}
        </p>

        <div
          v-if="registrationCompleted"
          class="auth-alert success"
          role="status"
        >
          Konto i organizacja zostały utworzone. Możesz teraz przejść do logowania.
        </div>

        <form
          v-else
          class="auth-form"
          @submit.prevent="submitRegistration"
        >
          <div class="auth-form-grid">
            <label>
              Imię
              <input
                v-model="firstName"
                autocomplete="given-name"
                maxlength="120"
                required
                type="text"
              >
            </label>

            <label>
              Nazwisko
              <input
                v-model="lastName"
                autocomplete="family-name"
                maxlength="120"
                required
                type="text"
              >
            </label>
          </div>

          <label>
            E-mail
            <input
              v-model="email"
              autocomplete="email"
              maxlength="320"
              required
              type="email"
            >
          </label>

          <div class="auth-form-grid">
            <label>
              Hasło
              <input
                v-model="password"
                autocomplete="new-password"
                maxlength="1024"
                required
                type="password"
              >
            </label>

            <label>
              Powtórz hasło
              <input
                v-model="passwordConfirmation"
                autocomplete="new-password"
                maxlength="1024"
                required
                type="password"
              >
            </label>
          </div>

          <label>
            Nazwa OSK
            <input
              v-model="organizationName"
              autocomplete="organization"
              maxlength="255"
              required
              type="text"
            >
          </label>

          <div class="auth-form-grid">
            <label>
              NIP <span class="auth-optional">(opcjonalnie)</span>
              <input
                v-model="nip"
                autocomplete="off"
                maxlength="16"
                type="text"
              >
            </label>

            <label>
              Telefon <span class="auth-optional">(opcjonalnie)</span>
              <input
                v-model="phone"
                autocomplete="tel"
                maxlength="40"
                type="tel"
              >
            </label>
          </div>

          <details class="auth-details">
            <summary>Adres firmy (opcjonalnie)</summary>

            <div class="auth-details-fields">
              <label>
                Ulica
                <input
                  v-model="street"
                  autocomplete="address-line1"
                  maxlength="255"
                  type="text"
                >
              </label>

              <div class="auth-form-grid">
                <label>
                  Numer budynku
                  <input
                    v-model="houseNumber"
                    maxlength="32"
                    type="text"
                  >
                </label>

                <label>
                  Numer lokalu
                  <input
                    v-model="unitNumber"
                    maxlength="32"
                    type="text"
                  >
                </label>
              </div>

              <div class="auth-form-grid">
                <label>
                  Kod pocztowy
                  <input
                    v-model="postalCode"
                    autocomplete="postal-code"
                    maxlength="20"
                    type="text"
                  >
                </label>

                <label>
                  Miejscowość
                  <input
                    v-model="city"
                    autocomplete="address-level2"
                    maxlength="160"
                    type="text"
                  >
                </label>
              </div>
            </div>
          </details>

          <div
            v-if="loadingTerms"
            class="auth-legal-state"
          >
            Pobieranie aktualnej wersji regulaminu…
          </div>

          <template v-else-if="terms">
            <p
              v-if="terms.sample_data"
              class="auth-alert notice"
            >
              To środowisko używa przykładowego regulaminu developerskiego. Nie jest to dokument produkcyjny.
            </p>

            <label class="auth-check auth-terms-check">
              <input
                v-model="termsAccepted"
                required
                type="checkbox"
              >
              <span>
                Akceptuję
                <a
                  :href="terms.document_url"
                  rel="noopener"
                  target="_blank"
                >aktualny regulamin</a>.
              </span>
            </label>
          </template>

          <button
            class="button primary auth-submit"
            :disabled="pending || loadingTerms || terms === null"
            type="submit"
          >
            {{ pending ? 'Tworzenie konta…' : 'Utwórz konto' }}
          </button>
        </form>

        <div class="auth-links">
          <a href="/login">Masz już konto? Zaloguj się</a>
        </div>
      </div>

      <p class="auth-foot">
        PrawkoNaRaz · panel administracyjny OSK
      </p>
    </section>
  </main>
</template>
