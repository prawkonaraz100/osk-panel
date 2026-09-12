<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ApiError, api } from '../ResourcesCore/api'

type CalendarView = 'month' | 'week' | 'day'

type ActivityEvent = {
  id: string
  event_type: string
  timestamp: string
  actor_display_name: string | null
  related_entity_type: string | null
  related_entity_id: string | null
  description: string
  safe_details: Record<string, unknown> | null
}

type DashboardProjection = {
  licenses: { active_count: number; available_count: number }
  internal_exams: { available_count: number }
  activity: ActivityEvent[]
  calendar: Record<string, unknown>
}

type CalendarItem = {
  id: string
  source_kind?: string
  source_id?: string
  event_type: string
  name: string | null
  starts_at: string
  ends_at: string
  status: string
  all_day?: boolean
}

const PRODUCT_TIME_ZONE = 'Europe/Warsaw'
const WEEKDAY_LABELS = ['Pon', 'Wt', 'Śr', 'Czw', 'Pt', 'Sob', 'Niedz']

const loading = ref(false)
const calendarLoading = ref(false)
const error = ref('')
const dashboard = ref<DashboardProjection | null>(null)
const calendarItems = ref<CalendarItem[]>([])
const calendarView = ref<CalendarView>('month')
const calendarCursor = ref(startOfDay(new Date()))

const period = computed(() => periodRange(calendarCursor.value, calendarView.value))
const periodLabel = computed(() => {
  if (calendarView.value === 'month') {
    return new Intl.DateTimeFormat('pl-PL', {
      month: 'long',
      year: 'numeric',
      timeZone: PRODUCT_TIME_ZONE,
    }).format(calendarCursor.value)
  }

  if (calendarView.value === 'day') {
    return new Intl.DateTimeFormat('pl-PL', {
      weekday: 'long',
      day: 'numeric',
      month: 'long',
      year: 'numeric',
      timeZone: PRODUCT_TIME_ZONE,
    }).format(calendarCursor.value)
  }

  return `${formatShortDate(period.value.start)} – ${formatShortDate(addDays(period.value.end, -1))}`
})

const monthDays = computed(() => {
  const start = monthGridStart(calendarCursor.value)
  return Array.from({ length: 42 }, (_, index) => {
    const date = addDays(start, index)
    return {
      key: dateKey(date),
      date,
      currentMonth: date.getMonth() === calendarCursor.value.getMonth(),
      today: dateKey(date) === dateKey(new Date()),
      items: calendarItems.value.filter((item) => eventDateKey(item) === dateKey(date)),
    }
  })
})

const weekDays = computed(() => {
  const start = startOfWeek(calendarCursor.value)
  return Array.from({ length: 7 }, (_, index) => {
    const date = addDays(start, index)
    return {
      key: dateKey(date),
      date,
      today: dateKey(date) === dateKey(new Date()),
      items: calendarItems.value.filter((item) => eventDateKey(item) === dateKey(date)),
    }
  })
})

const dayItems = computed(() =>
  calendarItems.value.filter((item) => eventDateKey(item) === dateKey(calendarCursor.value)),
)

onMounted(load)

async function load(): Promise<void> {
  loading.value = true
  error.value = ''

  try {
    dashboard.value = (await api<DashboardProjection>('/api/v1/dashboard')).data
    await loadCalendar()
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    loading.value = false
  }
}

async function loadCalendar(): Promise<void> {
  calendarLoading.value = true

  try {
    const params = new URLSearchParams({
      from: period.value.start.toISOString(),
      to: period.value.end.toISOString(),
    })
    calendarItems.value = (await api<CalendarItem[]>(`/api/v1/calendar/events?${params.toString()}`)).data
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    calendarLoading.value = false
  }
}

function switchCalendarView(view: CalendarView): void {
  calendarView.value = view
  void loadCalendar()
}

function navigateCalendar(direction: -1 | 1): void {
  if (calendarView.value === 'month') {
    calendarCursor.value = new Date(
      calendarCursor.value.getFullYear(),
      calendarCursor.value.getMonth() + direction,
      1,
    )
  } else if (calendarView.value === 'week') {
    calendarCursor.value = addDays(calendarCursor.value, direction * 7)
  } else {
    calendarCursor.value = addDays(calendarCursor.value, direction)
  }
  void loadCalendar()
}

function goToday(): void {
  calendarCursor.value = startOfDay(new Date())
  void loadCalendar()
}

function activityTime(value: string): string {
  return new Intl.DateTimeFormat('pl-PL', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: PRODUCT_TIME_ZONE,
  }).format(new Date(value))
}

function eventLabel(item: CalendarItem): string {
  return item.name?.trim() || (item.event_type === 'driving_lesson' ? 'Jazda' : 'Wydarzenie')
}

