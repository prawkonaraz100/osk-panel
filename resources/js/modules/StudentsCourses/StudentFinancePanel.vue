<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ApiError, api } from '../ResourcesCore/api'

type Money = { amount_minor: number; currency: string }

type CourseOption = {
  id: string
  driving_category_code: string
  training_type: 'basic' | 'supplementary'
  cancelled_at: string | null
}

type Charge = {
  id: string
  student_id: string
  course_enrollment_id: string | null
  title: string
  original_amount: Money
  paid_amount: Money
  remaining_amount: Money
  status: 'open' | 'partially_paid' | 'paid' | 'cancelled'
  due_at: string | null
  cancelled_at: string | null
  created_at: string
}

type Payment = {
  id: string
  student_id: string
  charge_id: string
  amount: Money
  paid_at: string
  payment_method: string | null
  note: string | null
  reversed_at: string | null
  reversal_reason: string | null
  created_at: string
}

type FinanceSummary = {
  total_charged: Money
  total_paid: Money
  balance: Money
}

const props = defineProps<{
  studentId: string
  courses: CourseOption[]
  archived: boolean
}>()

const loading = ref(false)
const saving = ref(false)
const error = ref('')
const notice = ref('')
const charges = ref<Charge[]>([])
const payments = ref<Payment[]>([])
const summary = ref<FinanceSummary>({
  total_charged: { amount_minor: 0, currency: 'PLN' },
  total_paid: { amount_minor: 0, currency: 'PLN' },
  balance: { amount_minor: 0, currency: 'PLN' },
})
const drawer = ref<'charge' | 'payment' | 'history' | null>(null)

const chargeTitle = ref('')
const chargeAmount = ref('')
const chargeCourseId = ref('')
const chargeDueAt = ref('')

const paymentChargeId = ref('')
const paymentAmount = ref('')
const paymentPaidAt = ref('')
const paymentMethod = ref('')
const paymentNote = ref('')

onMounted(loadFinance)

async function loadFinance(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const [chargeResult, paymentResult, summaryResult] = await Promise.all([
      api<Charge[]>(`/api/v1/students/${props.studentId}/charges`),
      api<Payment[]>(`/api/v1/students/${props.studentId}/payments`),
      api<FinanceSummary>(`/api/v1/students/${props.studentId}/finance-summary`),
    ])
    charges.value = chargeResult.data
    payments.value = paymentResult.data
    summary.value = summaryResult.data
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    loading.value = false
  }
}

function openCharge(): void {
  chargeTitle.value = ''
  chargeAmount.value = ''
  chargeCourseId.value = ''
  chargeDueAt.value = ''
  error.value = ''
  drawer.value = 'charge'
}

function openPayment(charge?: Charge): void {
  paymentChargeId.value = charge?.id ?? openCharges()[0]?.id ?? ''
  paymentAmount.value = charge ? minorToInput(charge.remaining_amount.amount_minor) : ''
  paymentPaidAt.value = currentLocalDateTime()
  paymentMethod.value = ''
  paymentNote.value = ''
  error.value = ''
  drawer.value = 'payment'
}

function openHistory(): void {
  error.value = ''
  drawer.value = 'history'
}

function closeDrawer(): void {
  drawer.value = null
  error.value = ''
}

async function saveCharge(): Promise<void> {
  const title = chargeTitle.value.trim()
  if (!title) {
    error.value = 'Podaj tytuł należności.'
    return
  }

  let amountMinor: number
  try {
    amountMinor = plnToMinor(chargeAmount.value, true)
  } catch (caught: unknown) {
    handleError(caught)
    return
  }

  saving.value = true
  error.value = ''
  try {
    await api<Charge>(`/api/v1/students/${props.studentId}/charges`, {
      method: 'POST',
      idempotent: true,
      body: JSON.stringify({
        title,
        amount: { amount_minor: amountMinor, currency: 'PLN' },
        course_enrollment_id: chargeCourseId.value || null,
        due_at: chargeDueAt.value || null,
      }),
    })
    notice.value = 'Należność została dodana.'
    closeDrawer()
    await loadFinance()
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    saving.value = false
  }
}

