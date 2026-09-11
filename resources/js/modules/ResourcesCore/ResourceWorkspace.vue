<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import CalendarShell from './CalendarShell.vue'
import { ApiError, api } from './api'

type LocationType = { code: string; label: string }
type Category = { id: string; code: string; label: string; active: boolean }
type StaffType = { code: string; label: string }

type LocationResource = {
  id: string
  type_code: string
  name: string
  street_and_number: string
  postal_code: string
  city_name: string
  voivodeship_name: string | null
  archived_at: string | null
}

type StaffResource = {
  id: string
  first_name: string
  last_name: string
  email: string
  pesel_masked: string | null
  phone: string | null
  authorization_number: string | null
  staff_type_codes: string[]
  category_ids: string[]
  location_ids: string[]
  card_valid_until: string | null
  medical_exam_valid_until: string | null
  psychological_exam_valid_until: string | null
  photo_asset_id: string | null
  has_login_account: boolean
  archived_at: string | null
}

type VehicleResource = {
  id: string
  registration_number: string
  side_number: string | null
  make: string
  model: string
  production_year: number | null
  engine_capacity_cm3: number | null
  vin: string | null
  category_ids: string[]
  location_ids: string[]
  next_inspection_at: string | null
  oc_valid_until: string | null
  ac_valid_until: string | null
  photo_asset_id: string | null
  archived_at: string | null
}

type Paginated<T> = {
  data: T[]
  meta: { page: number; per_page: number; total: number; last_page: number }
}

type LocationForm = {
  id: string | null
  type_code: string
  name: string
  street_and_number: string
  postal_code: string
  city_reference: string
}

type StaffForm = {
  id: string | null
  email: string
  first_name: string
  last_name: string
  staff_type_codes: string[]
  pesel: string
  phone: string
  authorization_number: string
  category_ids: string[]
  location_ids: string[]
  card_valid_until: string
  medical_exam_valid_until: string
  psychological_exam_valid_until: string
  create_login_account: boolean
}

type VehicleForm = {
  id: string | null
  registration_number: string
  side_number: string
  make: string
  model: string
  production_year: string
  engine_capacity_cm3: string
  vin: string
  category_ids: string[]
  location_ids: string[]
  next_inspection_at: string
  oc_valid_until: string
  ac_valid_until: string
}

const pathParts = window.location.pathname.split('/').filter(Boolean)
const routeRoot = pathParts[0] ?? 'home'
const detailId = pathParts[1] ?? null
const calendarQuery = window.location.search

const section = computed<'locations' | 'staff' | 'vehicles' | 'calendar' | 'home'>(() => {
  if (routeRoot === 'lokalizacje') return 'locations'
  if (routeRoot === 'pracownicy') return 'staff'
  if (routeRoot === 'pojazdy') return 'vehicles'
  if (routeRoot === 'kalendarz') return 'calendar'
  return 'home'
})

const loading = ref(false)
const saving = ref(false)
const error = ref('')
const notice = ref('')
const search = ref('')

const locations = ref<LocationResource[]>([])
const staff = ref<StaffResource[]>([])
const vehicles = ref<VehicleResource[]>([])
const locationTypes = ref<LocationType[]>([])
const staffTypes = ref<StaffType[]>([])
const categories = ref<Category[]>([])

const currentStaff = ref<StaffResource | null>(null)
const currentVehicle = ref<VehicleResource | null>(null)
const currentEtag = ref<string | null>(null)

const drawer = ref<'location' | 'staff' | 'vehicle' | null>(null)
const pendingPhotoName = ref('')

const locationForm = ref<LocationForm>(emptyLocationForm())
const staffForm = ref<StaffForm>(emptyStaffForm())
const vehicleForm = ref<VehicleForm>(emptyVehicleForm())

const permissionsOpen = ref(false)
const currentPermissions = ref<string[]>([])
const permissionOptions = [
  'locations.view',
  'locations.create',
  'locations.edit',
  'staff.view',
  'staff.create',
  'staff.edit',
  'vehicles.view',
  'vehicles.create',
  'vehicles.edit',
]

const pageTitle = computed(() => {
  if (section.value === 'locations') return 'Lokalizacje'
  if (section.value === 'staff') return currentStaff.value ? `${currentStaff.value.first_name} ${currentStaff.value.last_name}` : 'Pracownicy'
  if (section.value === 'vehicles') return currentVehicle.value ? currentVehicle.value.registration_number : 'Pojazdy'
  if (section.value === 'calendar') return 'Kalendarz'
  return 'Panel OSK'
})

const locationTypeMap = computed(() => new Map(locationTypes.value.map((item) => [item.code, item.label])))
const locationMap = computed(() => new Map(locations.value.map((item) => [item.id, item.name])))
const categoryMap = computed(() => new Map(categories.value.map((item) => [item.id, item.code])))
const staffTypeMap = computed(() => new Map(staffTypes.value.map((item) => [item.code, item.label])))

const filteredStaff = computed(() => {
  const query = search.value.trim().toLocaleLowerCase('pl')
  if (!query) return staff.value

  return staff.value.filter((item) =>
    [item.first_name, item.last_name, item.email].some((value) => value.toLocaleLowerCase('pl').includes(query)),
  )
})

const filteredVehicles = computed(() => {
  const query = search.value.trim().toLocaleLowerCase('pl')
  if (!query) return vehicles.value

  return vehicles.value.filter((item) =>
    [item.registration_number, item.make, item.model].some((value) => value.toLocaleLowerCase('pl').includes(query)),
  )
})

onMounted(load)

