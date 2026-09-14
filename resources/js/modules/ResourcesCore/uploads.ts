import { api } from './api'

export type ResourcePhotoPurpose = 'staff_photo' | 'vehicle_photo'
export type ResourcePhotoParentType = 'staff_profile' | 'vehicle'

type UploadPresign = {
  upload_id: string
  upload_url: string
  expires_at: string
}

export type ReadyFileAsset = {
  id: string
  purpose: string
  media_type: string
  size_bytes: number
  status: string
}

const PHOTO_MAX_BYTES = 10 * 1024 * 1024
const PHOTO_MIME_TYPES = new Set(['image/jpeg', 'image/png', 'image/webp'])

export async function uploadResourcePhoto(
  file: File,
  purpose: ResourcePhotoPurpose,
  parentType: ResourcePhotoParentType,
  parentId: string,
): Promise<ReadyFileAsset> {
  const declaredMime = photoMime(file)

  if (file.size < 1 || file.size > PHOTO_MAX_BYTES) {
    throw new Error('Zdjęcie musi mieć maksymalnie 10 MB.')
  }

  const sha256 = await fileSha256(file)
  const presign = await api<UploadPresign>('/api/v1/uploads/presign', {
    method: 'POST',
    body: JSON.stringify({
      purpose,
      filename: file.name,
      declared_mime: declaredMime,
      size_bytes: file.size,
      sha256,
      parent_type: parentType,
      parent_id: parentId,
    }),
  })

  const uploadResponse = await fetch(presign.data.upload_url, {
    method: 'PUT',
    body: file,
  })
  if (!uploadResponse.ok) {
    throw new Error('Nie udało się przesłać zdjęcia do prywatnego magazynu plików.')
  }

  const completed = await api<ReadyFileAsset>(`/api/v1/uploads/${presign.data.upload_id}/complete`, {
    method: 'POST',
    idempotent: true,
    body: JSON.stringify({ sha256 }),
  })

  if (completed.data.status !== 'ready' || completed.data.id !== presign.data.upload_id) {
    throw new Error('Zdjęcie nie przeszło końcowej weryfikacji bezpieczeństwa.')
  }

  return completed.data
}

function photoMime(file: File): string {
  let mime = file.type.trim().toLowerCase()
  if (!mime) {
    const name = file.name.toLowerCase()
    if (name.endsWith('.jpg') || name.endsWith('.jpeg')) mime = 'image/jpeg'
    if (name.endsWith('.png')) mime = 'image/png'
    if (name.endsWith('.webp')) mime = 'image/webp'
  }

  if (!PHOTO_MIME_TYPES.has(mime)) {
    throw new Error('Zdjęcie musi być plikiem JPG, PNG lub WEBP.')
  }

  return mime
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
