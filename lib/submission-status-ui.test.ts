import { describe, expect, it } from 'vitest'
import { answerErrorKey, timelineSteps } from './submission-status-ui'

describe('timelineSteps', () => {
  it('shows review in progress after receipt', () => {
    expect(timelineSteps({ stage: 'in_review' })).toEqual([
      { key: 'received', state: 'done' },
      { key: 'review', state: 'current' },
      { key: 'decision', state: 'upcoming' },
    ])
  })

  it('adds the question step while a reply is awaited', () => {
    expect(timelineSteps({ stage: 'awaiting_submitter' })).toEqual([
      { key: 'received', state: 'done' },
      { key: 'review', state: 'done' },
      { key: 'question', state: 'current' },
      { key: 'decision', state: 'upcoming' },
    ])
  })

  it('marks everything done once decided', () => {
    expect(timelineSteps({ stage: 'decided' })).toEqual([
      { key: 'received', state: 'done' },
      { key: 'review', state: 'done' },
      { key: 'decision', state: 'done' },
    ])
  })
})

describe('answerErrorKey', () => {
  it('maps WordPress codes to messages and defaults to generic', () => {
    expect(answerErrorKey('already_answered')).toBe('alreadyAnswered')
    expect(answerErrorKey('review_closed')).toBe('closed')
    expect(answerErrorKey('answer_rejected')).toBe('rejected')
    expect(answerErrorKey('body_too_long')).toBe('tooLong')
    expect(answerErrorKey('empty_body')).toBe('empty')
    expect(answerErrorKey('not_found')).toBe('expired')
    expect(answerErrorKey('rate_limited')).toBe('rateLimited')
    expect(answerErrorKey('something_else')).toBe('generic')
    expect(answerErrorKey(undefined)).toBe('generic')
  })
})