async function savePayment(): Promise<void> {
  if (!paymentChargeId.value) {
    error.value = 'Wybierz należność.'
    return
  }
  if (!paymentPaidAt.value) {
    error.value = 'Podaj datę wpłaty.'
    return
  }

  let amountMinor: number
  try {
    amountMinor = plnToMinor(paymentAmount.value, false)
  } catch (caught: unknown) {
    handleError(caught)
    return
  }

  saving.value = true
  error.value = ''
  try {
    await api<Payment>(`/api/v1/students/${props.studentId}/payments`, {
      method: 'POST',
      idempotent: true,
      body: JSON.stringify({
        charge_id: paymentChargeId.value,
        amount: { amount_minor: amountMinor, currency: 'PLN' },
        paid_at: new Date(paymentPaidAt.value).toISOString(),
        payment_method: paymentMethod.value || null,
        note: paymentNote.value.trim() || null,
      }),
    })
    notice.value = 'Wpłata została zapisana.'
    closeDrawer()
    await loadFinance()
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    saving.value = false
  }
}

async function cancelCharge(charge: Charge): Promise<void> {
  const reason = window.prompt('Podaj powód anulowania należności:')
  if (!reason?.trim()) return

  await mutate(
    `/api/v1/students/${props.studentId}/charges/${charge.id}/cancel`,
    { reason: reason.trim() },
    'Należność została anulowana.',
  )
}

async function reversePayment(payment: Payment): Promise<void> {
  const reason = window.prompt('Podaj powód korekty wpłaty:')
  if (!reason?.trim()) return

  await mutate(
    `/api/v1/students/${props.studentId}/payments/${payment.id}/reverse`,
    { reason: reason.trim() },
    'Wpłata została skorygowana.',
  )
}

async function mutate(path: string, body: Record<string, unknown>, message: string): Promise<void> {
  saving.value = true
  error.value = ''
  try {
    await api(path, {
      method: 'POST',
      idempotent: true,
      body: JSON.stringify(body),
    })
    notice.value = message
    await loadFinance()
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    saving.value = false
  }
}

function openCharges(): Charge[] {
  return charges.value.filter((charge) => charge.status === 'open' || charge.status === 'partially_paid')
}

function paymentsForCharge(chargeId: string): number {
  return payments.value.filter((payment) => payment.charge_id === chargeId && !payment.reversed_at).length
}

function chargeTitleFor(payment: Payment): string {
  return charges.value.find((charge) => charge.id === payment.charge_id)?.title ?? 'Należność'
}

function courseLabel(courseId: string | null): string {
  if (!courseId) return 'Bez przypisanego kursu'
  const course = props.courses.find((item) => item.id === courseId)
  if (!course) return 'Powiązany kurs'
  return `Kurs kat. ${course.driving_category_code}`
}

function statusLabel(status: Charge['status']): string {
  return {
    open: 'Do zapłaty',
    partially_paid: 'Częściowo opłacona',
    paid: 'Opłacona',
    cancelled: 'Anulowana',
  }[status]
}

function paymentMethodLabel(method: string | null): string {
  return {
    cash: 'Gotówka',
    bank_transfer: 'Przelew',
    card: 'Karta',
    other: 'Inne',
  }[method ?? ''] ?? 'Bez metody'
}

function money(value: Money): string {
  return new Intl.NumberFormat('pl-PL', {
    style: 'currency',
    currency: value.currency,
  }).format(value.amount_minor / 100)
}

function formatDate(value: string): string {
  return new Intl.DateTimeFormat('pl-PL', { dateStyle: 'medium' }).format(new Date(value))
}

