<script setup lang="ts">
import { computed, ref } from 'vue'
import { ApiError, api } from '../ResourcesCore/api'

type AuthMode = 'login' | 'forgot' | 'reset'

type LoginResponse = {
  return_url: string
}

const searchParams = new URLSearchParams(window.location.search)
const mode = computed<AuthMode>(() => {
  if (window.location.pathname === '/forgot-password') {
    return 'forgot'
  }

  if (window.location.pathname === '/reset-password') {
    return 'reset'
  }

  return 'login'
})

const identifier = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const rememberMe = ref(false)
const pending = ref(false)
const errorMessage = ref('')
const forgotAccepted = ref(false)
const resetCompleted = ref(false)
const resetToken = searchParams.get('token') ?? ''

function requestedReturnUrl(): string | undefined {
  const value = searchParams.get('return_url')
  if (value === null || !value.startsWith('/') || value.startsWith('//')) {
    return undefined
  }

  return value
}

function describeError(error: unknown): string {
  if (error instanceof ApiError) {
    if (error.status === 429) {
      return 'Wykonano zbyt wiele prób. Spróbuj ponownie później.'
    }

    return error.message
  }

  return 'Nie udało się wykonać operacji. Spróbuj ponownie.'
}

async function submitLogin(): Promise<void> {
  errorMessage.value = ''
  pending.value = true

  try {
    const returnUrl = requestedReturnUrl()
    const payload: {
      identifier: string
      password: string
      remember_me: boolean
      return_url?: string
    } = {
      identifier: identifier.value,
      password: password.value,
      remember_me: rememberMe.value,
    }

    if (returnUrl !== undefined) {
      payload.return_url = returnUrl
    }

    const result = await api<LoginResponse>('/api/v1/auth/login', {
      method: 'POST',
      body: JSON.stringify(payload),
    })

    window.location.assign(result.data.return_url || '/')
  } catch (error) {
    errorMessage.value = describeError(error)
  } finally {
    pending.value = false
  }
}

async function submitForgot(): Promise<void> {
  errorMessage.value = ''
  pending.value = true

  try {
    await api<void>('/api/v1/auth/password/forgot', {
      method: 'POST',
      body: JSON.stringify({ identifier: identifier.value }),
    })
    forgotAccepted.value = true
  } catch (error) {
    errorMessage.value = describeError(error)
  } finally {
    pending.value = false
  }
}

async function submitReset(): Promise<void> {
  errorMessage.value = ''

  if (resetToken === '') {
    errorMessage.value = 'Link do ustawienia nowego hasła jest nieprawidłowy lub niekompletny.'
    return
  }

  if (password.value !== passwordConfirmation.value) {
    errorMessage.value = 'Hasła nie są takie same.'
    return
  }

  pending.value = true

  try {
    await api<void>('/api/v1/auth/password/reset', {
      method: 'POST',
      body: JSON.stringify({
        token: resetToken,
        password: password.value,
      }),
    })
    window.history.replaceState({}, '', '/reset-password')
    resetCompleted.value = true
    password.value = ''
    passwordConfirmation.value = ''
  } catch (error) {
    errorMessage.value = describeError(error)
  } finally {
    pending.value = false
  }
}
</script>

<template>
  <main class="auth-page">
    <section class="auth-shell">
      <a class="auth-brand" href="/" aria-label="PrawkoNaRaz">
        <strong>prawkonaraz</strong><span>.pl</span>
      </a>

      <div class="auth-card">
        <div v-if="mode === 'login'">
          <p class="auth-kicker">Panel OSK</p>
          <h1>Zaloguj się</h1>
          <p class="auth-copy">Użyj swojego identyfikatora i hasła do panelu.</p>

          <p v-if="errorMessage" class="auth-alert error" role="alert">
            {{ errorMessage }}
          </p>

          <form class="auth-form" @submit.prevent="submitLogin">
            <label>
              Login lub e-mail
              <input
                v-model="identifier"
                autocomplete="username"
                maxlength="320"
                required
                type="text"
              >
            </label>

            <label>
              Hasło
              <input
                v-model="password"
                autocomplete="current-password"
                maxlength="1024"
                required
                type="password"
              >
            </label>

            <label class="auth-check">
              <input v-model="rememberMe" type="checkbox">
              <span>Zapamiętaj mnie</span>
            </label>

            <button class="button primary auth-submit" :disabled="pending" type="submit">
              {{ pending ? 'Logowanie…' : 'Zaloguj się' }}
            </button>
          </form>

          <div class="auth-links">
            <a href="/forgot-password">Nie pamiętasz hasła?</a>
          </div>
        </div>

        <div v-else-if="mode === 'forgot'">
          <p class="auth-kicker">Odzyskiwanie dostępu</p>
          <h1>Ustaw nowe hasło</h1>
          <p class="auth-copy">
            Podaj login lub e-mail. Ze względów bezpieczeństwa odpowiedź nie potwierdza,
            czy konto istnieje.
          </p>

          <p v-if="errorMessage" class="auth-alert error" role="alert">
            {{ errorMessage }}
          </p>

          <div v-if="forgotAccepted" class="auth-alert success" role="status">
            Jeśli konto kwalifikuje się do samodzielnego resetu, wiadomość z dalszymi
            instrukcjami została wysłana na zweryfikowany adres.
          </div>

          <form v-else class="auth-form" @submit.prevent="submitForgot">
            <label>
              Login lub e-mail
              <input
                v-model="identifier"
                autocomplete="username"
                maxlength="320"
                required
                type="text"
              >
            </label>

            <button class="button primary auth-submit" :disabled="pending" type="submit">
              {{ pending ? 'Wysyłanie…' : 'Wyślij instrukcję' }}
            </button>
          </form>

          <div class="auth-links">
            <a href="/login">Wróć do logowania</a>
          </div>
        </div>

        <div v-else>
          <p class="auth-kicker">Odzyskiwanie dostępu</p>
          <h1>Nowe hasło</h1>
          <p class="auth-copy">
            Po udanym ustawieniu nowego hasła wszystkie dotychczasowe sesje zostaną
            unieważnione przez backend.
          </p>

          <p v-if="errorMessage" class="auth-alert error" role="alert">
            {{ errorMessage }}
          </p>

          <div v-if="resetCompleted" class="auth-alert success" role="status">
            Hasło zostało zmienione. Możesz zalogować się ponownie.
          </div>

          <form v-else class="auth-form" @submit.prevent="submitReset">
            <label>
              Nowe hasło
              <input
                v-model="password"
                autocomplete="new-password"
                maxlength="1024"
                required
                type="password"
              >
            </label>

            <label>
              Powtórz nowe hasło
              <input
                v-model="passwordConfirmation"
                autocomplete="new-password"
                maxlength="1024"
                required
                type="password"
              >
            </label>

            <button
              class="button primary auth-submit"
              :disabled="pending || resetToken === ''"
              type="submit"
            >
              {{ pending ? 'Zapisywanie…' : 'Ustaw nowe hasło' }}
            </button>
          </form>

          <div class="auth-links">
            <a href="/login">Przejdź do logowania</a>
          </div>
        </div>
      </div>

      <p class="auth-foot">
        PrawkoNaRaz · panel administracyjny OSK
      </p>
    </section>
  </main>
</template>
