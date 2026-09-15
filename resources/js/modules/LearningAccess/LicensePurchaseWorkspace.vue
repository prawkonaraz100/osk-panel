<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ApiError, api } from '../ResourcesCore/api'

type Money = {
  amount_minor: number
  currency: string
}

type LicenseProduct = {
  id: string
  code: string
  duration_days: number
  active: boolean
  display_name: string
  price: Money | null
  list_price: Money | null
  pricing_revision: string | null
  sample_data: boolean
  languages: string[]
  available_count: number
  active_count: number
}

type CreatedOrder = {
  id: string
  display_number: string
  status: string
  total: Money
  created_at: string
}

type SelectedLine = {
  product: LicenseProduct
  quantity: number
}

const loading = ref(true)
const pending = ref(false)
const error = ref('')
const notice = ref('')
const products = ref<LicenseProduct[]>([])
const quantities = reactive<Record<string, number>>({})
const paymentMethod = ref<'bank_transfer' | 'online_payment'>('bank_transfer')
const createdOrder = ref<CreatedOrder | null>(null)

onMounted(loadProducts)

async function loadProducts(): Promise<void> {
  loading.value = true
  error.value = ''

  try {
    const result = await api<LicenseProduct[]>('/api/v1/license-products')
    products.value = result.data

    for (const product of result.data) {
      if (!(product.id in quantities)) {
        quantities[product.id] = 0
      }
    }
  } catch (caught: unknown) {
    error.value = messageFor(caught, 'Nie udało się pobrać produktów licencyjnych.')
  } finally {
    loading.value = false
  }
}

function quantity(productId: string): number {
  const value = Number(quantities[productId] ?? 0)

  if (!Number.isFinite(value) || value <= 0) {
    return 0
  }

  return Math.floor(value)
}

function setQuantity(productId: string, value: number): void {
  const normalized = Number.isFinite(value) ? Math.floor(value) : 0
  quantities[productId] = Math.max(0, normalized)
  createdOrder.value = null
  notice.value = ''
}

function normalizeQuantity(productId: string): void {
  setQuantity(productId, Number(quantities[productId] ?? 0))
}

const selectedLines = computed<SelectedLine[]>(() => products.value
  .filter(product => product.price !== null && quantity(product.id) > 0)
  .map(product => ({
    product,
    quantity: quantity(product.id),
  })))

const packageCount = computed(() => selectedLines.value
  .reduce((sum, line) => sum + line.quantity, 0))

const selectedCurrencies = computed(() => Array.from(new Set(
  selectedLines.value
    .map(line => line.product.price?.currency)
    .filter((currency): currency is string => Boolean(currency)),
)))

const mixedCurrencies = computed(() => selectedCurrencies.value.length > 1)
const previewCurrency = computed(() => selectedCurrencies.value[0] ?? 'PLN')

const previewListTotalMinor = computed(() => selectedLines.value.reduce((sum, line) => {
  const amount = line.product.list_price?.amount_minor ?? line.product.price?.amount_minor ?? 0
  return sum + amount * line.quantity
}, 0))

const previewPayableMinor = computed(() => selectedLines.value.reduce((sum, line) => {
  const amount = line.product.price?.amount_minor ?? 0
  return sum + amount * line.quantity
}, 0))

const previewDiscountMinor = computed(() => Math.max(
  0,
  previewListTotalMinor.value - previewPayableMinor.value,
))

const previewDiscountPercent = computed(() => {
  if (previewListTotalMinor.value <= 0) {
    return 0
  }

  return (previewDiscountMinor.value / previewListTotalMinor.value) * 100
})

const usesSamplePricing = computed(() => products.value.some(product => product.sample_data))

const canSubmit = computed(() => (
  !loading.value
  && !pending.value
  && selectedLines.value.length > 0
  && !mixedCurrencies.value
))

