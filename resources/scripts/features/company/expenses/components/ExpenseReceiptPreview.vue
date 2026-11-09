<template>
  <BaseCard container-class="p-4 md:p-5">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <div class="min-w-0">
        <h2 class="text-base font-semibold text-heading">
          {{ $t('expenses.receipt') }}
        </h2>
        <p class="truncate text-xs text-muted">{{ meta.file_name }}</p>
      </div>

      <div class="flex gap-2">
        <BaseButton
          variant="white"
          size="sm"
          :loading="isOpening"
          @click="openReceipt"
        >
          <template #left="slotProps">
            <BaseIcon name="ArrowTopRightOnSquareIcon" :class="slotProps.class" />
          </template>
          {{ $t('expenses.open_receipt') }}
        </BaseButton>
        <BaseButton
          variant="white"
          size="sm"
          :loading="isDownloading"
          @click="downloadReceipt"
        >
          <template #left="slotProps">
            <BaseIcon name="ArrowDownTrayIcon" :class="slotProps.class" />
          </template>
          {{ $t('expenses.download_receipt') }}
        </BaseButton>
      </div>
    </div>

    <!-- A PDF in the same viewer an invoice is shown in -->
    <BasePdfPreview
      v-if="kind === 'pdf'"
      :src="viewPath"
      :title="meta.file_name"
    />

    <!-- An image, fetched through the API client so a bearer token works -->
    <div
      v-else-if="kind === 'image'"
      class="flex min-h-64 items-center justify-center overflow-hidden rounded-lg border border-line-light bg-surface-secondary"
    >
      <BaseSpinner v-if="imageStatus === 'loading'" class="h-7 w-7 text-subtle" />
      <p v-else-if="imageStatus === 'error'" class="p-6 text-sm text-muted">
        {{ $t('expenses.receipt_unavailable') }}
      </p>
      <button
        v-else-if="imageUrl"
        type="button"
        class="block w-full cursor-zoom-in"
        :aria-label="$t('expenses.open_receipt')"
        @click="openReceipt"
      >
        <img
          :src="imageUrl"
          :alt="$t('expenses.receipt_image_alt', { name: meta.file_name })"
          class="mx-auto max-h-[78vh] w-auto object-contain"
        />
      </button>
    </div>

    <!-- Anything a browser cannot show in place -->
    <div
      v-else
      class="flex items-center gap-4 rounded-lg border border-line-light bg-surface-secondary p-4"
    >
      <span
        class="flex h-12 w-10 shrink-0 items-center justify-center rounded-md border border-line-light bg-surface"
        aria-hidden="true"
      >
        <BaseIcon name="DocumentIcon" class="h-5 w-5 text-subtle" />
      </span>
      <div class="min-w-0">
        <p class="truncate text-sm font-medium text-heading">{{ meta.file_name }}</p>
        <p class="text-xs text-muted">{{ $t('expenses.receipt_no_preview') }}</p>
      </div>
    </div>
  </BaseCard>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useNotificationStore } from '@/scripts/stores/notification.store'
import {
  downloadDocument,
  fetchDocumentBlob,
  openDocument,
  previewUrlFor,
  revokePreviewUrl,
} from '@/scripts/utils/documents'
import type { ReceiptMeta } from '@/scripts/types/domain/expense'

/**
 * An expense's receipt, shown where it is rather than saved first: a PDF in
 * the document viewer, an image inline, anything else as a file to open.
 */
interface Props {
  expenseId: number
  meta: ReceiptMeta
}

const props = defineProps<Props>()

const { t } = useI18n()
const notificationStore = useNotificationStore()

const isOpening = ref<boolean>(false)
const isDownloading = ref<boolean>(false)
const imageUrl = ref<string | null>(null)
const imageStatus = ref<'loading' | 'ready' | 'error'>('loading')

const kind = computed<'pdf' | 'image' | 'file'>(() => {
  const mime = props.meta.mime_type ?? ''

  if (mime === 'application/pdf') {
    return 'pdf'
  }

  return mime.startsWith('image/') ? 'image' : 'file'
})

// The media id moves when the receipt is replaced, so a cached copy of the
// old file is never shown under the new one
const viewPath = computed<string>(
  () => `/reports/expenses/${props.expenseId}/receipt?v=${props.meta.uuid}`,
)

async function loadImage(): Promise<void> {
  revokePreviewUrl(imageUrl.value)
  imageUrl.value = null
  imageStatus.value = 'loading'

  try {
    const fetched = await fetchDocumentBlob(viewPath.value)
    imageUrl.value = previewUrlFor(fetched.blob)
    imageStatus.value = 'ready'
  } catch {
    imageStatus.value = 'error'
  }
}

async function openReceipt(): Promise<void> {
  isOpening.value = true

  try {
    const fetched = await fetchDocumentBlob(viewPath.value, {}, props.meta.file_name)
    await openDocument(fetched.blob, props.meta.file_name)
  } catch {
    notificationStore.showNotification({
      type: 'error',
      message: t('expenses.receipt_unavailable'),
    })
  } finally {
    isOpening.value = false
  }
}

async function downloadReceipt(): Promise<void> {
  isDownloading.value = true

  try {
    await downloadDocument(
      `/reports/expenses/${props.expenseId}/download-receipt`,
      {},
      props.meta.file_name,
    )
  } catch {
    notificationStore.showNotification({
      type: 'error',
      message: t('pdf.download_failed'),
    })
  } finally {
    isDownloading.value = false
  }
}

watch(
  () => [kind.value, viewPath.value],
  () => {
    if (kind.value === 'image') {
      void loadImage()
    }
  },
  { immediate: true },
)

onBeforeUnmount(() => revokePreviewUrl(imageUrl.value))
</script>
