<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { ApiError, api } from '../ResourcesCore/api'

type DocumentType = 'training_record_card' | 'theory_delivery_journal'
type DocumentMode = 'paper' | 'electronic'
type FreshnessState = 'fresh' | 'regeneration_required'
type DeliveryEventType = 'printed' | 'signed_scan_attached' | 'electronic_presented'
type EventType = 'generated' | 'approved' | DeliveryEventType | 'regeneration_detected'

type CourseOption = {
  id: string
  training_type: 'basic' | 'supplementary'
  driving_category_code: string
  cancelled_at: string | null
  version: number
}

type TemplateBinding = {
  id: string
  document_type: DocumentType
  template_version: string
  renderer_version: string
  template_content_hash: string
}

type TrainingTotals = {
  osk_theory_minutes: number
  osk_practical_minutes: number
  external_theory_minutes: number
  external_practical_minutes: number
  combined_theory_minutes: number
  combined_practical_minutes: number
}

type FormalDocument = {
  id: string
  course_enrollment_id: string
  document_type: DocumentType
  revision: number
  document_mode_snapshot: DocumentMode
  template_id: string
  template_version: string
  renderer_version: string
  template_content_hash: string
  course_version: number
  requirements_revision: number
  evidence_bundle_hash: string
  content_hash: string
  approved_by_user_id: string | null
  approved_at: string | null
  generated_at: string
  created_at: string
}

type FormalPreview = {
  course_enrollment_id: string
  document_type: DocumentType
  document_mode: DocumentMode
  course_version: number
  requirements_revision: number
  evidence_bundle_hash: string
  template: TemplateBinding
  totals: TrainingTotals
  latest_document: FormalDocument | null
  freshness: FreshnessState
}

type FreshnessDocument = {
  document_type: DocumentType
  freshness: FreshnessState
  current_evidence_bundle_hash: string
  latest_document_id: string | null
  latest_revision: number | null
  latest_evidence_bundle_hash: string | null
}

type FreshnessProjection = {
  course_enrollment_id: string
  course_version: number
  requirements_revision: number
  documents: FreshnessDocument[]
}

type FormalEvent = {
  id: string
  formal_training_document_id: string
  event_type: EventType
  actor_user_id: string | null
  reason: string | null
  optional_asset_id: string | null
  occurred_at: string
  created_at: string
}

type UploadPresign = {
  upload_id: string
  upload_url: string
  expires_at: string
}

type FileAsset = {
  id: string
  purpose: string
  media_type: string
  size_bytes: number
  status: string
}

const props = defineProps<{
  courses: CourseOption[]
  archived: boolean
}>()

const documentTypes: Array<{ value: DocumentType; label: string; description: string }> = [
  {
    value: 'training_record_card',
    label: 'Karta szkolenia',
    description: 'Bieżący zapis formalnego przebiegu kursu oparty na danych źródłowych.',
  },
  {
    value: 'theory_delivery_journal',
    label: 'Dziennik realizacji teorii',
    description: 'Ewidencja realizacji części teoretycznej z aktualnych danych szkolenia.',
  },
]

const selectedCourseId = ref('')
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const notice = ref('')
const documents = ref<FormalDocument[]>([])
const freshness = ref<FreshnessProjection | null>(null)
const previews = ref<Partial<Record<DocumentType, FormalPreview>>>({})
const historyDocument = ref<FormalDocument | null>(null)
const historyEvents = ref<FormalEvent[]>([])
const historyLoading = ref(false)
const orphanReadyAsset = ref<{ assetId: string; documentId: string } | null>(null)

const selectedCourse = computed(
  () => props.courses.find((course) => course.id === selectedCourseId.value) ?? null,
)
const selectedCourseBlocked = computed(
  () => props.archived || Boolean(selectedCourse.value?.cancelled_at),
)
const courseSignature = computed(
  () => props.courses.map((course) => `${course.id}:${course.version}:${course.cancelled_at ?? ''}`).join('|'),
)

watch(courseSignature, async () => {
  ensureCourseSelection()
  if (selectedCourseId.value) await refresh()
}, { immediate: true })