function formatDateTime(value: string): string {
  return new Intl.DateTimeFormat('pl-PL', {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(value))
}

function currentLocalDateTime(): string {
  const now = new Date()
  const local = new Date(now.getTime() - now.getTimezoneOffset() * 60_000)
  return local.toISOString().slice(0, 16)
}

function minorToInput(amountMinor: number): string {
  const whole = Math.floor(amountMinor / 100)
  const cents = String(amountMinor % 100).padStart(2, '0')
  return `${whole},${cents}`
}

function plnToMinor(raw: string, allowZero: boolean): number {
  const normalized = raw.trim().replace(/\s+/g, '').replace(',', '.')
  const match = normalized.match(/^(\d+)(?:\.(\d{1,2}))?$/)
  if (!match) throw new Error('Podaj poprawną kwotę, np. 3500 lub 3500,50.')

  const whole = Number.parseInt(match[1] ?? '0', 10)
  const cents = Number.parseInt((match[2] ?? '').padEnd(2, '0') || '0', 10)
  const amountMinor = whole * 100 + cents
  if (!Number.isSafeInteger(amountMinor) || (allowZero ? amountMinor < 0 : amountMinor <= 0)) {
    throw new Error(allowZero ? 'Kwota nie może być ujemna.' : 'Kwota wpłaty musi być większa od zera.')
  }

  return amountMinor
}

function handleError(caught: unknown): void {
  if (caught instanceof ApiError) {
    error.value = caught.message
    return
  }
  error.value = caught instanceof Error ? caught.message : 'Nie udało się wykonać operacji finansowej.'
}
</script>

<template>
  <section class="detail-card finance-panel">
    <div class="card-heading split">
      <div>
        <span class="section-kicker">Płatności</span>
        <h2>Finanse kursanta</h2>
      </div>
      <div class="row-actions">
        <button
          class="button ghost"
          type="button"
          :disabled="archived"
          @click="openHistory"
        >
          Historia wpłat
        </button>
        <button
          class="button ghost"
          type="button"
          :disabled="archived || openCharges().length === 0"
          @click="openPayment()"
        >
          Dodaj wpłatę
        </button>
        <button
          class="button primary"
          type="button"
          :disabled="archived"
          @click="openCharge"
        >
          Dodaj należność
        </button>
      </div>
    </div>

    <div
      v-if="notice"
      class="notice"
    >
      {{ notice }}
      <button
        class="notice-close"
        type="button"
        aria-label="Zamknij komunikat"
        @click="notice = ''"
      >
        ×
      </button>
    </div>
    <div
      v-if="error && !drawer"
      class="notice error"
    >
      {{ error }}
    </div>

    <div
      v-if="loading"
      class="empty-inline"
    >
      Ładowanie finansów…
    </div>

    <template v-else>
      <div class="finance-summary-grid">
        <div>
          <span>Do zapłaty</span>
          <strong>{{ money(summary.total_charged) }}</strong>
        </div>
        <div>
          <span>Wpłacono</span>
          <strong>{{ money(summary.total_paid) }}</strong>
        </div>
        <div>
          <span>Pozostało</span>
          <strong>{{ money(summary.balance) }}</strong>
        </div>
      </div>

      <div
        v-if="charges.length === 0"
        class="empty-inline"
      >
        <strong>Brak należności.</strong>
        <span>Dodaj pierwszą należność albo wpisz koszt podczas tworzenia kursu.</span>
      </div>

      <div
        v-else
        class="finance-table-wrap"
      >
        <table class="finance-table">
          <thead>
            <tr>
              <th>Tytuł</th>
              <th>Kwota</th>
              <th>Pozostało</th>
              <th>Wpłaty</th>
              <th>Data dodania</th>
              <th>Status</th>
              <th>Akcje</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="charge in charges"
              :key="charge.id"
              :class="{ finance-cancelled: charge.status === 'cancelled' }"
            >
              <td>
                <strong>{{ charge.title }}</strong>
                <small class="table-subline">{{ courseLabel(charge.course_enrollment_id) }}</small>
              </td>
              <td>{{ money(charge.original_amount) }}</td>
              <td><strong>{{ money(charge.remaining_amount) }}</strong></td>
              <td>{{ paymentsForCharge(charge.id) }} · {{ money(charge.paid_amount) }}</td>
              <td>{{ formatDate(charge.created_at) }}</td>
              <td><span class="status-pill" :class="{ muted: charge.status === 'cancelled' }">{{ statusLabel(charge.status) }}</span></td>
              <td>
                <div class="row-actions">
                  <button
                    v-if="charge.status === 'open' || charge.status === 'partially_paid'"
                    class="text-button strong"
                    type="button"
                    :disabled="archived"
                    @click="openPayment(charge)"
                  >
                    Dodaj wpłatę
                  </button>
                  <button
                    v-if="charge.status === 'open'"
                    class="text-button danger"
                    type="button"
                    :disabled="archived"
                    @click="cancelCharge(charge)"
                  >
                    Anuluj
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <div
      v-if="drawer"
      class="drawer-backdrop finance-drawer-backdrop"
      @click.self="closeDrawer"
    >
      <section
        class="drawer compact"
        role="dialog"
        aria-modal="true"
      >
        <header class="drawer-header">
          <div>
            <span class="section-kicker">Finanse kursanta</span>
            <h2>{{ drawer === 'charge' ? 'Dodaj należność' : drawer === 'payment' ? 'Dodaj wpłatę' : 'Historia wpłat' }}</h2>
          </div>
          <button
            class="icon-button"
            type="button"
            aria-label="Zamknij"
            @click="closeDrawer"
          >
            ×
          </button>
        </header>

        <div
          v-if="error"
          class="notice error"
        >
          {{ error }}
        </div>

        <form
          v-if="drawer === 'charge'"
          class="form-grid"
          @submit.prevent="saveCharge"
        >
          <label class="full">Tytuł *
            <input
              v-model="chargeTitle"
              required
              maxlength="255"
              placeholder="np. Kurs kat. B"
            >
          </label>
          <label>Kwota *
            <div class="money-input">
              <input
                v-model="chargeAmount"
                required
                inputmode="decimal"
                placeholder="3500,00"
              >
              <span>zł</span>
            </div>
          </label>
          <label>Termin płatności
            <input
              v-model="chargeDueAt"
              type="date"
            >
          </label>
          <label class="full">Powiązany kurs
            <select v-model="chargeCourseId">
              <option value="">Bez przypisanego kursu</option>
              <option
                v-for="course in courses"
                :key="course.id"
                :value="course.id"
              >
                Kat. {{ course.driving_category_code }} · {{ course.training_type === 'basic' ? 'podstawowy' : 'uzupełniający' }}{{ course.cancelled_at ? ' · anulowany' : '' }}
              </option>
            </select>
          </label>
          <div class="form-actions full">
            <button
              class="button ghost"
              type="button"
              @click="closeDrawer"
            >
              Anuluj
            </button>
            <button
              class="button primary"
              type="submit"
              :disabled="saving"
            >
              {{ saving ? 'Zapisywanie…' : 'Dodaj należność' }}
            </button>
          </div>
        </form>

        <form
          v-else-if="drawer === 'payment'"
          class="form-grid"
          @submit.prevent="savePayment"
        >
          <label class="full">Należność *
            <select
              v-model="paymentChargeId"
              required
            >
              <option value="">Wybierz należność</option>
              <option
                v-for="charge in openCharges()"
                :key="charge.id"
                :value="charge.id"
              >
                {{ charge.title }} · pozostało {{ money(charge.remaining_amount) }}
              </option>
            </select>
          </label>
          <label>Kwota wpłaty *
            <div class="money-input">
              <input
                v-model="paymentAmount"
                required
                inputmode="decimal"
                placeholder="500,00"
              >
              <span>zł</span>
            </div>
          </label>
          <label>Data wpłaty *
            <input
              v-model="paymentPaidAt"
              type="datetime-local"
              required
            >
          </label>
          <label class="full">Metoda płatności
            <select v-model="paymentMethod">
              <option value="">Nie podano</option>
              <option value="cash">Gotówka</option>
              <option value="bank_transfer">Przelew</option>
              <option value="card">Karta</option>
              <option value="other">Inne</option>
            </select>
          </label>
          <label class="full">Notatka
            <input
              v-model="paymentNote"
              maxlength="2000"
              placeholder="Opcjonalnie"
            >
          </label>
          <div class="form-actions full">
            <button
              class="button ghost"
              type="button"
              @click="closeDrawer"
            >
              Anuluj
            </button>
            <button
              class="button primary"
              type="submit"
              :disabled="saving"
            >
              {{ saving ? 'Zapisywanie…' : 'Zapisz wpłatę' }}
            </button>
          </div>
        </form>

        <div
          v-else
          class="payment-history"
        >
          <div
            v-if="payments.length === 0"
            class="empty-inline"
          >
            Brak zarejestrowanych wpłat.
          </div>
          <article
            v-for="payment in payments"
            :key="payment.id"
            class="payment-history-row"
            :class="{ reversed: payment.reversed_at }"
          >
            <div>
              <strong>{{ money(payment.amount) }}</strong>
              <span>{{ chargeTitleFor(payment) }} · {{ formatDateTime(payment.paid_at) }}</span>
              <small>{{ paymentMethodLabel(payment.payment_method) }}{{ payment.note ? ` · ${payment.note}` : '' }}</small>
              <small v-if="payment.reversed_at">Skorygowano: {{ payment.reversal_reason ?? 'bez opisu' }}</small>
            </div>
            <button
              v-if="!payment.reversed_at"
              class="text-button danger"
              type="button"
              :disabled="saving || archived"
              @click="reversePayment(payment)"
            >
              Skoryguj
            </button>
            <span
              v-else
              class="status-pill muted"
            >Skorygowana</span>
          </article>
        </div>
      </section>
    </div>
  </section>
</template>
