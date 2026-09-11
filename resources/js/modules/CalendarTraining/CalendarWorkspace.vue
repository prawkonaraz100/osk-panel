<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ApiError, api } from '../ResourcesCore/api'

type CalendarView = 'month' | 'week' | 'day'
type CalendarEventType = 'general_event' | 'driving_lesson' | 'important_date'

type CalendarItem = {
  id: string
  source_kind: string
  source_id: string
  course_enrollment_id: string | null
  event_type: CalendarEventType
  name: string | null
  starts_at: string
  ends_at: string
  student_id: string | null
  instructor_id: string | null
  vehicle_id: string | null
  location_id: string | null
  custom_meeting_place: string | null
  status: string
  version: number
  all_day?: boolean
  source_date?: string
  important_date_kind?: string
}

type Student = {
  id: string
  first_name: string
  last_name: string
  archived_at: string | null
}

type StaffResource = {
  id: string
  first_name: string
  last_name: string
  email: string
  archived_at: string | null
}

type VehicleResource = {
  id: string
  registration_number: string
  make: string
  model: string
  archived_at: string | null
}

type LocationResource = {
  id: string
  type_code: string
  name: string
  street_and_number: string
  city_name: string
  archived_at: string | null
}

type Paginated<T> = {
  data: T[]
  meta: { page: number; per_page: number; total: number; last_page: number }
}

type EventForm = {
  event_type: 'general_event' | 'driving_lesson'
  name: string
  start_date: string
  start_time: string
  duration: string
  student_id: string
  instructor_id: string
  vehicle_id: string
  location_id: string
  custom_meeting_place: string
}

const PRODUCT_TIME_ZONE = 'Europe/Warsaw'
const WEEKDAY_LABELS = ['Pon', 'Wt', 'Śr', 'Czw', 'Pt', 'Sob', 'Niedz']

const loading = ref(false)
const saving = ref(false)
const error = ref('')
const notice = ref('')
const view = ref<CalendarView>('month')
const cursor = ref(startOfDay(new Date()))
const drawerOpen = ref(false)
const customMeetingPlaceMode = ref(false)

const events = ref<CalendarItem[]>([])
const students = ref<Student[]>([])
const staff = ref<StaffResource[]>([])
const vehicles = ref<VehicleResource[]>([])
const locations = ref<LocationResource[]>([])

const selectedTypes = ref<CalendarEventType[]>(['general_event', 'driving_lesson', 'important_date'])
const selectedStaff = ref<string[]>([])
const selectedVehicles = ref<string[]>([])
const selectedLocations = ref<string[]>([])
const referencesLoaded = ref(false)

const form = ref<EventForm>(emptyForm())

const query = new URLSearchParams(window.location.search)
const initialStaffId = query.get('w')
const initialVehicleId = query.get('v')
const initialLocationId = query.get('b')

const activeStaff = computed(() => staff.value.filter((item) => !item.archived_at))
const activeVehicles = computed(() => vehicles.value.filter((item) => !item.archived_at))
const activeLocations = computed(() => locations.value.filter((item) => !item.archived_at))
const activeStudents = computed(() => students.value.filter((item) => !item.archived_at))

const staffMap = computed(() => new Map(staff.value.map((item) => [item.id, `${item.first_name} ${item.last_name}`])))
const vehicleMap = computed(() => new Map(vehicles.value.map((item) => [item.id, `${item.make} ${item.model} · ${item.registration_number}`])))
const locationMap = computed(() => new Map(locations.value.map((item) => [item.id, item.name])))
const studentMap = computed(() => new Map(students.value.map((item) => [item.id, `${item.first_name} ${item.last_name}`])))

const period = computed(() => periodRange(cursor.value, view.value))
const periodLabel = computed(() => {
  if (view.value === 'month') {
    return new Intl.DateTimeFormat('pl-PL', { month: 'long', year: 'numeric', timeZone: PRODUCT_TIME_ZONE })
      .format(cursor.value)
  }

  if (view.value === 'day') {
    return new Intl.DateTimeFormat('pl-PL', {
      weekday: 'long',
      day: 'numeric',
      month: 'long',
      year: 'numeric',
      timeZone: PRODUCT_TIME_ZONE,
    }).format(cursor.value)
  }

  const end = addDays(period.value.end, -1)
  return `${formatShortDate(period.value.start)} – ${formatShortDate(end)}`
})

const filteredEvents = computed(() =>
  events.value.filter((item) =>
    selectedTypes.value.includes(item.event_type)
      && matchesSelection(item.instructor_id, selectedStaff.value, activeStaff.value.length)
      && matchesSelection(item.vehicle_id, selectedVehicles.value, activeVehicles.value.length)
      && matchesLocation(item),
  ),
)

