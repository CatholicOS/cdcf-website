// @vitest-environment happy-dom
import { act } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const search = { value: '' }
vi.mock('next/navigation', () => ({
  useSearchParams: () => new URLSearchParams(search.value),
}))

import DonationForm from './DonationForm'
import DonationStatus from './DonationStatus'
import { parsePresets } from './DonationSection'
import { renderDom } from '@/components/testing/renderDom'

let unmount = () => {}
afterEach(() => {
  unmount()
  search.value = ''
  vi.unstubAllGlobals()
})

/** Type into a React-controlled input the way a browser would. */
function typeInto(input: HTMLInputElement, value: string) {
  const setter = Object.getOwnPropertyDescriptor(
    HTMLInputElement.prototype,
    'value'
  )!.set!
  act(() => {
    setter.call(input, value)
    input.dispatchEvent(new Event('input', { bubbles: true }))
  })
}

function mount(
  projects = [
    {
      slug: 'bibleget',
      title: 'BibleGet',
      canonicalSlug: 'bibleget',
      canonicalTitle: 'BibleGet',
    },
  ]
) {
  const r = renderDom(
    <DonationForm
      locale="it"
      projects={projects}
      presetAmounts={[10, 25]}
      aboveCapHtml="<p>Call the office</p>"
    />
  )
  unmount = r.unmount
  return r.container
}

describe('DonationForm', () => {
  const fetchMock = vi.fn()
  beforeEach(() => {
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
  })

  it('swaps the button for the above-cap panel over $200', () => {
    const c = mount()
    typeInto(c.querySelector('input')!, '250')

    expect(c.textContent).toContain('Call the office')
    expect(c.querySelector('button[type=submit]')).toBeNull()
  })

  it('refuses an amount below the floor without calling the server', async () => {
    const c = mount()
    typeInto(c.querySelector('input')!, '3')
    await act(async () => {
      c.querySelector('form')!.dispatchEvent(
        new Event('submit', { bubbles: true, cancelable: true })
      )
    })

    expect(c.textContent).toContain('The minimum gift is $5.')
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('posts cents, frequency, designation and locale to create-session', async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ error: 'x' }), { status: 502 })
    )
    const c = mount()
    typeInto(c.querySelector('input')!, '50')
    const monthly = [...c.querySelectorAll('button')].find(
      (b) => b.textContent === 'Monthly'
    )!
    act(() => monthly.click())
    const select = c.querySelector('select')!
    act(() => {
      select.value = 'bibleget'
      select.dispatchEvent(new Event('change', { bubbles: true }))
    })
    await act(async () => {
      c.querySelector('form')!.dispatchEvent(
        new Event('submit', { bubbles: true, cancelable: true })
      )
    })

    const [url, init] = fetchMock.mock.calls[0]
    expect(url).toBe('/api/donate/create-session')
    expect(JSON.parse(init.body)).toEqual({
      amountCents: 5000,
      frequency: 'monthly',
      projectSlug: 'bibleget',
      locale: 'it',
      returnPath: window.location.pathname,
    })
    expect(c.textContent).toContain('We could not start the checkout.')
  })

  it('hides the designation picker when no project accepts donations', () => {
    const c = mount([])

    expect(c.querySelector('select')).toBeNull()
  })
})

describe('DonationStatus', () => {
  function status() {
    const r = renderDom(
      <DonationStatus
        thankYouHtml="<p>Thank you!</p>"
        cancelledText="Cancelled."
      >
        <p>the form</p>
      </DonationStatus>
    )
    unmount = r.unmount
    return r.container
  }

  it('shows the form by default', () => {
    expect(status().textContent).toBe('the form')
  })

  it('replaces the form with the thank-you copy on ?status=success', () => {
    search.value = 'status=success'
    const text = status().textContent
    expect(text).toContain('Thank you!')
    expect(text).not.toContain('the form')
  })

  it('adds the cancelled notice above the form on ?status=cancelled', () => {
    search.value = 'status=cancelled'
    expect(status().textContent).toBe('Cancelled.the form')
  })
})

describe('parsePresets', () => {
  it('drops values above the cap, non-integers and junk', () => {
    expect(parsePresets('10, 25,250,7.5,abc,200')).toEqual([10, 25, 200])
  })

  it('falls back to the defaults when nothing usable is left', () => {
    expect(parsePresets('500,0')).toEqual([10, 25, 50, 100])
    expect(parsePresets(null)).toEqual([10, 25, 50, 100])
  })
})