async function submitOrder(): Promise<void> {
  error.value = ''
  notice.value = ''
  createdOrder.value = null

  const items = selectedLines.value.map(line => ({
    product_id: line.product.id,
    quantity: line.quantity,
  }))

  if (items.length === 0) {
    error.value = 'Wybierz co najmniej jedną licencję.'
    return
  }

  if (mixedCurrencies.value) {
    error.value = 'Jedno zamówienie nie może łączyć produktów w różnych walutach.'
    return
  }

  pending.value = true

  try {
    const result = await api<CreatedOrder>('/api/v1/license-orders', {
      method: 'POST',
      idempotent: true,
      body: JSON.stringify({
        items,
        payment_method: paymentMethod.value,
      }),
    })

    createdOrder.value = result.data
    notice.value = `Zamówienie nr ${result.data.display_number} zostało utworzone. Ostateczna kwota zaakceptowana przez serwer: ${money(result.data.total.amount_minor, result.data.total.currency)}.`

    for (const line of selectedLines.value) {
      quantities[line.product.id] = 0
    }
  } catch (caught: unknown) {
    error.value = messageFor(caught, 'Nie udało się utworzyć zamówienia licencji.')
  } finally {
    pending.value = false
  }
}

function durationLabel(days: number): string {
  if (days === 30) return '1 miesiąc'
  if (days === 90) return '3 miesiące'
  if (days === 180) return '6 miesięcy'
  return `${days} dni`
}

function money(amountMinor: number, currency: string): string {
  return new Intl.NumberFormat('pl-PL', {
    style: 'currency',
    currency,
  }).format(amountMinor / 100)
}