const monthDays = computed(() => {
  const start = monthGridStart(cursor.value)
  return Array.from({ length: 42 }, (_, index) => {
    const date = addDays(start, index)
    return {
      key: dateKey(date),
      date,
      currentMonth: date.getMonth() === cursor.value.getMonth(),
      today: dateKey(date) === dateKey(new Date()),
      items: filteredEvents.value.filter((item) => eventDateKey(item) === dateKey(date)),
    }
  })
})

const weekDays = computed(() => {
  const start = startOfWeek(cursor.value)
  return Array.from({ length: 7 }, (_, index) => {
    const date = addDays(start, index)
    return {
      key: dateKey(date),
      date,
      today: dateKey(date) === dateKey(new Date()),
      items: filteredEvents.value.filter((item) => eventDateKey(item) === dateKey(date)),
    }
  })
})

const dayEvents = computed(() =>
  filteredEvents.value.filter((item) => eventDateKey(item) === dateKey(cursor.value)),
)

onMounted(async () => {
  await loadReferences()
  applyInitialSelections()

  if (query.get('action') === 'create') {
    openCreate()
  }

  await loadEvents()
})

async function loadReferences(): Promise<void> {
  const results = await Promise.allSettled([
    api<Paginated<Student>>('/api/v1/students?per_page=100'),
    api<Paginated<StaffResource>>('/api/v1/staff?per_page=100'),
    api<Paginated<VehicleResource>>('/api/v1/vehicles?per_page=100'),
    api<LocationResource[]>('/api/v1/locations'),
  ])

  if (results[0].status === 'fulfilled') students.value = results[0].value.data.data
  if (results[1].status === 'fulfilled') staff.value = results[1].value.data.data
  if (results[2].status === 'fulfilled') vehicles.value = results[2].value.data.data
  if (results[3].status === 'fulfilled') locations.value = results[3].value.data

  referencesLoaded.value = true
}

function applyInitialSelections(): void {
  selectedStaff.value = initialStaffId
    ? activeStaff.value.filter((item) => item.id === initialStaffId).map((item) => item.id)
    : activeStaff.value.map((item) => item.id)
  selectedVehicles.value = initialVehicleId
    ? activeVehicles.value.filter((item) => item.id === initialVehicleId).map((item) => item.id)
    : activeVehicles.value.map((item) => item.id)
  selectedLocations.value = initialLocationId
    ? activeLocations.value.filter((item) => item.id === initialLocationId).map((item) => item.id)
    : activeLocations.value.map((item) => item.id)
}

async function loadEvents(): Promise<void> {
  loading.value = true
  error.value = ''

  try {
    if (selectedTypes.value.length === 0) {
      events.value = []
      return
    }

    const params = new URLSearchParams()
    params.set('from', period.value.start.toISOString())
    params.set('to', period.value.end.toISOString())
    selectedTypes.value.forEach((type) => params.append('event_type[]', type))

    const result = await api<CalendarItem[]>(`/api/v1/calendar/events?${params.toString()}`)
    events.value = result.data
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    loading.value = false
  }
}

function switchView(next: CalendarView): void {
  view.value = next
  void loadEvents()
}

function navigate(direction: -1 | 1): void {
  if (view.value === 'month') {
    cursor.value = new Date(cursor.value.getFullYear(), cursor.value.getMonth() + direction, 1)
  } else if (view.value === 'week') {
    cursor.value = addDays(cursor.value, direction * 7)
  } else {
    cursor.value = addDays(cursor.value, direction)
  }
  void loadEvents()
}

function goToday(): void {
  cursor.value = startOfDay(new Date())
  void loadEvents()
}

function toggleType(type: CalendarEventType): void {
  selectedTypes.value = selectedTypes.value.includes(type)
    ? selectedTypes.value.filter((value) => value !== type)
    : [...selectedTypes.value, type]
  void loadEvents()
}

function toggleSelection(target: 'staff' | 'vehicles' | 'locations', id: string): void {
  const list = target === 'staff'
    ? selectedStaff
    : target === 'vehicles'
      ? selectedVehicles
      : selectedLocations

  list.value = list.value.includes(id)
    ? list.value.filter((value) => value !== id)
    : [...list.value, id]
}

function selectAll(target: 'staff' | 'vehicles' | 'locations'): void {
  if (target === 'staff') selectedStaff.value = activeStaff.value.map((item) => item.id)
  if (target === 'vehicles') selectedVehicles.value = activeVehicles.value.map((item) => item.id)
  if (target === 'locations') selectedLocations.value = activeLocations.value.map((item) => item.id)
}

function clearAll(target: 'staff' | 'vehicles' | 'locations'): void {
  if (target === 'staff') selectedStaff.value = []
  if (target === 'vehicles') selectedVehicles.value = []
  if (target === 'locations') selectedLocations.value = []
}