function eventTime(item: CalendarItem): string {
  if (item.all_day) return 'cały dzień'
  return new Intl.DateTimeFormat('pl-PL', {
    hour: '2-digit',
    minute: '2-digit',
    timeZone: PRODUCT_TIME_ZONE,
  }).format(new Date(item.starts_at))
}

function handleError(caught: unknown): void {
  error.value = caught instanceof ApiError
    ? caught.message
    : caught instanceof Error
      ? caught.message
      : 'Nie udało się pobrać danych panelu.'
}

function startOfDay(value: Date): Date {
  return new Date(value.getFullYear(), value.getMonth(), value.getDate())
}

function startOfWeek(value: Date): Date {
  const date = startOfDay(value)
  const day = date.getDay() || 7
  return addDays(date, 1 - day)
}

function monthGridStart(value: Date): Date {
  return startOfWeek(new Date(value.getFullYear(), value.getMonth(), 1))
}

function addDays(value: Date, days: number): Date {
  const result = new Date(value)
  result.setDate(result.getDate() + days)
  return result
}

function periodRange(value: Date, view: CalendarView): { start: Date; end: Date } {
  if (view === 'day') {
    const start = startOfDay(value)
    return { start, end: addDays(start, 1) }
  }

  if (view === 'week') {
    const start = startOfWeek(value)
    return { start, end: addDays(start, 7) }
  }

  const start = new Date(value.getFullYear(), value.getMonth(), 1)
  return { start, end: new Date(value.getFullYear(), value.getMonth() + 1, 1) }
}

function dateKey(value: Date): string {
  return [
    value.getFullYear(),
    String(value.getMonth() + 1).padStart(2, '0'),
    String(value.getDate()).padStart(2, '0'),
  ].join('-')
}

function eventDateKey(item: CalendarItem): string {
  const date = new Date(item.starts_at)
  const parts = new Intl.DateTimeFormat('en-CA', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    timeZone: PRODUCT_TIME_ZONE,
  }).formatToParts(date)
  const map = new Map(parts.map((part) => [part.type, part.value]))
  return `${map.get('year')}-${map.get('month')}-${map.get('day')}`
}

function formatShortDate(value: Date): string {
  return new Intl.DateTimeFormat('pl-PL', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    timeZone: PRODUCT_TIME_ZONE,
  }).format(value)
}
</script>