function messageFor(caught: unknown, fallback: string): string {
  if (caught instanceof ApiError) {
    return Object.values(caught.fields).flat().join(' ') || caught.message
  }

  if (caught instanceof Error) {
    return caught.message
  }

  return fallback
}
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
        <a href="/kursanci">Kursanci</a>
        <a href="/lokalizacje">Lokalizacje</a>
        <a href="/pracownicy">Pracownicy</a>
        <a href="/pojazdy">Pojazdy</a>
        <a href="/kalendarz">Kalendarz</a>
        <a
          href="/licencje/panel"
          class="active"
        >
          Licencje
        </a>
        <a href="/ustawienia">Ustawienia</a>
      </nav>

      <div class="sidebar-foot">
        Stage 5 · Core v1
      </div>
    </aside>

    <main class="workspace license-purchase-workspace">
      <header class="workspace-header">
        <div>
          <div class="eyebrow">
            PrawkoNaRaz · OSK
          </div>
          <h1>Wykup licencje</h1>
          <p class="purchase-copy">
            Wybierz liczbę licencji. Ceny są pobierane z serwera, a ostateczna wartość zamówienia jest ustalana ponownie przez backend przy zapisie.
          </p>
        </div>

        <div class="header-actions">
          <a
            class="button ghost"
            href="/licencje/panel"
          >
            Panel licencji
          </a>
          <a
            class="button ghost"
            href="/historia-zakupow"
          >
            Historia zakupów
          </a>
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
        v-if="usesSamplePricing"
        class="notice warning purchase-warning"
        role="status"
      >
        To środowisko korzysta z przykładowego cennika developerskiego. Nie jest to cennik produkcyjny.
      </div>

      <div
        v-if="loading"
        class="loading-card"
      >
        Ładowanie produktów i aktualnych cen…
      </div>

      <div
        v-else
        class="license-purchase-layout"
      >
        <section>
          <div class="purchase-section-heading">
            <div>
              <span class="section-kicker">Produkty</span>
              <h2>Wybierz licencje</h2>
            </div>
            <p>
              Możesz dodać kilka wariantów do jednego zamówienia.
            </p>
          </div>

          <div class="purchase-product-grid">
            <article
              v-for="product in products"
              :key="product.id"
              class="purchase-product-card"
              :class="{ unavailable: product.price === null }"
            >
              <div class="purchase-product-head">
                <div>
                  <span class="section-kicker">Licencja</span>
                  <h3>{{ durationLabel(product.duration_days) }}</h3>
                  <p>{{ product.display_name }}</p>
                </div>

                <div
                  v-if="product.price"
                  class="purchase-price"
                >
                  <small
                    v-if="product.list_price && product.list_price.amount_minor !== product.price.amount_minor"
                    class="purchase-list-price"
                  >
                    {{ money(product.list_price.amount_minor, product.list_price.currency) }}
                  </small>
                  <strong>
                    {{ money(product.price.amount_minor, product.price.currency) }}
                  </strong>
                  <span>za sztukę</span>
                </div>

                <div
                  v-else
                  class="purchase-price unavailable-price"
                >
                  <strong>Brak ceny</strong>
                  <span>zakup niedostępny</span>
                </div>
              </div>

              <dl class="purchase-product-meta">
                <div>
                  <dt>Dostępne w puli</dt>
                  <dd>{{ product.available_count }}</dd>
                </div>
                <div>
                  <dt>Aktywne</dt>
                  <dd>{{ product.active_count }}</dd>
                </div>
                <div>
                  <dt>Języki</dt>
                  <dd>{{ product.languages.length ? product.languages.join(', ').toUpperCase() : '—' }}</dd>
                </div>
              </dl>

              <div class="quantity-row">
                <span>Ilość</span>

                <div class="quantity-stepper">
                  <button
                    type="button"
                    :disabled="product.price === null || quantity(product.id) === 0"
                    :aria-label="`Zmniejsz liczbę licencji ${durationLabel(product.duration_days)}`"
                    @click="setQuantity(product.id, quantity(product.id) - 1)"
                  >
                    −
                  </button>

                  <input
                    v-model.number="quantities[product.id]"
                    type="number"
                    inputmode="numeric"
                    min="0"
                    step="1"
                    :disabled="product.price === null"
                    :aria-label="`Liczba licencji ${durationLabel(product.duration_days)}`"
                    @change="normalizeQuantity(product.id)"
                  >

                  <button
                    type="button"
                    :disabled="product.price === null"
                    :aria-label="`Zwiększ liczbę licencji ${durationLabel(product.duration_days)}`"
                    @click="setQuantity(product.id, quantity(product.id) + 1)"
                  >
                    +
                  </button>
                </div>
              </div>

              <p
                v-if="product.price === null"
                class="product-pricing-missing"
              >
                Serwer nie udostępnia obecnie ceny dla tego produktu. Frontend nie tworzy ceny zastępczej.
              </p>
            </article>

            <div
              v-if="products.length === 0"
              class="empty-inline"
            >
              <strong>Brak aktywnych produktów licencyjnych.</strong>
              <span>Zakup będzie dostępny po opublikowaniu katalogu produktów i cennika.</span>
            </div>
          </div>

          <section class="purchase-volume-card">
            <div>
              <span class="section-kicker">Większy wolumen</span>
              <strong>Potrzebujesz większej liczby licencji?</strong>
            </div>
            <p>
              Skontaktuj się z obsługą w sprawie oferty indywidualnej. Nie hardkodujemy publicznego limitu samoobsługowego bez osobnej reguły biznesowej.
            </p>
          </section>
        </section>

        <aside class="purchase-summary-card">
          <span class="section-kicker">Podsumowanie</span>
          <h2>Zamówienie</h2>

          <dl class="summary-list">
            <div>
              <dt>Liczba pakietów</dt>
              <dd>{{ packageCount }}</dd>
            </div>
            <div>
              <dt>Cena katalogowa</dt>
              <dd>
                {{ mixedCurrencies ? 'Różne waluty' : money(previewListTotalMinor, previewCurrency) }}
              </dd>
            </div>
            <div>
              <dt>Rabat</dt>
              <dd>
                {{ mixedCurrencies ? '—' : previewDiscountPercent.toFixed(2) + '%' }}
              </dd>
            </div>
            <div>
              <dt>Wartość rabatu</dt>
              <dd>
                {{ mixedCurrencies ? '—' : money(previewDiscountMinor, previewCurrency) }}
              </dd>
            </div>
            <div class="summary-total">
              <dt>Do zapłaty</dt>
              <dd>
                {{ mixedCurrencies ? '—' : money(previewPayableMinor, previewCurrency) }}
              </dd>
            </div>
          </dl>

          <p class="server-authority-note">
            To jest podgląd wyliczony wyłącznie z aktualnej projekcji cen zwróconej przez serwer. Przeglądarka nie wysyła kwot w żądaniu zamówienia.
          </p>

          <fieldset class="payment-fieldset">
            <legend>Metoda płatności</legend>

            <label>
              <input
                v-model="paymentMethod"
                type="radio"
                value="bank_transfer"
              >
              <span>
                <strong>Przelew bankowy</strong>
                <small>Zamówienie pozostaje oczekujące do wiarygodnego potwierdzenia płatności.</small>
              </span>
            </label>

            <label>
              <input
                v-model="paymentMethod"
                type="radio"
                value="online_payment"
              >
              <span>
                <strong>Płatność online</strong>
                <small>Provider-neutral intent; ten ekran nie udaje aktywnej integracji operatora płatności.</small>
              </span>
            </label>
          </fieldset>

          <p
            v-if="mixedCurrencies"
            class="summary-error"
          >
            Wybrane produkty mają różne waluty. Utwórz osobne zamówienia.
          </p>

          <button
            class="button primary purchase-submit"
            type="button"
            :disabled="!canSubmit"
            @click="submitOrder"
          >
            {{ pending ? 'Tworzenie zamówienia…' : 'Kup teraz' }}
          </button>

          <div
            v-if="createdOrder"
            class="created-order-card"
          >
            <strong>Zamówienie nr {{ createdOrder.display_number }}</strong>
            <span>Status: {{ createdOrder.status }}</span>
            <span>Kwota serwera: {{ money(createdOrder.total.amount_minor, createdOrder.total.currency) }}</span>
            <a href="/historia-zakupow">Przejdź do historii zakupów</a>
          </div>
        </aside>
      </div>
    </main>
  </div>