function openCreate(date?: Date): void {
  const base = date ?? cursor.value
  form.value = {
    ...emptyForm(),
    start_date: dateKey(base),
  }
  customMeetingPlaceMode.value = false
  error.value = ''
  drawerOpen.value = true
}

function closeDrawer(): void {
  drawerOpen.value = false
  customMeetingPlaceMode.value = false
  error.value = ''
}

function setMeetingPlaceMode(custom: boolean): void {
  customMeetingPlaceMode.value = custom
  if (custom) {
    form.value.location_id = ''
  } else {
    form.value.custom_meeting_place = ''
  }
}

async function saveEvent(): Promise<void> {
  error.value = ''
  notice.value = ''

  const start = buildDateTime(form.value.start_date, form.value.start_time)
  const durationMinutes = parseDuration(form.value.duration)
  if (!start || durationMinutes <= 0) {
    error.value = 'Podaj poprawną datę rozpoczęcia i czas trwania.'
    return
  }

  if (form.value.event_type === 'driving_lesson' && (!form.value.student_id || !form.value.instructor_id)) {
    error.value = 'Formalna jazda wymaga wskazania kursanta i instruktora.'
    return
  }

  saving.value = true
  try {
    const endsAt = new Date(start.getTime() + durationMinutes * 60_000)
    const common = {
      name: form.value.name.trim() || null,
      starts_at: start.toISOString(),
      ends_at: endsAt.toISOString(),
      student_id: form.value.student_id || null,
      instructor_id: form.value.instructor_id || null,
      vehicle_id: form.value.vehicle_id || null,
      location_id: customMeetingPlaceMode.value ? null : (form.value.location_id || null),
      custom_meeting_place: customMeetingPlaceMode.value
        ? (form.value.custom_meeting_place.trim() || null)
        : null,
    }

    if (form.value.event_type === 'general_event') {
      await api<CalendarItem>('/api/v1/calendar/events', {
        method: 'POST',
        idempotent: true,
        body: JSON.stringify({ event_type: 'general_event', ...common }),
      })
    } else {
      await api<CalendarItem>('/api/v1/calendar/driving-lessons', {
        method: 'POST',
        idempotent: true,
        body: JSON.stringify(common),
      })
    }

    notice.value = form.value.event_type === 'driving_lesson'
      ? 'Jazda została zaplanowana.'
      : 'Wydarzenie zostało dodane.'
    closeDrawer()
    await loadEvents()
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    saving.value = false
  }
}

function emptyForm(): EventForm {
  return {
    event_type: 'general_event',
    name: '',
    start_date: dateKey(new Date()),
    start_time: '08:00',
    duration: '01:00',
    student_id: '',
    instructor_id: '',
    vehicle_id: '',
    location_id: '',
    custom_meeting_place: '',
  }
}

function eventLabel(item: CalendarItem): string {
  if (item.name?.trim()) return item.name
  if (item.event_type === 'driving_lesson') return 'Jazda'
  if (item.event_type === 'important_date') return 'Ważna data'
  return 'Wydarzenie'
}

function eventMeta(item: CalendarItem): string {
  if (item.all_day) return 'Cały dzień'
  return `${formatTime(item.starts_at)}–${formatTime(item.ends_at)}`
}

function eventDetails(item: CalendarItem): string {
  const parts = [
    item.student_id ? studentMap.value.get(item.student_id) : null,
    item.instructor_id ? staffMap.value.get(item.instructor_id) : null,
    item.vehicle_id ? vehicleMap.value.get(item.vehicle_id) : null,
    item.location_id ? locationMap.value.get(item.location_id) : null,
    item.custom_meeting_place,
  ].filter((value): value is string => Boolean(value))

  return parts.join(' · ')
}

function typeLabel(type: CalendarEventType): string {
  if (type === 'general_event') return 'Wydarzenia'
  if (type === 'driving_lesson') return 'Jazdy'
  return 'Ważne daty'
}

function typeClass(item: CalendarItem): string {
  if (item.event_type === 'driving_lesson') return 'drive'
  if (item.event_type === 'important_date') return 'important'
  return 'general'
}

function matchesSelection(id: string | null, selected: string[], total: number): boolean {
  if (!referencesLoaded.value || total === 0 || selected.length === total) return true
  if (selected.length === 0) return id === null
  return id !== null && selected.includes(id)
}

function matchesLocation(item: CalendarItem): boolean {
  const total = activeLocations.value.length
  if (!referencesLoaded.value || total === 0 || selectedLocations.value.length === total) return true
  if (selectedLocations.value.length === 0) return item.location_id === null

  if (item.event_type === 'important_date' && item.location_id === null) {
    return true
  }

  return item.location_id !== null && selectedLocations.value.includes(item.location_id)
}