async function load(): Promise<void> {
  loading.value = true
  error.value = ''

  try {
    if (section.value === 'locations') {
      const [locationResult, typesResult] = await Promise.all([
        api<LocationResource[]>('/api/v1/locations'),
        api<LocationType[]>('/api/v1/location-types'),
      ])
      locations.value = locationResult.data
      locationTypes.value = typesResult.data
      return
    }

    if (section.value === 'staff') {
      const common = await Promise.all([
        api<LocationResource[]>('/api/v1/locations'),
        api<StaffType[]>('/api/v1/staff-types'),
        api<Category[]>('/api/v1/driving-categories'),
      ])
      locations.value = common[0].data
      staffTypes.value = common[1].data
      categories.value = common[2].data

      if (detailId) {
        const result = await api<StaffResource>(`/api/v1/staff/${detailId}`)
        currentStaff.value = result.data
        currentEtag.value = result.etag
      } else {
        staff.value = (await api<Paginated<StaffResource>>('/api/v1/staff?per_page=100')).data.data
      }
      return
    }

    if (section.value === 'vehicles') {
      const common = await Promise.all([
        api<LocationResource[]>('/api/v1/locations'),
        api<Category[]>('/api/v1/driving-categories'),
      ])
      locations.value = common[0].data
      categories.value = common[1].data

      if (detailId) {
        const result = await api<VehicleResource>(`/api/v1/vehicles/${detailId}`)
        currentVehicle.value = result.data
        currentEtag.value = result.etag
      } else {
        vehicles.value = (await api<Paginated<VehicleResource>>('/api/v1/vehicles?per_page=100')).data.data
      }
    }
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    loading.value = false
  }
}

function emptyLocationForm(): LocationForm {
  return {
    id: null,
    type_code: 'branch',
    name: '',
    street_and_number: '',
    postal_code: '',
    city_reference: '',
  }
}

function emptyStaffForm(): StaffForm {
  return {
    id: null,
    email: '',
    first_name: '',
    last_name: '',
    staff_type_codes: [],
    pesel: '',
    phone: '',
    authorization_number: '',
    category_ids: [],
    location_ids: [],
    card_valid_until: '',
    medical_exam_valid_until: '',
    psychological_exam_valid_until: '',
    create_login_account: false,
  }
}

function emptyVehicleForm(): VehicleForm {
  return {
    id: null,
    registration_number: '',
    side_number: '',
    make: '',
    model: '',
    production_year: '',
    engine_capacity_cm3: '',
    vin: '',
    category_ids: [],
    location_ids: [],
    next_inspection_at: '',
    oc_valid_until: '',
    ac_valid_until: '',
  }
}

function openLocationCreate(): void {
  currentEtag.value = null
  locationForm.value = emptyLocationForm()
  drawer.value = 'location'
}

async function openLocationEdit(item: LocationResource): Promise<void> {
  try {
    const result = await api<LocationResource>(`/api/v1/locations/${item.id}`)
    currentEtag.value = result.etag
    locationForm.value = {
      id: result.data.id,
      type_code: result.data.type_code,
      name: result.data.name,
      street_and_number: result.data.street_and_number,
      postal_code: result.data.postal_code,
      city_reference: result.data.city_name,
    }
    drawer.value = 'location'
  } catch (caught: unknown) {
    handleError(caught)
  }
}

function openStaffCreate(): void {
  currentEtag.value = null
  staffForm.value = emptyStaffForm()
  drawer.value = 'staff'
}

function openStaffEdit(item: StaffResource): void {
  staffForm.value = {
    id: item.id,
    email: item.email,
    first_name: item.first_name,
    last_name: item.last_name,
    staff_type_codes: [...item.staff_type_codes],
    pesel: '',
    phone: item.phone ?? '',
    authorization_number: item.authorization_number ?? '',
    category_ids: [...item.category_ids],
    location_ids: [...item.location_ids],
    card_valid_until: item.card_valid_until ?? '',
    medical_exam_valid_until: item.medical_exam_valid_until ?? '',
    psychological_exam_valid_until: item.psychological_exam_valid_until ?? '',
    create_login_account: false,
  }
  drawer.value = 'staff'
}

function openVehicleCreate(): void {
  currentEtag.value = null
  vehicleForm.value = emptyVehicleForm()
  drawer.value = 'vehicle'
}

function openVehicleEdit(item: VehicleResource): void {
  vehicleForm.value = {
    id: item.id,
    registration_number: item.registration_number,
    side_number: item.side_number ?? '',
    make: item.make,
    model: item.model,
    production_year: item.production_year?.toString() ?? '',
    engine_capacity_cm3: item.engine_capacity_cm3?.toString() ?? '',
    vin: item.vin ?? '',
    category_ids: [...item.category_ids],
    location_ids: [...item.location_ids],
    next_inspection_at: item.next_inspection_at ?? '',
    oc_valid_until: item.oc_valid_until ?? '',
    ac_valid_until: item.ac_valid_until ?? '',
  }
  drawer.value = 'vehicle'
}

function closeDrawer(): void {
  drawer.value = null
  pendingPhotoName.value = ''
  error.value = ''
}

async function saveLocation(): Promise<void> {
  saving.value = true
  error.value = ''
  try {
    const body = {
      type_code: locationForm.value.type_code,
      name: locationForm.value.name,
      street_and_number: locationForm.value.street_and_number,
      postal_code: locationForm.value.postal_code,
      city_reference: locationForm.value.city_reference,
    }

    if (locationForm.value.id) {
      await api<LocationResource>(`/api/v1/locations/${locationForm.value.id}`, {
        method: 'PATCH',
        headers: currentEtag.value ? { 'If-Match': currentEtag.value } : undefined,
        body: JSON.stringify(body),
      })
    } else {
      await api<LocationResource>('/api/v1/locations', {
        method: 'POST',
        idempotent: true,
        body: JSON.stringify(body),
      })
    }

    notice.value = 'Lokalizacja została zapisana.'
    closeDrawer()
    await load()
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    saving.value = false
  }
}