</template>

<style scoped>
.license-purchase-workspace {
  width: min(1540px, 100%);
}

.purchase-copy {
  max-width: 760px;
  margin: 8px 0 0;
  color: var(--muted);
  line-height: 1.6;
}

.purchase-warning {
  color: #68571f;
  border-color: #e5cf8c;
  background: #fffaf0;
}

.license-purchase-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(300px, 390px);
  gap: 24px;
  align-items: start;
}

.purchase-section-heading {
  margin-bottom: 14px;
  display: flex;
  align-items: end;
  justify-content: space-between;
  gap: 18px;
}

.purchase-section-heading h2,
.purchase-summary-card h2 {
  margin: 5px 0 0;
  font-size: 22px;
}

.purchase-section-heading p {
  margin: 0;
  color: var(--muted);
  font-size: 13px;
}

.purchase-product-grid {
  display: grid;
  gap: 14px;
}

.purchase-product-card,
.purchase-summary-card,
.purchase-volume-card {
  border: 1px solid var(--line);
  border-radius: 16px;
  background: #fff;
}

.purchase-product-card {
  padding: 20px;
}

.purchase-product-card.unavailable {
  opacity: 0.72;
}

.purchase-product-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 20px;
}

.purchase-product-head h3 {
  margin: 5px 0 4px;
  font-size: 21px;
}

.purchase-product-head p {
  margin: 0;
  color: var(--muted);
  font-size: 13px;
}

.purchase-price {
  display: grid;
  justify-items: end;
  gap: 2px;
  white-space: nowrap;
}

.purchase-price strong {
  font-size: 21px;
}

.purchase-price span,
.purchase-price small {
  color: var(--muted);
  font-size: 11px;
}

.purchase-list-price {
  text-decoration: line-through;
}

.unavailable-price strong {
  color: var(--muted);
  font-size: 15px;
}

.purchase-product-meta {
  margin: 20px 0;
  padding: 14px 0;
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 14px;
  border-top: 1px solid var(--line);
  border-bottom: 1px solid var(--line);
}

.purchase-product-meta div {
  display: grid;
  gap: 4px;
}

.purchase-product-meta dt {
  color: var(--muted);
  font-size: 11px;
}

.purchase-product-meta dd {
  margin: 0;
  font-size: 13px;
  font-weight: 700;
}

.quantity-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
}

.quantity-row > span {
  font-size: 13px;
  font-weight: 650;
}

.quantity-stepper {
  display: inline-grid;
  grid-template-columns: 38px 74px 38px;
  align-items: center;
}

.quantity-stepper button,
.quantity-stepper input {
  min-height: 38px;
  border: 1px solid var(--line-strong);
  background: #fff;
}