function eventDateKey(item: CalendarItem): string {
  if (item.source_date) return item.source_date

  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: PRODUCT_TIME_ZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(new Date(item.starts_at))

  const year = parts.find((part) => part.type === 'year')?.value ?? ''
  const month = parts.find((part) => part.type === 'month')?.value ?? ''
  const day = parts.find((part) => part.type === 'day')?.value ?? ''
  return `${year}-${month}-${day}`
}

function formatTime(value: string): string {
  return new Intl.DateTimeFormat('pl-PL', {
    hour: '2-digit',
    minute: '2-digit',
    timeZone: PRODUCT_TIME_ZONE,
  }).format(new Date(value))
}

function formatShortDate(value: Date): string {
  return new Intl.DateTimeFormat('pl-PL', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    timeZone: PRODUCT_TIME_ZONE,
  }).format(value)
}

function formatDayNumber(value: Date): string {
  return new Intl.DateTimeFormat('pl-PL', {
    day: 'numeric',
    timeZone: PRODUCT_TIME_ZONE,
  }).format(value)
}

function buildDateTime(date: string, time: string): Date | null {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date) || !/^\d{2}:\d{2}$/.test(time)) return null

  const candidate = new Date(`${date}T${time}:00`)
  return Number.isNaN(candidate.getTime()) ? null : candidate
}

function parseDuration(value: string): number {
  const match = /^(\d{1,2}):(\d{2})$/.exec(value.trim())
  if (!match) return 0

  const hours = Number(match[1])
  const minutes = Number(match[2])
  if (minutes > 59) return 0

  return hours * 60 + minutes
}

function startOfDay(value: Date): Date {
  return new Date(value.getFullYear(), value.getMonth(), value.getDate())
}

function addDays(value: Date, days: number): Date {
  const copy = new Date(value)
  copy.setDate(copy.getDate() + days)
  return startOfDay(copy)
}

function startOfWeek(value: Date): Date {
  const day = value.getDay() || 7
  return addDays(value, 1 - day)
}

function monthGridStart(value: Date): Date {
  return startOfWeek(new Date(value.getFullYear(), value.getMonth(), 1))
}