<template>
  <div class="app-shell">
    <aside class="sidebar">
      <a class="brand" href="/">OSK <strong>Panel</strong></a>
      <nav class="main-nav" aria-label="Główna nawigacja">
        <a class="active" href="/">Panel główny</a>
        <a href="/kursanci">Kursanci</a>
        <a href="/kalendarz">Kalendarz</a>
        <a href="/licencje/panel">Licencje</a>
        <a href="/egzamin-wewnetrzny/panel">Egzaminy</a>
        <a href="/historia-zakupow">Historia zakupów</a>
        <a href="/lokalizacje">Lokalizacje</a>
        <a href="/pracownicy">Pracownicy</a>
        <a href="/pojazdy">Pojazdy</a>
      </nav>
      <div class="sidebar-foot">Stage 5 · Core v1</div>
    </aside>

    <main class="workspace">
      <header class="workspace-header">
        <div>
          <div class="eyebrow">PrawkoNaRaz · OSK</div>
          <h1>Panel główny</h1>
        </div>
      </header>

      <div v-if="error" class="notice error">
        <span>{{ error }}</span>
        <button type="button" aria-label="Zamknij" @click="error = ''">×</button>
      </div>

      <div v-if="loading || !dashboard" class="loading-card">
        Ładowanie panelu…
      </div>

      <template v-else>
        <section class="dashboard-summary-grid">
          <article class="dashboard-card">
            <div class="dashboard-card-head">
              <div>
                <span class="section-kicker">Licencje</span>
                <h2>Dostępy kursantów</h2>
              </div>
              <a class="button ghost" href="/licencje/panel">Więcej</a>
            </div>
            <div class="dashboard-metrics">
              <div>
                <span>Aktywne licencje</span>
                <strong>{{ dashboard.licenses.active_count }}</strong>
              </div>
              <div>
                <span>Dostępne licencje</span>
                <strong>{{ dashboard.licenses.available_count }}</strong>
              </div>
            </div>
            <div class="dashboard-actions">
              <a class="button primary" href="/licencje/panel">Przydziel licencję</a>
              <button class="button ghost" type="button" disabled title="Zakup online wymaga domkniętego checkoutu.">
                Kup licencje
              </button>
            </div>
          </article>

          <article class="dashboard-card">
            <div class="dashboard-card-head">
              <div>
                <span class="section-kicker">Egzaminy wewnętrzne</span>
                <h2>Dostępne egzaminy</h2>
              </div>
              <a class="button ghost" href="/egzamin-wewnetrzny/panel">Więcej</a>
            </div>
            <div class="dashboard-big-number">{{ dashboard.internal_exams.available_count }}</div>
            <div class="dashboard-actions">
              <a class="button primary" href="/egzamin-wewnetrzny/panel">Przeprowadź egzamin</a>
              <button class="button ghost" type="button" disabled title="Zakup online wymaga domkniętego checkoutu.">
                Kup egzamin
              </button>
            </div>
          </article>
        </section>

        <section class="dashboard-lower-grid">
          <article class="dashboard-card dashboard-activity">
            <div class="dashboard-card-head">
              <div>
                <span class="section-kicker">Powiadomienia</span>
                <h2>Aktywność OSK</h2>
              </div>
            </div>
            <div v-if="dashboard.activity.length" class="activity-list">
              <article v-for="item in dashboard.activity" :key="item.id" class="activity-row">
                <div class="activity-dot" />
                <div>
                  <strong>{{ item.description }}</strong>
                  <span>{{ activityTime(item.timestamp) }}</span>
                  <small v-if="item.actor_display_name">przez {{ item.actor_display_name }}</small>
                </div>
              </article>
            </div>
            <div v-else class="dashboard-empty">Brak ostatnich zdarzeń.</div>
          </article>

          <article class="dashboard-card dashboard-calendar">
            <div class="dashboard-card-head dashboard-calendar-head">
              <div>
                <span class="section-kicker">Kalendarz</span>
                <h2>{{ periodLabel }}</h2>
              </div>
              <div class="dashboard-calendar-links">
                <a class="button ghost" href="/kalendarz">Pełny kalendarz</a>
                <a class="button primary" href="/kalendarz?action=create">Dodaj wydarzenie</a>
              </div>
            </div>

            <div class="dashboard-calendar-toolbar">
              <div class="dashboard-calendar-nav">
                <button class="button ghost" type="button" aria-label="Poprzedni okres" @click="navigateCalendar(-1)">‹</button>
                <button class="button ghost" type="button" @click="goToday">Dzisiaj</button>
                <button class="button ghost" type="button" aria-label="Następny okres" @click="navigateCalendar(1)">›</button>
              </div>
              <div class="dashboard-view-switch">
                <button
                  v-for="mode in (['month', 'week', 'day'] as CalendarView[])"
                  :key="mode"
                  class="dashboard-view-button"
                  :class="{ active: calendarView === mode }"
                  type="button"
                  @click="switchCalendarView(mode)"
                >
                  {{ mode === 'month' ? 'Miesiąc' : mode === 'week' ? 'Tydzień' : 'Dzień' }}
                </button>
              </div>
            </div>

            <div v-if="calendarLoading" class="dashboard-empty">Ładowanie kalendarza…</div>
            <template v-else>
              <div v-if="calendarView === 'month'" class="dashboard-month">
                <span v-for="label in WEEKDAY_LABELS" :key="label" class="dashboard-weekday">{{ label }}</span>
                <article
                  v-for="day in monthDays"
                  :key="day.key"
                  class="dashboard-day"
                  :class="{ muted: !day.currentMonth, today: day.today }"
                >
                  <time>{{ day.date.getDate() }}</time>
                  <div class="dashboard-day-events">
                    <span v-for="item in day.items.slice(0, 3)" :key="item.id">
                      {{ eventTime(item) }} · {{ eventLabel(item) }}
                    </span>
                    <small v-if="day.items.length > 3">+{{ day.items.length - 3 }}</small>
                  </div>
                </article>
              </div>

              <div v-else-if="calendarView === 'week'" class="dashboard-week">
                <article v-for="day in weekDays" :key="day.key" :class="{ today: day.today }">
                  <strong>{{ formatShortDate(day.date) }}</strong>
                  <span v-for="item in day.items" :key="item.id">
                    {{ eventTime(item) }} · {{ eventLabel(item) }}
                  </span>
                  <small v-if="!day.items.length">Brak wpisów</small>
                </article>
              </div>

              <div v-else class="dashboard-day-list">
                <article v-for="item in dayItems" :key="item.id">
                  <time>{{ eventTime(item) }}</time>
                  <strong>{{ eventLabel(item) }}</strong>
                </article>
                <div v-if="!dayItems.length" class="dashboard-empty">Brak wpisów w tym dniu.</div>
              </div>
            </template>
          </article>
        </section>
      </template>
    </main>
  </div>
</template>

<style scoped>
.dashboard-summary-grid,
.dashboard-lower-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 18px;
}

.dashboard-lower-grid {
  margin-top: 18px;
  grid-template-columns: minmax(320px, .75fr) minmax(520px, 1.25fr);
  align-items: start;
}