.quantity-stepper button {
  cursor: pointer;
  font-size: 18px;
}

.quantity-stepper button:first-child {
  border-radius: 9px 0 0 9px;
}

.quantity-stepper button:last-child {
  border-radius: 0 9px 9px 0;
}

.quantity-stepper button:disabled {
  cursor: not-allowed;
  opacity: 0.45;
}

.quantity-stepper input {
  width: 74px;
  margin: 0 -1px;
  padding: 7px;
  text-align: center;
  appearance: textfield;
}

.quantity-stepper input::-webkit-inner-spin-button,
.quantity-stepper input::-webkit-outer-spin-button {
  margin: 0;
  appearance: none;
}

.product-pricing-missing {
  margin: 12px 0 0;
  color: var(--muted);
  font-size: 12px;
}

.purchase-volume-card {
  margin-top: 18px;
  padding: 18px 20px;
  display: flex;
  justify-content: space-between;
  gap: 24px;
}

.purchase-volume-card > div {
  display: grid;
  gap: 5px;
}

.purchase-volume-card p {
  max-width: 620px;
  margin: 0;
  color: var(--muted);
  font-size: 12px;
  line-height: 1.55;
}

.purchase-summary-card {
  position: sticky;
  top: 20px;
  padding: 22px;
}

.summary-list {
  margin: 20px 0 0;
  display: grid;
  gap: 0;
}

.summary-list > div {
  padding: 11px 0;
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 16px;
  border-bottom: 1px solid var(--line);
}

.summary-list dt {
  color: var(--muted);
  font-size: 12px;
}

.summary-list dd {
  margin: 0;
  font-size: 13px;
  font-weight: 700;
  text-align: right;
}

.summary-list .summary-total {
  padding-top: 16px;
  border-bottom: 0;
}

.summary-total dt,
.summary-total dd {
  color: var(--text);
  font-size: 17px;
  font-weight: 800;
}

.server-authority-note {
  margin: 4px 0 18px;
  padding: 12px;
  border-radius: 10px;
  background: var(--surface-soft, #f7f6f2);
  color: var(--muted);
  font-size: 11px;
  line-height: 1.5;
}

.payment-fieldset {
  margin: 0;
  padding: 0;
  display: grid;
  gap: 10px;
  border: 0;
}

.payment-fieldset legend {
  margin-bottom: 8px;
  color: var(--text);
  font-size: 13px;
  font-weight: 700;
}

.payment-fieldset label {
  padding: 11px 12px;
  display: flex;
  align-items: flex-start;
  gap: 10px;
  border: 1px solid var(--line);
  border-radius: 10px;
  cursor: pointer;
}

.payment-fieldset input {
  margin-top: 2px;
}

.payment-fieldset span {
  display: grid;
  gap: 3px;
}

.payment-fieldset strong {
  font-size: 12px;
}

.payment-fieldset small {
  color: var(--muted);
  font-size: 10px;
  line-height: 1.45;
}

.summary-error {
  margin: 14px 0 0;
  color: var(--danger);
  font-size: 12px;
}

.purchase-submit {
  width: 100%;
  margin-top: 18px;
}

.created-order-card {
  margin-top: 16px;
  padding: 14px;
  display: grid;
  gap: 5px;
  border: 1px solid var(--line);
  border-radius: 10px;
  background: var(--success-soft);
  font-size: 12px;
}

.created-order-card span {
  color: var(--muted);
}

.created-order-card a {
  margin-top: 4px;
  font-weight: 700;
  text-decoration: underline;
}

@media (max-width: 1080px) {
  .license-purchase-layout {
    grid-template-columns: 1fr;
  }

  .purchase-summary-card {
    position: static;
  }
}

@media (max-width: 720px) {
  .purchase-product-head,
  .purchase-volume-card,
  .purchase-section-heading {
    align-items: stretch;
    flex-direction: column;
  }

  .purchase-price {
    justify-items: start;
  }

  .purchase-product-meta {
    grid-template-columns: 1fr;
  }

  .quantity-row {
    align-items: stretch;
    flex-direction: column;
  }
}
</style>