async function saveStaff(): Promise<void> {
  saving.value = true
  error.value = ''
  try {
    const body: Record<string, unknown> = {
      email: staffForm.value.email,
      first_name: staffForm.value.first_name,
      last_name: staffForm.value.last_name,
      staff_type_codes: staffForm.value.staff_type_codes,
      phone: staffForm.value.phone || null,
      authorization_number: staffForm.value.authorization_number || null,
      category_ids: staffForm.value.category_ids,
      location_ids: staffForm.value.location_ids,
      card_valid_until: staffForm.value.card_valid_until || null,
      medical_exam_valid_until: staffForm.value.medical_exam_valid_until || null,
      psychological_exam_valid_until: staffForm.value.psychological_exam_valid_until || null,
    }

    if (staffForm.value.pesel.trim()) {
      body.pesel = staffForm.value.pesel.trim()
    }
    if (!staffForm.value.id && staffForm.value.create_login_account) {
      body.create_login_account = true
    }

    if (staffForm.value.id) {
      const result = await api<StaffResource>(`/api/v1/staff/${staffForm.value.id}`, {
        method: 'PATCH',
        headers: currentEtag.value ? { 'If-Match': currentEtag.value } : undefined,
        body: JSON.stringify(body),
      })
      currentStaff.value = result.data
      currentEtag.value = result.etag
    } else {
      await api<StaffResource>('/api/v1/staff', {
        method: 'POST',
        idempotent: true,
        body: JSON.stringify(body),
      })
    }

    notice.value = 'Dane pracownika zostały zapisane.'
    closeDrawer()
    await load()
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    saving.value = false
  }
}

async function saveVehicle(): Promise<void> {
  saving.value = true
  error.value = ''
  try {
    const body = {
      registration_number: vehicleForm.value.registration_number,
      side_number: vehicleForm.value.side_number || null,
      make: vehicleForm.value.make,
      model: vehicleForm.value.model,
      production_year: vehicleForm.value.production_year ? Number(vehicleForm.value.production_year) : null,
      engine_capacity_cm3: vehicleForm.value.engine_capacity_cm3 ? Number(vehicleForm.value.engine_capacity_cm3) : null,
      vin: vehicleForm.value.vin || null,
      category_ids: vehicleForm.value.category_ids,
      location_ids: vehicleForm.value.location_ids,
      next_inspection_at: vehicleForm.value.next_inspection_at || null,
      oc_valid_until: vehicleForm.value.oc_valid_until || null,
      ac_valid_until: vehicleForm.value.ac_valid_until || null,
    }

    if (vehicleForm.value.id) {
      const result = await api<VehicleResource>(`/api/v1/vehicles/${vehicleForm.value.id}`, {
        method: 'PATCH',
        headers: currentEtag.value ? { 'If-Match': currentEtag.value } : undefined,
        body: JSON.stringify(body),
      })
      currentVehicle.value = result.data
      currentEtag.value = result.etag
    } else {
      await api<VehicleResource>('/api/v1/vehicles', {
        method: 'POST',
        idempotent: true,
        body: JSON.stringify(body),
      })
    }

    notice.value = 'Pojazd został zapisany.'
    closeDrawer()
    await load()
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    saving.value = false
  }
}

async function archiveLocation(item: LocationResource): Promise<void> {
  if (!window.confirm(`Archiwizować lokalizację „${item.name}”? Historia pozostanie zachowana.`)) return

  await command(`/api/v1/locations/${item.id}/archive`, 'Lokalizacja została zarchiwizowana.')
}

async function restoreLocation(item: LocationResource): Promise<void> {
  await command(`/api/v1/locations/${item.id}/restore`, 'Lokalizacja została przywrócona.')
}

async function archiveStaff(): Promise<void> {
  if (!currentStaff.value) return
  if (!window.confirm('Archiwizować profil pracownika? Historia zostanie zachowana, a aktywne powiązanie z panelem zostanie zakończone.')) return

  await command(`/api/v1/staff/${currentStaff.value.id}/archive`, 'Profil pracownika został zarchiwizowany.')
}

async function restoreStaff(): Promise<void> {
  if (!currentStaff.value) return
  await command(`/api/v1/staff/${currentStaff.value.id}/restore`, 'Profil pracownika został przywrócony. Dostęp do panelu nie został automatycznie wznowiony.')
}

async function archiveVehicle(deleteLabel = false): Promise<void> {
  if (!currentVehicle.value) return
  const prompt = deleteLabel
    ? 'Ta bezpieczna akcja nie usuwa historii. Pojazd zostanie zarchiwizowany. Kontynuować?'
    : 'Archiwizować pojazd? Historia i ważności pozostaną zachowane.'
  if (!window.confirm(prompt)) return

  await command(
    `/api/v1/vehicles/${currentVehicle.value.id}/archive`,
    deleteLabel ? 'Pojazd został bezpiecznie zarchiwizowany zamiast fizycznego usunięcia.' : 'Pojazd został zarchiwizowany.',
  )
}

async function restoreVehicle(): Promise<void> {
  if (!currentVehicle.value) return
  await command(`/api/v1/vehicles/${currentVehicle.value.id}/restore`, 'Pojazd został przywrócony.')
}

async function createPanelAccount(): Promise<void> {
  if (!currentStaff.value) return
  await command(
    `/api/v1/staff/${currentStaff.value.id}/user-account`,
    'Konto panelowe zostało bezpiecznie przygotowane. Aktywacja logowania pozostaje osobnym krokiem bezpieczeństwa.',
  )
}

async function revokePanelAccount(): Promise<void> {
  if (!currentStaff.value) return
  if (!window.confirm('Odłączyć dostęp panelowy od profilu pracownika? Profil i historia pozostaną zachowane.')) return

  saving.value = true
  error.value = ''
  try {
    await api<void>(`/api/v1/staff/${currentStaff.value.id}/user-account`, { method: 'DELETE' })
    notice.value = 'Dostęp panelowy został odłączony.'
    await load()
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    saving.value = false
  }
}

