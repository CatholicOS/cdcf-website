import { describe, expect, it } from 'vitest'
import {
  inboxActorKey,
  inboxHref,
  inboxMessageKey,
  inboxTitle,
  relativeTime,
} from './review-inbox'
import type { InboxItem } from './review-types'

const item = (over: Partial<InboxItem> = {}): InboxItem => ({
  event_id: 12,
  type: 'discussion_posted',
  kind: 'submission',
  project: { id: 40, title: 'Liturgy Helper' },
  actor: { id: 8, name: 'Fr. A' },
  entry_id: 5,
  data: {},
  created_at: '2026-10-16 09:00:00.000000',
  read: false,
  to_you: false,
  ...over,
})

describe('inboxHref', () => {
  it('links to the entry when there is one', () => {
    expect(inboxHref(item())).toBe('/review/40#entry-5')
    expect(inboxHref(item({ entry_id: null }))).toBe('/review/40')
  })
})

describe('inboxMessageKey', () => {
  it('words a received referral as a referral', () => {
    const base = { type: 'submission_received' } as const
    expect(inboxMessageKey(item({ ...base, kind: 'referral' }))).toBe(
      'referral_received'
    )
    expect(inboxMessageKey(item({ ...base, kind: 'submission' }))).toBe(
      'submission_received'
    )
  })

  it('uses the event type', () => {
    expect(inboxMessageKey(item())).toBe('discussion_posted')
  })
  it('distinguishes a reply to your review', () => {
    expect(inboxMessageKey(item({ type: 'reply_posted', to_you: true }))).toBe(
      'reply_posted_to_you'
    )
    expect(inboxMessageKey(item({ type: 'reply_posted' }))).toBe('reply_posted')
  })
  it('distinguishes a migrated override', () => {
    expect(
      inboxMessageKey(
        item({ type: 'admin_override', actor: null, data: { backfill: true } })
      )
    ).toBe('admin_override_backfill')
  })
})

describe('inboxActorKey', () => {
  it('is null when the actor has a name', () => {
    expect(inboxActorKey(item())).toBeNull()
  })
  it('names the submitter for submitter events, someone otherwise', () => {
    expect(
      inboxActorKey(item({ type: 'submitter_answered', actor: null }))
    ).toBe('submitter')
    expect(inboxActorKey(item({ type: 'admin_override', actor: null }))).toBe(
      'someone'
    )
  })
})

describe('inboxTitle', () => {
  it('falls back to a dash when the project has no title any more', () => {
    expect(inboxTitle(item({ project: { id: 40, title: '' } }))).toBe('—')
    expect(inboxTitle(item())).toBe('Liturgy Helper')
  })
})

describe('relativeTime', () => {
  const now = new Date('2026-10-16T10:00:00Z')
  it('formats past times in the largest whole unit', () => {
    expect(relativeTime(new Date('2026-10-16T09:55:00Z'), now, 'en')).toBe(
      '5 min. ago'
    )
    expect(relativeTime(new Date('2026-10-16T07:00:00Z'), now, 'en')).toBe(
      '3 hr. ago'
    )
    expect(relativeTime(new Date('2026-10-14T10:00:00Z'), now, 'en')).toBe(
      '2 days ago'
    )
  })
  it('counts whole units, never rounding up into the next one', () => {
    // 45 days is one month and a half; 59m50s is not yet an hour.
    expect(relativeTime(new Date('2026-09-01T10:00:00Z'), now, 'en')).toBe(
      'last mo.'
    )
    expect(relativeTime(new Date('2026-10-16T09:00:10Z'), now, 'en')).toBe(
      '59 min. ago'
    )
  })
  it('says now for under a minute', () => {
    expect(relativeTime(new Date('2026-10-16T09:59:30Z'), now, 'en')).toBe(
      'now'
    )
  })
})
