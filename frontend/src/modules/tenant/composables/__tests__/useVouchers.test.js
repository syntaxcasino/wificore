import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

const notify = {
  success: vi.fn(),
  error: vi.fn(),
}

vi.mock('@/modules/common/services/api/axios', () => ({
  default: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    user: { tenant_id: 'tenant-1' },
    tenantId: 'tenant-1',
  }),
}))

vi.mock('@/stores/eventDeduplication', () => ({
  useEventDeduplicationStore: () => ({
    tryProcess: vi.fn(() => true),
  }),
}))

vi.mock('@/stores/notifications', () => ({
  useNotificationStore: () => notify,
}))

vi.mock('@/modules/common/composables/performance/useViewCache', () => ({
  readSnapshot: vi.fn(() => null),
  scheduleAfterPaint: vi.fn((cb) => cb()),
  writeSnapshot: vi.fn(),
}))

import { useVouchers } from '../useVouchers'

describe('useVouchers copy helper', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    Object.defineProperty(globalThis.navigator, 'clipboard', {
      value: { writeText: vi.fn() },
      configurable: true,
    })
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('shows a success toast when a voucher code is copied', async () => {
    const { copyVoucherCode } = useVouchers()
    await expect(copyVoucherCode('ABC-123')).resolves.toBe(true)

    expect(navigator.clipboard.writeText).toHaveBeenCalledWith('ABC-123')
    expect(notify.success).toHaveBeenCalledWith('Voucher Copied', 'Voucher ABC-123 copied to clipboard')
    expect(notify.error).not.toHaveBeenCalled()
  })

  it('shows an error toast when copy fails', async () => {
    navigator.clipboard.writeText.mockRejectedValueOnce(new Error('denied'))

    const { copyVoucherCode } = useVouchers()
    await expect(copyVoucherCode('ABC-123')).resolves.toBe(false)

    expect(notify.error).toHaveBeenCalledWith('Copy Failed', 'Failed to copy voucher code')
    expect(notify.success).not.toHaveBeenCalled()
  })
})