function ensureCourseSelection(): void {
  if (props.courses.some((course) => course.id === selectedCourseId.value)) return
  selectedCourseId.value = props.courses.find((course) => !course.cancelled_at)?.id
    ?? props.courses[0]?.id
    ?? ''
}

async function refresh(): Promise<void> {
  if (!selectedCourseId.value) {
    documents.value = []
    freshness.value = null
    previews.value = {}
    return
  }

  loading.value = true
  error.value = ''
  try {
    const courseId = selectedCourseId.value
    const [listResult, freshnessResult, cardPreview, journalPreview] = await Promise.all([
      api<FormalDocument[]>(`/api/v1/course-enrollments/${courseId}/formal-documents`),
      api<FreshnessProjection>(`/api/v1/course-enrollments/${courseId}/formal-documents/freshness`),
      api<FormalPreview>(
        `/api/v1/course-enrollments/${courseId}/formal-documents/preview?document_type=training_record_card`,
      ),
      api<FormalPreview>(
        `/api/v1/course-enrollments/${courseId}/formal-documents/preview?document_type=theory_delivery_journal`,
      ),
    ])

    if (courseId !== selectedCourseId.value) return

    documents.value = listResult.data
    freshness.value = freshnessResult.data
    previews.value = {
      training_record_card: cardPreview.data,
      theory_delivery_journal: journalPreview.data,
    }

    if (historyDocument.value) {
      const current = documents.value.find((document) => document.id === historyDocument.value?.id)
      if (!current) {
        historyDocument.value = null
        historyEvents.value = []
      }
    }
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    loading.value = false
  }
}

function previewFor(type: DocumentType): FormalPreview | null {
  return previews.value[type] ?? null
}

function latestFor(type: DocumentType): FormalDocument | null {
  return documents.value.find((document) => document.document_type === type) ?? previewFor(type)?.latest_document ?? null
}

function freshnessFor(type: DocumentType): FreshnessState {
  return freshness.value?.documents.find((item) => item.document_type === type)?.freshness
    ?? previewFor(type)?.freshness
    ?? 'regeneration_required'
}

function revisionsFor(type: DocumentType): FormalDocument[] {
  return documents.value.filter((document) => document.document_type === type)
}

function canDeliver(type: DocumentType): boolean {
  return !selectedCourseBlocked.value
    && freshnessFor(type) === 'fresh'
    && latestFor(type) !== null
}

async function approve(type: DocumentType): Promise<void> {
  const preview = previewFor(type)
  if (!preview || selectedCourseBlocked.value) return
  if (preview.freshness === 'fresh' && preview.latest_document) {
    notice.value = 'Aktualna rewizja jest już zgodna z bieżącymi danymi źródłowymi.'
    return
  }

  if (!window.confirm('Zatwierdzić bieżące dane źródłowe i utworzyć niezmienną rewizję dokumentu?')) return

  saving.value = true
  error.value = ''
  notice.value = ''
  try {
    await api<FormalDocument>(`/api/v1/course-enrollments/${preview.course_enrollment_id}/formal-documents`, {
      method: 'POST',
      idempotent: true,
      headers: { 'If-Match': `"v${preview.course_version}"` },
      body: JSON.stringify({
        document_type: type,
        requirements_revision: preview.requirements_revision,
        evidence_bundle_hash: preview.evidence_bundle_hash,
        template_id: preview.template.id,
        template_version: preview.template.template_version,
        renderer_version: preview.template.renderer_version,
        template_content_hash: preview.template.template_content_hash,
      }),
    })
    notice.value = 'Dokument został zatwierdzony i wygenerowany z dokładnie sprawdzonego zestawu danych.'
    await refresh()
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    saving.value = false
  }
}