function dateKey(value: Date): string {
  const year = value.getFullYear()
  const month = String(value.getMonth() + 1).padStart(2, '0')
  const day = String(value.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function periodRange(value: Date, mode: CalendarView): { start: Date; end: Date } {
  if (mode === 'month') {
    const start = monthGridStart(value)
    return { start, end: addDays(start, 42) }
  }
  if (mode === 'week') {
    const start = startOfWeek(value)
    return { start, end: addDays(start, 7) }
  }

  const start = startOfDay(value)
  return { start, end: addDays(start, 1) }
}

function handleError(caught: unknown): void {
  if (caught instanceof ApiError) {
    const fieldMessage = Object.values(caught.fields).flat()[0]
    error.value = fieldMessage ?? caught.message
    return
  }

  error.value = 'Nie udało się wykonać operacji.'
}
</script>

<template>
  <main class="calendar-workspace">
    <header class="page-header">
      <div>
        <span class="section-kicker">Kalendarz OSK</span>
        <h1>Kalendarz</h1>
        <p>Jeden harmonogram dla jazd, wydarzeń i ważnych terminów.</p>
      </div>
      <button
        class="button primary"
        type="button"
        @click="openCreate()"
      >
        Dodaj wydarzenie
      </button>
    </header>

    <p
      v-if="error"
      class="flash error"
      role="alert"
    >
      {{ error }}
    </p>
    <p
      v-if="notice"
      class="flash success"
    >
      {{ notice }}
    </p>

    <div class="calendar-layout">
      <aside class="calendar-sidebar">
        <section class="filter-section">
          <h2>Rodzaje</h2>
          <label
            v-for="type in (['general_event', 'driving_lesson', 'important_date'] as CalendarEventType[])"
            :key="type"
            class="check-row"
          >
            <input
              type="checkbox"
              :checked="selectedTypes.includes(type)"
              @change="toggleType(type)"
            >
            <span class="filter-dot" :class="type === 'driving_lesson' ? 'drive' : type === 'important_date' ? 'important' : 'general'" />
            <span>{{ typeLabel(type) }}</span>
          </label>
        </section>

        <section class="filter-section">
          <div class="filter-heading">
            <h2>Pracownicy</h2>
            <a href="/pracownicy">Dodaj</a>
          </div>
          <div class="select-actions">
            <button type="button" @click="selectAll('staff')">Zaznacz wszystko</button>
            <button type="button" @click="clearAll('staff')">Wyczyść</button>
          </div>
          <label
            v-for="item in activeStaff"
            :key="item.id"
            class="check-row resource"
          >
            <input
              type="checkbox"
              :checked="selectedStaff.includes(item.id)"
              @change="toggleSelection('staff', item.id)"
            >
            <span>{{ item.first_name }} {{ item.last_name }}</span>
          </label>
          <p v-if="activeStaff.length === 0" class="empty-filter">Brak dostępnych pracowników.</p>
        </section>

        <section class="filter-section">
          <div class="filter-heading">
            <h2>Pojazdy</h2>
            <a href="/pojazdy">Dodaj</a>
          </div>
          <div class="select-actions">
            <button type="button" @click="selectAll('vehicles')">Zaznacz wszystko</button>
            <button type="button" @click="clearAll('vehicles')">Wyczyść</button>
          </div>
          <label
            v-for="item in activeVehicles"
            :key="item.id"
            class="check-row resource"
          >
            <input
              type="checkbox"
              :checked="selectedVehicles.includes(item.id)"
              @change="toggleSelection('vehicles', item.id)"
            >
            <span>{{ item.make }} {{ item.model }}</span>
            <small>{{ item.registration_number }}</small>
          </label>
          <p v-if="activeVehicles.length === 0" class="empty-filter">Brak dostępnych pojazdów.</p>
        </section>

        <section class="filter-section">
          <div class="filter-heading">
            <h2>Lokalizacje</h2>
            <a href="/lokalizacje">Dodaj</a>
          </div>
          <div class="select-actions">
            <button type="button" @click="selectAll('locations')">Zaznacz wszystko</button>
            <button type="button" @click="clearAll('locations')">Wyczyść</button>
          </div>
          <label
            v-for="item in activeLocations"
            :key="item.id"
            class="check-row resource"
          >
            <input
              type="checkbox"
              :checked="selectedLocations.includes(item.id)"
              @change="toggleSelection('locations', item.id)"
            >
            <span>{{ item.name }}</span>
            <small>{{ item.street_and_number }}, {{ item.city_name }}</small>
          </label>
          <p v-if="activeLocations.length === 0" class="empty-filter">Brak dostępnych lokalizacji.</p>
        </section>
      </aside>

      <section class="calendar-card">
        <div class="calendar-toolbar">
          <div class="period-nav">
            <button
              type="button"
              aria-label="Poprzedni okres"
              @click="navigate(-1)"
            >
              ‹
            </button>
            <button type="button" @click="goToday">Dzisiaj</button>
            <button
              type="button"
              aria-label="Następny okres"
              @click="navigate(1)"
            >
              ›
            </button>
          </div>

          <strong class="period-label">{{ periodLabel }}</strong>

          <div class="view-switch">
            <button
              type="button"
              :class="{ active: view === 'month' }"
              @click="switchView('month')"
            >
              Miesiąc
            </button>
            <button
              type="button"
              :class="{ active: view === 'week' }"
              @click="switchView('week')"
            >
              Tydzień
            </button>
            <button
              type="button"
              :class="{ active: view === 'day' }"
              @click="switchView('day')"
            >
              Dzień
            </button>
          </div>
        </div>

        <div v-if="loading" class="calendar-state">Ładowanie kalendarza…</div>

        <template v-else-if="view === 'month'">
          <div class="weekday-row">
            <span v-for="label in WEEKDAY_LABELS" :key="label">{{ label }}</span>
          </div>
          <div class="month-grid">
            <button
              v-for="day in monthDays"
              :key="day.key"
              class="month-day"
              :class="{ muted: !day.currentMonth, today: day.today }"
              type="button"
              @dblclick="openCreate(day.date)"
            >
              <span class="day-number">{{ formatDayNumber(day.date) }}</span>
              <span
                v-for="item in day.items.slice(0, 4)"
                :key="`${item.source_kind}-${item.source_id}`"
                class="calendar-chip"
                :class="typeClass(item)"
                :title="eventDetails(item)"
              >
                <b>{{ eventMeta(item) }}</b>
                {{ eventLabel(item) }}
              </span>
              <span v-if="day.items.length > 4" class="more-items">+{{ day.items.length - 4 }} więcej</span>
            </button>
          </div>
        </template>

        <div v-else-if="view === 'week'" class="week-grid">
          <section
            v-for="(day, index) in weekDays"
            :key="day.key"
            class="week-day"
            :class="{ today: day.today }"
          >
            <button class="week-day-heading" type="button" @click="openCreate(day.date)">
              <span>{{ WEEKDAY_LABELS[index] }}</span>
              <strong>{{ formatDayNumber(day.date) }}</strong>
            </button>
            <article
              v-for="item in day.items"
              :key="`${item.source_kind}-${item.source_id}`"
              class="agenda-item"
              :class="typeClass(item)"
            >
              <small>{{ eventMeta(item) }}</small>
              <strong>{{ eventLabel(item) }}</strong>
              <span>{{ eventDetails(item) }}</span>
            </article>
            <p v-if="day.items.length === 0" class="empty-day">Brak wpisów</p>
          </section>
        </div>

        <div v-else class="day-view">
          <button class="day-create" type="button" @click="openCreate(cursor)">
            + Dodaj wpis w tym dniu
          </button>
          <article
            v-for="item in dayEvents"
            :key="`${item.source_kind}-${item.source_id}`"
            class="day-agenda-item"
            :class="typeClass(item)"
          >
            <div class="day-time">{{ eventMeta(item) }}</div>
            <div>
              <strong>{{ eventLabel(item) }}</strong>
              <p>{{ eventDetails(item) || 'Bez przypisanych zasobów' }}</p>
            </div>
          </article>
          <div v-if="dayEvents.length === 0" class="calendar-state">
            Brak wpisów w tym dniu.
          </div>
        </div>
      </section>
    </div>

    <div
      v-if="drawerOpen"
      class="drawer-backdrop"
      @click.self="closeDrawer"
    >
      <section
        class="event-drawer"
        role="dialog"
        aria-modal="true"
        aria-labelledby="calendar-create-title"
      >
        <header class="drawer-header">
          <div>
            <span class="section-kicker">Kalendarz</span>
            <h2 id="calendar-create-title">Dodaj wydarzenie</h2>
          </div>
          <button
            class="close-button"
            type="button"
            aria-label="Zamknij"
            @click="closeDrawer"
          >
            ×
          </button>
        </header>

        <form class="event-form" @submit.prevent="saveEvent">
          <label class="full">
            Rodzaj wydarzenia *
            <select v-model="form.event_type" required>
              <option value="general_event">Wydarzenie</option>
              <option value="driving_lesson">Jazda</option>
            </select>
          </label>

          <label class="full">
            Nazwa wydarzenia (opcjonalnie)
            <input v-model="form.name" maxlength="255">
          </label>

          <label>
            Data rozpoczęcia *
            <input v-model="form.start_date" type="date" required>
          </label>

          <label>
            Godzina *
            <input v-model="form.start_time" type="time" required>
          </label>

          <label class="full">
            Ilość godzin
            <input
              v-model="form.duration"
              inputmode="numeric"
              placeholder="01:00"
              pattern="\d{1,2}:\d{2}"
            >
            <small>Format GG:MM.</small>
          </label>

          <label class="full">
            Kursant (opcjonalnie)
            <select v-model="form.student_id">
              <option value="">Nie przypisuj</option>
              <option
                v-for="item in activeStudents"
                :key="item.id"
                :value="item.id"
              >
                {{ item.first_name }} {{ item.last_name }}
              </option>
            </select>
          </label>

          <label class="full">
            Instruktor (opcjonalnie)
            <select v-model="form.instructor_id">
              <option value="">Nie przypisuj</option>
              <option
                v-for="item in activeStaff"
                :key="item.id"
                :value="item.id"
              >
                {{ item.first_name }} {{ item.last_name }} · {{ item.email }}
              </option>
            </select>
          </label>

          <label class="full">
            Pojazd (opcjonalnie)
            <select v-model="form.vehicle_id">
              <option value="">Nie przypisuj</option>
              <option
                v-for="item in activeVehicles"
                :key="item.id"
                :value="item.id"
              >
                {{ item.make }} {{ item.model }} · {{ item.registration_number }}
              </option>
            </select>
          </label>

          <div class="full meeting-field">
            <label>
              Miejsce spotkania (opcjonalnie)
              <select
                v-if="!customMeetingPlaceMode"
                v-model="form.location_id"
              >
                <option value="">Nie przypisuj</option>
                <option
                  v-for="item in activeLocations"
                  :key="item.id"
                  :value="item.id"
                >
                  {{ item.name }} · {{ item.street_and_number }}
                </option>
              </select>
              <input
                v-else
                v-model="form.custom_meeting_place"
                maxlength="255"
                placeholder="Wpisz miejsce spotkania"
              >
            </label>
            <button
              class="meeting-toggle"
              type="button"
              @click="setMeetingPlaceMode(!customMeetingPlaceMode)"
            >
              {{ customMeetingPlaceMode ? 'Wróć' : 'Inne?' }}
            </button>
          </div>

          <p
            v-if="form.event_type === 'driving_lesson'"
            class="form-note full"
          >
            Jazda jest zapisywana jako formalna sesja praktyczna. System sprawdzi kurs, instruktora i konflikty zasobów przy zapisie.
          </p>

          <div class="drawer-actions full">
            <button class="button ghost" type="button" @click="closeDrawer">
              Anuluj
            </button>
            <button class="button primary" type="submit" :disabled="saving">
              {{ saving ? 'Zapisywanie…' : 'Zapisz' }}
            </button>
          </div>
        </form>
      </section>
    </div>
  </main>
</template>

<style scoped>
.calendar-workspace {
  max-width: 1560px;
  margin: 0 auto;
  padding: 28px;
  color: #1f2937;
}

.page-header,
.calendar-toolbar,
.filter-heading,
.select-actions,
.drawer-header,
.drawer-actions {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.page-header {
  margin-bottom: 20px;
}

.page-header h1,
.drawer-header h2,
.filter-section h2 {
  margin: 0;
}

.page-header h1 {
  font-size: clamp(28px, 3vw, 42px);
  letter-spacing: -0.04em;
}

.page-header p {
  margin: 6px 0 0;
  color: #6b7280;
}

.section-kicker {
  display: block;
  margin-bottom: 4px;
  color: #8a6a11;
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.calendar-layout {
  display: grid;
  grid-template-columns: 250px minmax(0, 1fr);
  gap: 18px;
  align-items: start;
}

.calendar-sidebar,
.calendar-card,
.event-drawer {
  border: 1px solid #e5e7eb;
  background: #fff;
  border-radius: 16px;
  box-shadow: 0 8px 28px rgb(15 23 42 / 5%);
}

.calendar-sidebar {
  overflow: hidden;
}

.filter-section {
  padding: 16px;
  border-bottom: 1px solid #eef0f2;
}

.filter-section:last-child {
  border-bottom: 0;
}

.filter-section h2 {
  font-size: 14px;
}

.filter-heading a {
  color: #78600f;
  font-size: 12px;
  font-weight: 700;
  text-decoration: none;
}

.select-actions {
  justify-content: flex-start;
  margin: 9px 0;
}

.select-actions button,
.period-nav button,
.view-switch button,
.week-day-heading,
.day-create,
.meeting-toggle,
.close-button {
  border: 0;
  background: transparent;
  color: #4b5563;
  cursor: pointer;
  font: inherit;
}

.select-actions button {
  padding: 0;
  color: #6b7280;
  font-size: 11px;
}

.check-row {
  display: grid;
  grid-template-columns: auto auto 1fr;
  gap: 8px;
  align-items: center;
  min-height: 30px;
  font-size: 13px;
}

.check-row.resource {
  grid-template-columns: auto 1fr;
}

.check-row.resource small {
  grid-column: 2;
  margin-top: -5px;
  color: #9ca3af;
}

.filter-dot {
  width: 8px;
  height: 8px;
  border-radius: 999px;
  background: #d1d5db;
}

.filter-dot.general,
.calendar-chip.general {
  border-left-color: #8b8f97;
}

.filter-dot.drive,
.calendar-chip.drive {
  border-left-color: #d3a51f;
}

.filter-dot.important,
.calendar-chip.important {
  border-left-color: #b7791f;
}

.filter-dot.general { background: #8b8f97; }
.filter-dot.drive { background: #d3a51f; }
.filter-dot.important { background: #b7791f; }

.empty-filter {
  margin: 8px 0 0;
  color: #9ca3af;
  font-size: 12px;
}

.calendar-card {
  min-width: 0;
  overflow: hidden;
}

.calendar-toolbar {
  min-height: 68px;
  padding: 12px 16px;
  border-bottom: 1px solid #e5e7eb;
}

.period-nav,
.view-switch {
  display: flex;
  gap: 6px;
}

.period-nav button,
.view-switch button {
  min-height: 36px;
  padding: 0 12px;
  border: 1px solid #e5e7eb;
  border-radius: 9px;
  background: #fff;
}

.view-switch button.active {
  border-color: #d7b23b;
  background: #fff9e8;
  color: #6c550b;
  font-weight: 700;
}

.period-label {
  text-transform: capitalize;
}

.weekday-row,
.month-grid,
.week-grid {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
}

.weekday-row {
  border-bottom: 1px solid #e5e7eb;
  background: #fafafa;
}

.weekday-row span {
  padding: 9px;
  color: #6b7280;
  font-size: 12px;
  font-weight: 700;
  text-align: center;
}

.month-day {
  min-height: 136px;
  padding: 8px;
  overflow: hidden;
  border: 0;
  border-right: 1px solid #eef0f2;
  border-bottom: 1px solid #eef0f2;
  background: #fff;
  color: inherit;
  cursor: default;
  text-align: left;
}

.month-day.muted {
  background: #fafafa;
  color: #a3a3a3;
}

.month-day.today,
.week-day.today {
  background: #fffdf5;
}

.day-number {
  display: inline-flex;
  width: 25px;
  height: 25px;
  margin-bottom: 6px;
  align-items: center;
  justify-content: center;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 700;
}

.today .day-number {
  background: #efc54f;
  color: #2b2515;
}

.calendar-chip {
  display: block;
  margin: 3px 0;
  padding: 4px 6px;
  overflow: hidden;
  border-left: 3px solid #9ca3af;
  border-radius: 6px;
  background: #f7f7f6;
  font-size: 11px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.calendar-chip b {
  margin-right: 4px;
  font-weight: 700;
}

.more-items {
  color: #6b7280;
  font-size: 11px;
}

.week-grid {
  min-height: 620px;
}

.week-day {
  min-width: 0;
  padding: 8px;
  border-right: 1px solid #eef0f2;
}

.week-day-heading {
  display: flex;
  width: 100%;
  padding: 8px 4px 12px;
  align-items: center;
  justify-content: center;
  gap: 7px;
}

.week-day-heading strong {
  font-size: 20px;
}

.agenda-item,
.day-agenda-item {
  margin-bottom: 8px;
  border-left: 3px solid #9ca3af;
  border-radius: 8px;
  background: #f8f8f7;
}

.agenda-item {
  display: flex;
  padding: 8px;
  flex-direction: column;
  gap: 3px;
}

.agenda-item.drive,
.day-agenda-item.drive { border-left-color: #d3a51f; }
.agenda-item.important,
.day-agenda-item.important { border-left-color: #b7791f; }

.agenda-item span,
.day-agenda-item p {
  color: #6b7280;
  font-size: 11px;
}

.empty-day,
.calendar-state {
  color: #9ca3af;
  font-size: 12px;
}

.calendar-state {
  padding: 48px;
  text-align: center;
}

.day-view {
  padding: 18px;
}

.day-create {
  margin-bottom: 14px;
  color: #78600f;
  font-weight: 700;
}

.day-agenda-item {
  display: grid;
  grid-template-columns: 120px 1fr;
  gap: 18px;
  padding: 14px 16px;
}

.day-agenda-item p {
  margin: 4px 0 0;
}

.day-time {
  font-size: 13px;
  font-weight: 700;
}

.flash {
  margin: 0 0 14px;
  padding: 10px 14px;
  border-radius: 10px;
  font-size: 13px;
}

.flash.error {
  background: #fff1f1;
  color: #9b2c2c;
}

.flash.success {
  background: #f5f5ef;
  color: #50622a;
}

.drawer-backdrop {
  position: fixed;
  z-index: 100;
  inset: 0;
  display: flex;
  justify-content: flex-end;
  background: rgb(15 23 42 / 34%);
}

.event-drawer {
  width: min(520px, 100%);
  height: 100%;
  overflow: auto;
  border-radius: 0;
  padding: 22px;
}

.drawer-header {
  padding-bottom: 16px;
  border-bottom: 1px solid #e5e7eb;
}

.close-button {
  width: 38px;
  height: 38px;
  font-size: 26px;
}

.event-form {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px;
  padding-top: 18px;
}

.event-form label {
  display: grid;
  gap: 6px;
  color: #4b5563;
  font-size: 12px;
  font-weight: 700;
}

.event-form input,
.event-form select {
  width: 100%;
  min-height: 42px;
  box-sizing: border-box;
  border: 1px solid #dfe2e6;
  border-radius: 9px;
  background: #fff;
  padding: 0 11px;
  color: #1f2937;
  font: inherit;
}

.event-form small,
.form-note {
  color: #7b8190;
  font-size: 11px;
  font-weight: 400;
}

.full {
  grid-column: 1 / -1;
}

.meeting-field {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 10px;
  align-items: end;
}

.meeting-toggle {
  min-height: 42px;
  color: #78600f;
  font-weight: 700;
}

.form-note {
  margin: 0;
  padding: 10px 12px;
  border-radius: 8px;
  background: #faf8ef;
}

.drawer-actions {
  justify-content: flex-end;
  padding-top: 8px;
}

.button {
  min-height: 40px;
  border: 1px solid #dfe2e6;
  border-radius: 9px;
  padding: 0 15px;
  cursor: pointer;
  font: inherit;
  font-weight: 700;
}

.button.primary {
  border-color: #efc54f;
  background: #efc54f;
  color: #2b2515;
}

.button.ghost {
  background: #fff;
  color: #374151;
}

.button:disabled {
  cursor: wait;
  opacity: 0.55;
}

@media (max-width: 1000px) {
  .calendar-layout {
    grid-template-columns: 1fr;
  }

  .calendar-sidebar {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
  }

  .filter-section {
    border-right: 1px solid #eef0f2;
  }
}

@media (max-width: 760px) {
  .calendar-workspace {
    padding: 16px;
  }

  .page-header,
  .calendar-toolbar {
    align-items: stretch;
    flex-direction: column;
  }

  .calendar-sidebar {
    grid-template-columns: 1fr;
  }

  .month-day {
    min-height: 96px;
    padding: 4px;
  }

  .calendar-chip b {
    display: none;
  }

  .week-grid {
    grid-template-columns: 1fr;
    min-height: auto;
  }

  .week-day {
    border-bottom: 1px solid #eef0f2;
  }

  .event-form {
    grid-template-columns: 1fr;
  }

  .event-form > *,
  .full {
    grid-column: 1;
  }
}
</style>