async function openPermissions(): Promise<void> {
  if (!currentStaff.value) return
  error.value = ''

  try {
    const result = await api<{ permissions: string[] }>(`/api/v1/staff/${currentStaff.value.id}/permissions`)
    currentPermissions.value = result.data.permissions
    permissionsOpen.value = true
  } catch (caught: unknown) {
    handleError(caught)
  }
}

async function savePermissions(): Promise<void> {
  if (!currentStaff.value) return
  saving.value = true
  error.value = ''

  try {
    await api<{ permissions: string[] }>(`/api/v1/staff/${currentStaff.value.id}/permissions`, {
      method: 'PUT',
      idempotent: true,
      body: JSON.stringify({ permissions: currentPermissions.value }),
    })
    notice.value = 'Uprawnienia konta zostały zapisane.'
    permissionsOpen.value = false
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    saving.value = false
  }
}

async function command(url: string, successMessage: string): Promise<void> {
  saving.value = true
  error.value = ''
  try {
    await api<unknown>(url, { method: 'POST', idempotent: true, body: JSON.stringify({}) })
    notice.value = successMessage
    await load()
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    saving.value = false
  }
}

function handlePhoto(event: Event): void {
  const input = event.target as HTMLInputElement
  pendingPhotoName.value = input.files?.[0]?.name ?? ''
}

function handleError(caught: unknown): void {
  if (caught instanceof ApiError) {
    const fieldMessages = Object.values(caught.fields).flat()
    error.value = fieldMessages[0] ?? caught.message
    return
  }

  error.value = 'Wystąpił nieoczekiwany błąd.'
}

function dateStatus(value: string | null): string {
  if (!value) return 'Brak daty'
  const today = new Date()
  const date = new Date(`${value}T00:00:00`)
  const days = Math.ceil((date.getTime() - today.getTime()) / 86_400_000)
  if (days < 0) return 'Po terminie'
  if (days <= 30) return 'Wkrótce wygasa'
  return 'Ważne'
}

function joinLabels(ids: string[], map: Map<string, string>): string {
  if (!ids.length) return '—'
  return ids.map((id) => map.get(id) ?? id).join(', ')
}

function togglePermission(permission: string): void {
  if (currentPermissions.value.includes(permission)) {
    currentPermissions.value = currentPermissions.value.filter((item) => item !== permission)
  } else {
    currentPermissions.value = [...currentPermissions.value, permission]
  }
}
</script>