async function recordDelivery(document: FormalDocument | null, eventType: DeliveryEventType): Promise<void> {
  if (!document || !canDeliver(document.document_type)) return

  const confirmation = eventType === 'printed'
    ? 'Potwierdzić, że ta dokładna rewizja dokumentu została wydrukowana?'
    : 'Potwierdzić przedstawienie tej dokładnej rewizji w formie elektronicznej?'
  if (!window.confirm(confirmation)) return

  saving.value = true
  error.value = ''
  notice.value = ''
  try {
    await api<FormalEvent>(`/api/v1/formal-training-documents/${document.id}/delivery-events`, {
      method: 'POST',
      idempotent: true,
      body: JSON.stringify({ event_type: eventType }),
    })
    notice.value = eventType === 'printed'
      ? 'Wydruk został zapisany w historii tej rewizji.'
      : 'Przedstawienie elektroniczne zostało zapisane w historii tej rewizji.'
    await refreshHistoryIfOpen(document.id)
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    saving.value = false
  }
}

async function uploadSignedScan(document: FormalDocument | null, event: Event): Promise<void> {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file || !document || !canDeliver(document.document_type)) return

  const declaredMime = file.type || (file.name.toLowerCase().endsWith('.pdf') ? 'application/pdf' : '')
  if (declaredMime !== 'application/pdf') {
    error.value = 'Podpisany skan musi być plikiem PDF.'
    return
  }
  if (file.size < 1 || file.size > 25 * 1024 * 1024) {
    error.value = 'Podpisany skan PDF musi mieć maksymalnie 25 MB.'
    return
  }

  saving.value = true
  error.value = ''
  notice.value = ''
  orphanReadyAsset.value = null

  try {
    const sha256 = await fileSha256(file)
    const presign = await api<UploadPresign>('/api/v1/uploads/presign', {
      method: 'POST',
      body: JSON.stringify({
        purpose: 'formal_training_signed_scan',
        filename: file.name,
        declared_mime: declaredMime,
        size_bytes: file.size,
        sha256,
        parent_type: 'formal_training_document',
        parent_id: document.id,
      }),
    })

    const uploadResponse = await fetch(presign.data.upload_url, {
      method: 'PUT',
      body: file,
    })
    if (!uploadResponse.ok) {
      throw new Error('Nie udało się przesłać skanu do prywatnego magazynu plików.')
    }

    const completed = await api<FileAsset>(`/api/v1/uploads/${presign.data.upload_id}/complete`, {
      method: 'POST',
      idempotent: true,
      body: JSON.stringify({ sha256 }),
    })

    try {
      await attachReadyAsset(document.id, completed.data.id)
    } catch (caught: unknown) {
      orphanReadyAsset.value = { assetId: completed.data.id, documentId: document.id }
      throw caught
    }

    notice.value = 'Podpisany skan został zweryfikowany i dołączony do historii tej rewizji.'
    await refreshHistoryIfOpen(document.id)
  } catch (caught: unknown) {
    handleError(caught)
    if (orphanReadyAsset.value) {
      error.value += ' Plik jest już bezpiecznie zapisany jako gotowy; możesz ponowić samo podpięcie.'
    }
  } finally {
    saving.value = false
  }
}

async function retryReadyAssetAttachment(): Promise<void> {
  const pending = orphanReadyAsset.value
  if (!pending) return

  saving.value = true
  error.value = ''
  try {
    await attachReadyAsset(pending.documentId, pending.assetId)
    orphanReadyAsset.value = null
    notice.value = 'Gotowy skan został podpięty do dokumentu.'
    await refreshHistoryIfOpen(pending.documentId)
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    saving.value = false
  }
}

async function attachReadyAsset(documentId: string, assetId: string): Promise<void> {
  await api<FormalEvent>(`/api/v1/formal-training-documents/${documentId}/delivery-events`, {
    method: 'POST',
    idempotent: true,
    body: JSON.stringify({
      event_type: 'signed_scan_attached',
      optional_asset_id: assetId,
    }),
  })
}

async function openHistory(document: FormalDocument | null): Promise<void> {
  if (!document) return
  historyDocument.value = document
  historyEvents.value = []
  historyLoading.value = true
  error.value = ''
  try {
    historyEvents.value = (
      await api<FormalEvent[]>(`/api/v1/formal-training-documents/${document.id}/events`)
    ).data
  } catch (caught: unknown) {
    handleError(caught)
  } finally {
    historyLoading.value = false
  }
}