.dashboard-card {
  min-width: 0;
  padding: 22px;
  border: 1px solid var(--line);
  border-radius: 16px;
  background: var(--surface);
}

.dashboard-card h2 {
  margin: 4px 0 0;
  font-size: 21px;
  letter-spacing: -.03em;
}

.dashboard-card-head,
.dashboard-actions,
.dashboard-calendar-toolbar,
.dashboard-calendar-nav,
.dashboard-calendar-links,
.dashboard-view-switch {
  display: flex;
  align-items: center;
  gap: 9px;
}

.dashboard-card-head,
.dashboard-calendar-toolbar {
  justify-content: space-between;
}

.dashboard-metrics {
  margin: 24px 0;
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.dashboard-metrics > div {
  padding: 15px;
  border-radius: 12px;
  background: var(--surface-soft);
  display: grid;
  gap: 6px;
}

.dashboard-metrics span,
.activity-row span,
.activity-row small {
  color: var(--muted);
  font-size: 11px;
}

.dashboard-metrics strong {
  font-size: 28px;
  letter-spacing: -.04em;
}

.dashboard-big-number {
  margin: 22px 0;
  font-size: 52px;
  font-weight: 760;
  letter-spacing: -.06em;
}

.activity-list {
  margin-top: 12px;
  display: grid;
}

.activity-row {
  padding: 13px 0;
  border-top: 1px solid var(--line);
  display: grid;
  grid-template-columns: 9px minmax(0, 1fr);
  gap: 12px;
}

.activity-row:first-child {
  border-top: 0;
}

.activity-row > div:last-child {
  display: grid;
  gap: 4px;
}

.activity-dot {
  width: 8px;
  height: 8px;
  margin-top: 5px;
  border-radius: 50%;
  background: var(--accent);
}

.dashboard-calendar-head {
  align-items: flex-start;
}

.dashboard-calendar-toolbar {
  margin: 18px 0 12px;
}

.dashboard-view-switch {
  padding: 3px;
  border-radius: 10px;
  background: var(--surface-soft);
}

.dashboard-view-button {
  padding: 7px 10px;
  border: 0;
  border-radius: 8px;
  background: transparent;
  color: var(--muted);
  cursor: pointer;
  font-size: 12px;
  font-weight: 650;
}

.dashboard-view-button.active {
  background: #fff;
  color: var(--text);
  box-shadow: 0 1px 5px rgba(23, 23, 23, .08);
}

.dashboard-month {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  border-top: 1px solid var(--line);
  border-left: 1px solid var(--line);
}

.dashboard-weekday {
  padding: 8px;
  border-right: 1px solid var(--line);
  border-bottom: 1px solid var(--line);
  color: var(--muted);
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
}

.dashboard-day {
  min-height: 82px;
  padding: 7px;
  border-right: 1px solid var(--line);
  border-bottom: 1px solid var(--line);
}

.dashboard-day.muted {
  background: #fbfbfa;
  color: #aaa8a2;
}

.dashboard-day.today time {
  width: 24px;
  height: 24px;
  border-radius: 50%;
  background: var(--accent);
  display: grid;
  place-items: center;
}

.dashboard-day time {
  font-size: 11px;
  font-weight: 700;
}

.dashboard-day-events {
  margin-top: 5px;
  display: grid;
  gap: 3px;
}

.dashboard-day-events span,
.dashboard-day-events small {
  overflow: hidden;
  color: var(--muted);
  font-size: 9px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.dashboard-week {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  gap: 7px;
}

.dashboard-week article {
  min-height: 120px;
  padding: 10px;
  border: 1px solid var(--line);
  border-radius: 10px;
  display: grid;
  align-content: start;
  gap: 6px;
}

.dashboard-week article.today {
  border-color: var(--accent-strong);
}

.dashboard-week span,
.dashboard-week small {
  color: var(--muted);
  font-size: 10px;
}

.dashboard-day-list {
  display: grid;
}

.dashboard-day-list article {
  padding: 13px 0;
  border-top: 1px solid var(--line);
  display: grid;
  grid-template-columns: 90px 1fr;
  gap: 12px;
}

.dashboard-day-list time {
  color: var(--muted);
  font-size: 12px;
}

.dashboard-empty {
  padding: 20px 0;
  color: var(--muted);
  font-size: 13px;
}

@media (max-width: 1100px) {
  .dashboard-summary-grid,
  .dashboard-lower-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 760px) {
  .dashboard-card-head,
  .dashboard-calendar-toolbar,
  .dashboard-calendar-links {
    align-items: stretch;
    flex-direction: column;
  }

  .dashboard-month,
  .dashboard-week {
    min-width: 700px;
  }

  .dashboard-calendar {
    overflow-x: auto;
  }
}
</style>