<template>
  <div class="app-shell">
    <aside class="sidebar">
      <a
        class="brand"
        href="/"
      >OSK <strong>Panel</strong></a>
      <nav
        class="main-nav"
        aria-label="Główna nawigacja"
      >
        <a href="/kursanci">Kursanci</a>
        <a
          href="/lokalizacje"
          :class="{ active: section === 'locations' }"
        >Lokalizacje</a>
        <a
          href="/pracownicy"
          :class="{ active: section === 'staff' }"
        >Pracownicy</a>
        <a
          href="/pojazdy"
          :class="{ active: section === 'vehicles' }"
        >Pojazdy</a>
      </nav>
      <div class="sidebar-foot">
        Stage 5 · Core v1
      </div>
    </aside>

    <main class="workspace">
      <header class="workspace-header">
        <div>
          <div class="eyebrow">
            PrawkoNaRaz · OSK
          </div>
          <h1>{{ pageTitle }}</h1>
        </div>
        <div class="header-actions">
          <a
            v-if="detailId && section === 'staff'"
            class="button ghost"
            href="/pracownicy"
          >Wróć do listy</a>
          <a
            v-if="detailId && section === 'vehicles'"
            class="button ghost"
            href="/pojazdy"
          >Wróć do listy</a>
        </div>
      </header>

      <div
        v-if="notice"
        class="notice success"
        role="status"
      >
        <span>{{ notice }}</span>
        <button
          type="button"
          aria-label="Zamknij"
          @click="notice = ''"
        >
          ×
        </button>
      </div>
      <div
        v-if="error"
        class="notice error"
        role="alert"
      >
        <span>{{ error }}</span>
        <button
          type="button"
          aria-label="Zamknij"
          @click="error = ''"
        >
          ×
        </button>
      </div>

      <div
        v-if="loading"
        class="loading-card"
      >
        Ładowanie danych…
      </div>

      <template v-else-if="section === 'home'">
        <section class="intro-card">
          <span class="section-kicker">Fundament gotowy</span>
          <h2>Pierwsze moduły operacyjne OSK</h2>
          <p>Lokalizacje, pracownicy i pojazdy korzystają z jednego tenantowego modelu uprawnień, historii i audytu.</p>
          <div class="intro-links">
            <a
              class="button primary"
              href="/lokalizacje"
            >Otwórz lokalizacje</a>
            <a
              class="button ghost"
              href="/pracownicy"
            >Pracownicy</a>
            <a
              class="button ghost"
              href="/pojazdy"
            >Pojazdy</a>
          </div>
        </section>
      </template>

      <template v-else-if="section === 'calendar'">
        <section class="intro-card">
          <span class="section-kicker">Zachowany punkt wejścia</span>
          <h2>Kalendarz jest następnym zależnym modułem</h2>
          <p>
            Kontekst zasobu został zachowany w adresie. Silnik zdarzeń, dostępności i konfliktów nie jest pozorowany
            w tym slice i zostanie podpięty w dedykowanym module Calendar.
          </p>
          <div
            v-if="calendarQuery"
            class="context-chip"
          >
            {{ calendarQuery }}
          </div>
          <div class="intro-links">
            <a
              class="button ghost"
              href="/pracownicy"
            >Pracownicy</a>
            <a
              class="button ghost"
              href="/pojazdy"
            >Pojazdy</a>
            <a
              class="button ghost"
              href="/lokalizacje"
            >Lokalizacje</a>
          </div>
        </section>
      </template>

      <template v-else-if="section === 'locations'">
        <section class="toolbar">
          <div>
            <p class="section-description">
              Miejsca prowadzenia działalności i szkolenia w obrębie tego OSK.
            </p>
          </div>
          <button
            class="button primary"
            type="button"
            @click="openLocationCreate"
          >
            Dodaj lokalizację
          </button>
        </section>

        <section class="table-card">
          <table>
            <thead>
              <tr>
                <th>Rodzaj</th>
                <th>Nazwa</th>
                <th>Adres</th>
                <th>Status</th>
                <th class="actions-column">
                  Akcje
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="item in locations"
                :key="item.id"
                :class="{ archived: item.archived_at }"
              >
                <td>{{ locationTypeMap.get(item.type_code) ?? item.type_code }}</td>
                <td><strong>{{ item.name }}</strong></td>
                <td>{{ item.street_and_number }}, {{ item.postal_code }} {{ item.city_name }}</td>
                <td>
                  <span
                    class="status-pill"
                    :class="{ muted: item.archived_at }"
                  >{{ item.archived_at ? 'Archiwalna' : 'Aktywna' }}</span>
                </td>
                <td>
                  <div class="row-actions">
                    <a
                      class="text-link"
                      :href="`/kalendarz?b=${item.id}`"
                    >Kalendarz</a>
                    <button
                      v-if="!item.archived_at"
                      class="text-button"
                      type="button"
                      @click="openLocationEdit(item)"
                    >
                      Edytuj
                    </button>
                    <button
                      v-if="!item.archived_at"
                      class="text-button danger"
                      type="button"
                      @click="archiveLocation(item)"
                    >
                      Archiwizuj
                    </button>
                    <button
                      v-else
                      class="text-button"
                      type="button"
                      @click="restoreLocation(item)"
                    >
                      Przywróć
                    </button>
                  </div>
                </td>
              </tr>
              <tr v-if="locations.length === 0">
                <td
                  colspan="5"
                  class="empty-cell"
                >
                  Nie dodano jeszcze żadnej lokalizacji.
                </td>
              </tr>
            </tbody>
          </table>
        </section>
      </template>

      <template v-else-if="section === 'staff' && !detailId">
        <section class="toolbar">
          <div class="search-box">
            <label for="staff-search">Szukaj</label>
            <input
              id="staff-search"
              v-model="search"
              type="search"
              placeholder="Imię, nazwisko lub e-mail"
            >
          </div>
          <button
            class="button primary"
            type="button"
            @click="openStaffCreate"
          >
            Dodaj pracownika
          </button>
        </section>

        <section class="table-card">
          <table>
            <thead>
              <tr>
                <th>Pracownik</th>
                <th>E-mail</th>
                <th>Rodzaj</th>
                <th>Dokumenty</th>
                <th>Konto</th>
                <th class="actions-column">
                  Akcje
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="item in filteredStaff"
                :key="item.id"
                :class="{ archived: item.archived_at }"
              >
                <td>
                  <div class="person-cell">
                    <div class="avatar">
                      {{ item.first_name.charAt(0) }}{{ item.last_name.charAt(0) }}
                    </div>
                    <div><strong>{{ item.first_name }} {{ item.last_name }}</strong><small>{{ item.archived_at ? 'Archiwalny profil' : 'Aktywny profil' }}</small></div>
                  </div>
                </td>
                <td>{{ item.email }}</td>
                <td>{{ joinLabels(item.staff_type_codes, staffTypeMap) }}</td>
                <td>
                  <div class="validity-stack">
                    <span>Legitymacja: {{ dateStatus(item.card_valid_until) }}</span>
                    <span>Badania: {{ dateStatus(item.medical_exam_valid_until) }}</span>
                  </div>
                </td>
                <td>{{ item.has_login_account ? 'Powiązane' : 'Brak' }}</td>
                <td>
                  <div class="row-actions">
                    <a
                      class="text-link"
                      :href="`/kalendarz?w=${item.id}`"
                    >Kalendarz</a>
                    <a
                      class="text-link strong"
                      :href="`/pracownicy/${item.id}`"
                    >Zobacz</a>
                  </div>
                </td>
              </tr>
              <tr v-if="filteredStaff.length === 0">
                <td
                  colspan="6"
                  class="empty-cell"
                >
                  Brak pracowników spełniających kryteria.
                </td>
              </tr>
            </tbody>
          </table>
        </section>
      </template>

      <template v-else-if="section === 'staff' && currentStaff">
        <section class="detail-hero">
          <div class="detail-identity">
            <div class="avatar large">
              {{ currentStaff.first_name.charAt(0) }}{{ currentStaff.last_name.charAt(0) }}
            </div>
            <div>
              <div class="detail-title">
                {{ currentStaff.first_name }} {{ currentStaff.last_name }}
              </div>
              <div class="detail-subtitle">
                {{ currentStaff.email }}
              </div>
            </div>
          </div>
          <div class="detail-actions">
            <button
              v-if="!currentStaff.archived_at"
              class="button ghost"
              type="button"
              @click="openStaffEdit(currentStaff)"
            >
              Edytuj dane
            </button>
            <button
              v-if="!currentStaff.archived_at"
              class="button danger-outline"
              type="button"
              @click="archiveStaff"
            >
              Archiwizuj
            </button>
            <button
              v-else
              class="button primary"
              type="button"
              @click="restoreStaff"
            >
              Przywróć profil
            </button>
          </div>
        </section>

        <div class="detail-grid">
          <section class="detail-card">
            <div class="card-heading">
              <div><span class="section-kicker">Dane pracownika</span><h2>Profil</h2></div>
            </div>
            <dl class="details-list">
              <div><dt>Rodzaj</dt><dd>{{ joinLabels(currentStaff.staff_type_codes, staffTypeMap) }}</dd></div>
              <div><dt>Telefon</dt><dd>{{ currentStaff.phone ?? '—' }}</dd></div>
              <div><dt>PESEL</dt><dd>{{ currentStaff.pesel_masked ?? '—' }}</dd></div>
              <div><dt>Numer uprawnień</dt><dd>{{ currentStaff.authorization_number ?? '—' }}</dd></div>
              <div><dt>Kategorie</dt><dd>{{ joinLabels(currentStaff.category_ids, categoryMap) }}</dd></div>
              <div><dt>Lokalizacje</dt><dd>{{ joinLabels(currentStaff.location_ids, locationMap) }}</dd></div>
            </dl>
          </section>

          <section class="detail-card">
            <div class="card-heading">
              <div><span class="section-kicker">Ważności</span><h2>Dokumenty</h2></div>
            </div>
            <div class="validity-cards">
              <div><span>Legitymacja</span><strong>{{ currentStaff.card_valid_until ?? 'Brak daty' }}</strong><small>{{ dateStatus(currentStaff.card_valid_until) }}</small></div>
              <div><span>Badania lekarskie</span><strong>{{ currentStaff.medical_exam_valid_until ?? 'Brak daty' }}</strong><small>{{ dateStatus(currentStaff.medical_exam_valid_until) }}</small></div>
              <div><span>Badania psychologiczne</span><strong>{{ currentStaff.psychological_exam_valid_until ?? 'Brak daty' }}</strong><small>{{ dateStatus(currentStaff.psychological_exam_valid_until) }}</small></div>
            </div>
          </section>
        </div>

        <section class="detail-card">
          <div class="card-heading split">
            <div><span class="section-kicker">Dostęp do panelu</span><h2>Konto i uprawnienia</h2></div>
            <div class="row-actions">
              <button
                v-if="currentStaff.has_login_account"
                class="button ghost"
                type="button"
                @click="openPermissions"
              >
                Uprawnienia
              </button>
              <button
                v-if="currentStaff.has_login_account"
                class="button danger-outline"
                type="button"
                @click="revokePanelAccount"
              >
                Odłącz konto
              </button>
              <button
                v-else-if="!currentStaff.archived_at"
                class="button primary"
                type="button"
                @click="createPanelAccount"
              >
                Utwórz konto do logowania
              </button>
            </div>
          </div>
          <p
            v-if="currentStaff.has_login_account"
            class="muted-copy"
          >
            Profil ma jawne powiązanie z kontem. Typ pracownika nie nadaje uprawnień automatycznie.
          </p>
          <p
            v-else
            class="muted-copy"
          >
            Profil kadrowy może istnieć bez konta do logowania.
          </p>
        </section>

        <CalendarShell :resource-query="`w=${currentStaff.id}`" />
      </template>

      <template v-else-if="section === 'vehicles' && !detailId">
        <section class="toolbar">
          <div class="search-box">
            <label for="vehicle-search">Szukaj</label>
            <input
              id="vehicle-search"
              v-model="search"
              type="search"
              placeholder="Rejestracja, marka lub model"
            >
          </div>
          <button
            class="button primary"
            type="button"
            @click="openVehicleCreate"
          >
            Dodaj pojazd
          </button>
        </section>

        <section class="table-card">
          <table>
            <thead>
              <tr>
                <th>Pojazd</th>
                <th>Marka i model</th>
                <th>Kategorie</th>
                <th>Dokumenty</th>
                <th class="actions-column">
                  Akcje
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="item in filteredVehicles"
                :key="item.id"
                :class="{ archived: item.archived_at }"
              >
                <td><strong>{{ item.registration_number }}</strong><small class="table-subline">{{ item.side_number ?? 'Bez nr bocznego' }}</small></td>
                <td>{{ item.make }} {{ item.model }}</td>
                <td>{{ joinLabels(item.category_ids, categoryMap) }}</td>
                <td>
                  <div class="validity-stack">
                    <span>Przegląd: {{ dateStatus(item.next_inspection_at) }}</span>
                    <span>OC: {{ dateStatus(item.oc_valid_until) }}</span>
                    <span>AC: {{ dateStatus(item.ac_valid_until) }}</span>
                  </div>
                </td>
                <td>
                  <div class="row-actions">
                    <a
                      class="text-link"
                      :href="`/kalendarz?v=${item.id}`"
                    >Kalendarz</a>
                    <a
                      class="text-link strong"
                      :href="`/pojazdy/${item.id}`"
                    >Zobacz</a>
                  </div>
                </td>
              </tr>
              <tr v-if="filteredVehicles.length === 0">
                <td
                  colspan="5"
                  class="empty-cell"
                >
                  Nie dodano jeszcze żadnego pojazdu.
                </td>
              </tr>
            </tbody>
          </table>
        </section>
      </template>

      <template v-else-if="section === 'vehicles' && currentVehicle">
        <section class="detail-hero">
          <div class="detail-identity">
            <div class="vehicle-mark">
              AUTO
            </div>
            <div>
              <div class="detail-title">
                {{ currentVehicle.registration_number }}
              </div>
              <div class="detail-subtitle">
                {{ currentVehicle.make }} {{ currentVehicle.model }}
              </div>
            </div>
          </div>
          <div class="detail-actions">
            <button
              v-if="!currentVehicle.archived_at"
              class="button ghost"
              type="button"
              @click="openVehicleEdit(currentVehicle)"
            >
              Edytuj dane
            </button>
            <button
              v-if="!currentVehicle.archived_at"
              class="button ghost"
              type="button"
              @click="archiveVehicle(false)"
            >
              Archiwizuj
            </button>
            <button
              v-if="!currentVehicle.archived_at"
              class="button danger-outline"
              type="button"
              @click="archiveVehicle(true)"
            >
              Usuń
            </button>
            <button
              v-else
              class="button primary"
              type="button"
              @click="restoreVehicle"
            >
              Przywróć pojazd
            </button>
          </div>
        </section>

        <div class="detail-grid">
          <section class="detail-card">
            <div class="card-heading">
              <div><span class="section-kicker">Pojazd</span><h2>Dane techniczne</h2></div>
            </div>
            <dl class="details-list">
              <div><dt>Marka / model</dt><dd>{{ currentVehicle.make }} {{ currentVehicle.model }}</dd></div>
              <div><dt>Nr rejestracyjny</dt><dd>{{ currentVehicle.registration_number }}</dd></div>
              <div><dt>Nr boczny</dt><dd>{{ currentVehicle.side_number ?? '—' }}</dd></div>
              <div><dt>VIN</dt><dd>{{ currentVehicle.vin ?? '—' }}</dd></div>
              <div><dt>Pojemność</dt><dd>{{ currentVehicle.engine_capacity_cm3 ? `${currentVehicle.engine_capacity_cm3} cm³` : '—' }}</dd></div>
              <div><dt>Rok produkcji</dt><dd>{{ currentVehicle.production_year ?? '—' }}</dd></div>
              <div><dt>Kategorie</dt><dd>{{ joinLabels(currentVehicle.category_ids, categoryMap) }}</dd></div>
              <div><dt>Lokalizacje</dt><dd>{{ joinLabels(currentVehicle.location_ids, locationMap) }}</dd></div>
            </dl>
          </section>

          <section class="detail-card">
            <div class="card-heading">
              <div><span class="section-kicker">Ważności</span><h2>Dokumenty</h2></div>
            </div>
            <div class="validity-cards">
              <div><span>Przegląd techniczny</span><strong>{{ currentVehicle.next_inspection_at ?? 'Brak daty' }}</strong><small>{{ dateStatus(currentVehicle.next_inspection_at) }}</small></div>
              <div><span>Ubezpieczenie OC</span><strong>{{ currentVehicle.oc_valid_until ?? 'Brak daty' }}</strong><small>{{ dateStatus(currentVehicle.oc_valid_until) }}</small></div>
              <div><span>Ubezpieczenie AC</span><strong>{{ currentVehicle.ac_valid_until ?? 'Brak daty' }}</strong><small>{{ dateStatus(currentVehicle.ac_valid_until) }}</small></div>
            </div>
          </section>
        </div>

        <CalendarShell :resource-query="`v=${currentVehicle.id}`" />
      </template>

      <div
        v-if="drawer"
        class="drawer-backdrop"
        @click.self="closeDrawer"
      >
        <section
          class="drawer"
          role="dialog"
          aria-modal="true"
        >
          <header class="drawer-header">
            <div>
              <span class="section-kicker">{{ drawer === 'location' ? 'Lokalizacja' : drawer === 'staff' ? 'Pracownik' : 'Pojazd' }}</span>
              <h2>
                {{ drawer === 'location'
                  ? (locationForm.id ? 'Edytuj lokalizację' : 'Dodaj lokalizację')
                  : drawer === 'staff'
                    ? (staffForm.id ? 'Edytuj pracownika' : 'Dodaj pracownika')
                    : (vehicleForm.id ? `Pojazd: ${vehicleForm.registration_number}` : 'Dodaj pojazd') }}
              </h2>
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

          <form
            v-if="drawer === 'location'"
            class="form-grid"
            @submit.prevent="saveLocation"
          >
            <label>Rodzaj *
              <select
                v-model="locationForm.type_code"
                required
              >
                <option
                  v-for="item in locationTypes"
                  :key="item.code"
                  :value="item.code"
                >{{ item.label }}</option>
              </select>
            </label>
            <label>Nazwa *
              <input
                v-model="locationForm.name"
                required
                maxlength="255"
              >
            </label>
            <label class="full">Ulica i numer *
              <input
                v-model="locationForm.street_and_number"
                required
                maxlength="255"
              >
            </label>
            <label>Kod pocztowy *
              <input
                v-model="locationForm.postal_code"
                required
                inputmode="numeric"
                placeholder="00-000"
              >
            </label>
            <label>Miasto *
              <input
                v-model="locationForm.city_reference"
                required
                maxlength="160"
                list="city-hints"
              >
              <datalist id="city-hints"><option :value="locationForm.city_reference" /></datalist>
              <small>Do czasu wyboru centralnego katalogu miejscowości zapisujemy zweryfikowaną nazwę jako referencję.</small>
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
                Zapisz
              </button>
            </div>
          </form>

          <form
            v-else-if="drawer === 'staff'"
            class="form-grid"
            @submit.prevent="saveStaff"
          >
            <label>E-mail *
              <input
                v-model="staffForm.email"
                required
                type="email"
                maxlength="320"
              >
            </label>
            <label>Imię *
              <input
                v-model="staffForm.first_name"
                required
                maxlength="120"
              >
            </label>
            <label>Nazwisko *
              <input
                v-model="staffForm.last_name"
                required
                maxlength="120"
              >
            </label>
            <fieldset class="full">
              <legend>Rodzaj pracownika *</legend>
              <div class="checkbox-grid">
                <label
                  v-for="item in staffTypes"
                  :key="item.code"
                  class="check"
                >
                  <input
                    v-model="staffForm.staff_type_codes"
                    type="checkbox"
                    :value="item.code"
                  >
                  <span>{{ item.label }}</span>
                </label>
              </div>
            </fieldset>
            <label>PESEL
              <input
                v-model="staffForm.pesel"
                inputmode="numeric"
                maxlength="11"
                :placeholder="staffForm.id ? 'Pozostaw puste, aby nie zmieniać' : ''"
              >
              <small v-if="staffForm.id">Istniejący PESEL nie jest zwracany do formularza.</small>
            </label>
            <label>Telefon
              <input
                v-model="staffForm.phone"
                maxlength="40"
              >
            </label>
            <label>Numer uprawnień
              <input
                v-model="staffForm.authorization_number"
                maxlength="128"
              >
            </label>
            <label>Ważność legitymacji
              <input
                v-model="staffForm.card_valid_until"
                type="date"
              >
            </label>
            <label>Ważność badań lekarskich
              <input
                v-model="staffForm.medical_exam_valid_until"
                type="date"
              >
            </label>
            <label>Ważność badań psychologicznych
              <input
                v-model="staffForm.psychological_exam_valid_until"
                type="date"
              >
            </label>
            <fieldset class="full">
              <legend>Kategorie</legend>
              <div class="checkbox-grid">
                <label
                  v-for="item in categories"
                  :key="item.id"
                  class="check"
                >
                  <input
                    v-model="staffForm.category_ids"
                    type="checkbox"
                    :value="item.id"
                  >
                  <span>{{ item.code }}</span>
                </label>
              </div>
            </fieldset>
            <fieldset class="full">
              <legend>Lokalizacje</legend>
              <div class="checkbox-grid">
                <label
                  v-for="item in locations.filter((location) => !location.archived_at)"
                  :key="item.id"
                  class="check"
                >
                  <input
                    v-model="staffForm.location_ids"
                    type="checkbox"
                    :value="item.id"
                  >
                  <span>{{ item.name }}</span>
                </label>
              </div>
            </fieldset>
            <label class="full file-field">Dodaj zdjęcie (opcjonalnie)
              <input
                type="file"
                accept="image/*"
                @change="handlePhoto"
              >
              <small>{{ pendingPhotoName || 'Bezpieczny transport pliku zostanie podpięty w dedykowanym module UploadsAssets. Sam wybór pliku nie wysyła go teraz.' }}</small>
            </label>
            <label
              v-if="!staffForm.id"
              class="check full"
            >
              <input
                v-model="staffForm.create_login_account"
                type="checkbox"
              >
              <span>Utwórz konto do logowania</span>
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
                :disabled="saving || staffForm.staff_type_codes.length === 0"
              >
                Zapisz
              </button>
            </div>
          </form>

          <form
            v-else
            class="form-grid"
            @submit.prevent="saveVehicle"
          >
            <label>Nr rejestracyjny *
              <input
                v-model="vehicleForm.registration_number"
                required
                maxlength="32"
              >
            </label>
            <label>Numer boczny
              <input
                v-model="vehicleForm.side_number"
                maxlength="64"
              >
            </label>
            <label>Marka *
              <input
                v-model="vehicleForm.make"
                required
                maxlength="120"
              >
            </label>
            <label>Model *
              <input
                v-model="vehicleForm.model"
                required
                maxlength="120"
              >
            </label>
            <label>Rok produkcji
              <input
                v-model="vehicleForm.production_year"
                type="number"
                min="1900"
                max="2100"
              >
            </label>
            <label>Pojemność (w cm³)
              <input
                v-model="vehicleForm.engine_capacity_cm3"
                type="number"
                min="1"
                max="100000"
              >
            </label>
            <label class="full">Numer VIN
              <input
                v-model="vehicleForm.vin"
                maxlength="17"
              >
            </label>
            <label>Następny przegląd
              <input
                v-model="vehicleForm.next_inspection_at"
                type="date"
              >
            </label>
            <label>Ważność OC
              <input
                v-model="vehicleForm.oc_valid_until"
                type="date"
              >
            </label>
            <label>Ważność AC
              <input
                v-model="vehicleForm.ac_valid_until"
                type="date"
              >
            </label>
            <fieldset class="full">
              <legend>Wybierz kategorie obsługiwane przez pojazd</legend>
              <div class="checkbox-grid">
                <label
                  v-for="item in categories"
                  :key="item.id"
                  class="check"
                >
                  <input
                    v-model="vehicleForm.category_ids"
                    type="checkbox"
                    :value="item.id"
                  >
                  <span>{{ item.code }}</span>
                </label>
              </div>
            </fieldset>
            <fieldset class="full">
              <legend>Lokalizacje</legend>
              <div class="checkbox-grid">
                <label
                  v-for="item in locations.filter((location) => !location.archived_at)"
                  :key="item.id"
                  class="check"
                >
                  <input
                    v-model="vehicleForm.location_ids"
                    type="checkbox"
                    :value="item.id"
                  >
                  <span>{{ item.name }}</span>
                </label>
              </div>
            </fieldset>
            <label class="full file-field">Dodaj zdjęcie (opcjonalnie)
              <input
                type="file"
                accept="image/*"
                @change="handlePhoto"
              >
              <small>{{ pendingPhotoName || 'Pole jest zachowane. Transfer pliku zostanie uruchomiony dopiero z bezpiecznym pipeline UploadsAssets.' }}</small>
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
                Zapisz
              </button>
            </div>
          </form>
        </section>
      </div>

      <div
        v-if="permissionsOpen"
        class="drawer-backdrop"
        @click.self="permissionsOpen = false"
      >
        <section
          class="drawer compact"
          role="dialog"
          aria-modal="true"
        >
          <header class="drawer-header">
            <div><span class="section-kicker">Dostęp panelowy</span><h2>Uprawnienia pracownika</h2></div>
            <button
              class="icon-button"
              type="button"
              aria-label="Zamknij"
              @click="permissionsOpen = false"
            >
              ×
            </button>
          </header>
          <p class="muted-copy">
            Uprawnienia są jawne. Rodzaj pracownika nie jest rolą bezpieczeństwa.
          </p>
          <div class="permission-list">
            <label
              v-for="permission in permissionOptions"
              :key="permission"
              class="check"
            >
              <input
                type="checkbox"
                :checked="currentPermissions.includes(permission)"
                @change="togglePermission(permission)"
              >
              <span>{{ permission }}</span>
            </label>
          </div>
          <div class="form-actions">
            <button
              class="button ghost"
              type="button"
              @click="permissionsOpen = false"
            >
              Anuluj
            </button>
            <button
              class="button primary"
              type="button"
              :disabled="saving"
              @click="savePermissions"
            >
              Zapisz uprawnienia
            </button>
          </div>
        </section>
      </div>
    </main>
  </div>
</template>