function closeHistory(): void {
  historyDocument.value = null
  historyEvents.value = []
}

async function refreshHistoryIfOpen(documentId: string): Promise<void> {
  if (!historyDocument.value || historyDocument.value.id !== documentId) return
  await openHistory(historyDocument.value)
}

async function downloadPdf(document: FormalDocument | null): Promise<void> {
  if (!document) return
  error.value = ''
  try {
    const response = await fetch(`/api/v1/formal-training-documents/${document.id}/file`, {
      credentials: 'same-origin',
      headers: { Accept: 'application/pdf' },
    })
    if (!response.ok) {
      throw new Error(await safeResponseMessage(response))
    }

    const blob = await response.blob()
    const href = URL.createObjectURL(blob)
    const anchor = window.document.createElement('a')
    anchor.href = href
    anchor.download = `${document.document_type}-r${document.revision}.pdf`
    window.document.body.appendChild(anchor)
    anchor.click()
    anchor.remove()
    URL.revokeObjectURL(href)
  } catch (caught: unknown) {
    handleError(caught)
  }
}

async function fileSha256(file: File): Promise<string> {
  if (!globalThis.crypto?.subtle) {
    throw new Error('Ta przeglądarka nie obsługuje wymaganej weryfikacji SHA-256 pliku.')
  }

  const digest = await globalThis.crypto.subtle.digest('SHA-256', await file.arrayBuffer())
  return Array.from(new Uint8Array(digest))
    .map((byte) => byte.toString(16).padStart(2, '0'))
    .join('')
}

async function safeResponseMessage(response: Response): Promise<string> {
  try {
    const payload = await response.json() as { error?: { message?: string } }
    if (payload.error?.message) return payload.error.message
  } catch {
    // Keep the safe generic fallback.
  }

  return 'Nie udało się pobrać dokumentu.'
}

function handleError(caught: unknown): void {
  if (caught instanceof ApiError) {
    error.value = Object.values(caught.fields).flat().join(' ') || caught.message
  } else if (caught instanceof Error) {
    error.value = caught.message
  } else {
    error.value = 'Nie udało się wykonać operacji na dokumentach.'
  }
}

function documentLabel(type: DocumentType): string {
  return documentTypes.find((item) => item.value === type)?.label ?? type
}

function modeLabel(mode: DocumentMode): string {
  return mode === 'paper' ? 'Papierowy' : 'Elektroniczny'
}

function freshnessLabel(state: FreshnessState): string {
  return state === 'fresh' ? 'Aktualny' : 'Wymaga regeneracji'
}

function eventLabel(type: EventType): string {
  return {
    generated: 'Wygenerowano',
    approved: 'Zatwierdzono',
    printed: 'Wydrukowano',
    signed_scan_attached: 'Dołączono podpisany skan',
    electronic_presented: 'Przedstawiono elektronicznie',
    regeneration_detected: 'Wykryto potrzebę regeneracji',
  }[type]
}

function courseLabel(course: CourseOption): string {
  const training = course.training_type === 'basic' ? 'podstawowe' : 'uzupełniające'
  return `${course.driving_category_code} · szkolenie ${training}`
}

function formatDateTime(value: string | null): string {
  if (!value) return '—'
  return new Intl.DateTimeFormat('pl-PL', {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(value))
}

function shortHash(value: string): string {
  return `${value.slice(0, 8)}…${value.slice(-8)}`
}
</script>

<template>
  <section class="detail-card formal-documents-panel">
    <div class="card-heading split">
      <div>
        <span class="section-kicker">Dokumentacja kursu</span>
        <h2>Dokumenty formalne</h2>
      </div>
      <div class="formal-doc-toolbar">
        <label v-if="courses.length > 0">
          Kurs
          <select
            v-model="selectedCourseId"
            :disabled="loading || saving"
            @change="refresh"
          >
            <option
              v-for="course in courses"
              :key="course.id"
              :value="course.id"
            >
              {{ courseLabel(course) }}{{ course.cancelled_at ? ' · anulowany' : '' }}
            </option>
          </select>
        </label>
        <button
          class="button ghost"
          type="button"
          :disabled="!selectedCourseId || loading || saving"
          @click="refresh"
        >
          Odśwież status
        </button>
      </div>
    </div>

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
      v-if="orphanReadyAsset"
      class="formal-doc-recovery"
    >
      <div>
        <strong>Skan jest już zweryfikowany, ale nie został jeszcze podpięty.</strong>
        <span>Ponów tylko zapis zdarzenia — nie przesyłaj pliku drugi raz.</span>
      </div>
      <button
        class="button ghost"
        type="button"
        :disabled="saving"
        @click="retryReadyAssetAttachment"
      >
        Ponów podpięcie
      </button>
    </div>

    <div
      v-if="courses.length === 0"
      class="empty-inline"
    >
      <strong>Brak kursu do dokumentacji.</strong>
      <span>Dokument formalny jest zawsze związany z konkretnym kursem kursanta.</span>
    </div>

    <div
      v-else-if="loading"
      class="loading-card formal-doc-loading"
    >
      Sprawdzanie bieżących danych i historii dokumentów…
    </div>

    <div
      v-else
      class="formal-doc-grid"
    >
      <article
        v-for="definition in documentTypes"
        :key="definition.value"
        class="formal-doc-card"
      >
        <div class="formal-doc-card-head">
          <div>
            <span>{{ definition.description }}</span>
            <h3>{{ definition.label }}</h3>
          </div>
          <span
            class="status-pill"
            :class="{ muted: freshnessFor(definition.value) !== 'fresh' }"
          >
            {{ freshnessLabel(freshnessFor(definition.value)) }}
          </span>
        </div>

        <template v-if="previewFor(definition.value)">
          <div class="formal-doc-facts">
            <div>
              <span>Tryb</span>
              <strong>{{ modeLabel(previewFor(definition.value)?.document_mode) }}</strong>
            </div>
            <div>
              <span>Teoria łącznie</span>
              <strong>{{ previewFor(definition.value)?.totals.combined_theory_minutes ?? 0 }} min</strong>
            </div>
            <div>
              <span>Praktyka łącznie</span>
              <strong>{{ previewFor(definition.value)?.totals.combined_practical_minutes ?? 0 }} min</strong>
            </div>
          </div>

          <div
            v-if="freshnessFor(definition.value) !== 'fresh'"
            class="formal-doc-warning"
          >
            Bieżące dane źródłowe różnią się od ostatniej rewizji. Stary dokument pozostaje w historii,
            ale przed wydrukiem, skanem lub przedstawieniem elektronicznym trzeba zatwierdzić nową rewizję.
          </div>

          <div
            v-if="latestFor(definition.value)"
            class="formal-doc-current"
          >
            <div>
              <span>Ostatnia rewizja</span>
              <strong>R{{ latestFor(definition.value)?.revision }}</strong>
            </div>
            <div>
              <span>Zatwierdzono</span>
              <strong>{{ formatDateTime(latestFor(definition.value)?.approved_at ?? null) }}</strong>
            </div>
            <div class="formal-doc-hash">
              <span>Hash danych</span>
              <strong>{{ shortHash(latestFor(definition.value)?.evidence_bundle_hash ?? '') }}</strong>
            </div>
          </div>

          <div class="formal-doc-actions">
            <button
              v-if="freshnessFor(definition.value) !== 'fresh'"
              class="button primary"
              type="button"
              :disabled="selectedCourseBlocked || saving"
              @click="approve(definition.value)"
            >
              {{ latestFor(definition.value) ? 'Zatwierdź nową rewizję' : 'Zatwierdź i wygeneruj' }}
            </button>

            <button
              v-if="latestFor(definition.value)"
              class="button ghost"
              type="button"
              :disabled="saving"
              @click="downloadPdf(latestFor(definition.value))"
            >
              Pobierz PDF
            </button>

            <button
              v-if="latestFor(definition.value)"
              class="button ghost"
              type="button"
              :disabled="saving"
              @click="openHistory(latestFor(definition.value))"
            >
              Historia
            </button>

            <button
              v-if="latestFor(definition.value)?.document_mode_snapshot === 'paper'"
              class="button ghost"
              type="button"
              :disabled="!canDeliver(definition.value) || saving"
              @click="recordDelivery(latestFor(definition.value), 'printed')"
            >
              Potwierdź wydruk
            </button>

            <label
              v-if="latestFor(definition.value)?.document_mode_snapshot === 'paper'"
              class="button ghost formal-doc-file-button"
              :class="{ disabled: !canDeliver(definition.value) || saving }"
            >
              Dodaj podpisany skan
              <input
                type="file"
                accept="application/pdf,.pdf"
                :disabled="!canDeliver(definition.value) || saving"
                @change="uploadSignedScan(latestFor(definition.value), $event)"
              >
            </label>

            <button
              v-if="latestFor(definition.value)?.document_mode_snapshot === 'electronic'"
              class="button ghost"
              type="button"
              :disabled="!canDeliver(definition.value) || saving"
              @click="recordDelivery(latestFor(definition.value), 'electronic_presented')"
            >
              Oznacz jako przedstawiony
            </button>
          </div>

          <p
            v-if="latestFor(definition.value)?.document_mode_snapshot === 'paper'"
            class="formal-doc-note"
          >
            Podpis odręczny odbywa się poza systemem. Podpisany skan jest historycznym załącznikiem do
            konkretnej, niezmiennej rewizji — nie zastępuje kanonicznego PDF.
          </p>
          <p
            v-if="latestFor(definition.value)?.document_mode_snapshot === 'electronic'"
            class="formal-doc-note"
          >
            „Przedstawiono elektronicznie” zapisuje wyłącznie fakt dostarczenia tej rewizji. Nie oznacza
            podpisu kwalifikowanego ani żadnego konkretnego ustawowego mechanizmu e-podpisu.
          </p>

          <div
            v-if="revisionsFor(definition.value).length > 1"
            class="formal-doc-revisions"
          >
            <strong>Poprzednie rewizje</strong>
            <div
              v-for="document in revisionsFor(definition.value).slice(1)"
              :key="document.id"
              class="formal-doc-revision-row"
            >
              <div>
                <b>R{{ document.revision }}</b>
                <span>{{ formatDateTime(document.approved_at) }} · {{ modeLabel(document.document_mode_snapshot) }}</span>
              </div>
              <div class="row-actions">
                <button
                  class="text-button"
                  type="button"
                  @click="downloadPdf(document)"
                >
                  Pobierz
                </button>
                <button
                  class="text-button"
                  type="button"
                  @click="openHistory(document)"
                >
                  Historia
                </button>
              </div>
            </div>
          </div>
        </template>
      </article>
    </div>

    <div
      v-if="historyDocument"
      class="drawer-backdrop finance-drawer-backdrop"
      @click.self="closeHistory"
    >
      <aside class="drawer compact formal-doc-drawer">
        <div class="drawer-header">
          <div>
            <span class="section-kicker">Historia dokumentu</span>
            <h2>{{ documentLabel(historyDocument.document_type) }} · R{{ historyDocument.revision }}</h2>
          </div>
          <button
            class="icon-button"
            type="button"
            aria-label="Zamknij"
            @click="closeHistory"
          >
            ×
          </button>
        </div>

        <div
          v-if="historyLoading"
          class="loading-card"
        >
          Ładowanie historii…
        </div>
        <div
          v-else-if="historyEvents.length === 0"
          class="empty-inline"
        >
          Brak zapisanych zdarzeń dla tej rewizji.
        </div>
        <div
          v-else
          class="formal-doc-event-list"
        >
          <article
            v-for="item in historyEvents"
            :key="item.id"
            class="formal-doc-event-row"
          >
            <div>
              <strong>{{ eventLabel(item.event_type) }}</strong>
              <span>{{ formatDateTime(item.occurred_at) }}</span>
            </div>
            <small v-if="item.reason">{{ item.reason }}</small>
          </article>
        </div>
      </aside>
    </div>
  </section>
</template>
