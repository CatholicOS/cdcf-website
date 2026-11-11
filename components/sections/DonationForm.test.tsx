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

/** The first element matching `selector`; fails the test when there is none. */
function q<T extends Element = HTMLElement>(
  root: ParentNode,
  selector: string
): T {
  const el = root.querySelector<T>(selector)
  if (!el) throw new Error(`No element matches ${selector}`)
  return el
}

/** The button whose text is exactly `text`; fails the test when there is none. */
function buttonNamed(root: ParentNode, text: string): HTMLButtonElement {
  const button = [...root.querySelectorAll('button')].find(
    (b) => b.textContent === text
  )
  if (!button) throw new Error(`No button named ${text}`)
  return button
}

/** The native value setter React listens through, for simulated typing. */
const nativeValueSetter = (() => {
  const setter = Object.getOwnPropertyDescriptor(
    HTMLInputElement.prototype,
    'value'
  )?.set
  if (!setter) throw new Error('HTMLInputElement has no value setter')
  return setter
})()

let unmount = () => {}
afterEach(() => {
  unmount()
  search.value = ''
  vi.unstubAllGlobals()
})

/** Type into a React-controlled input the way a browser would. */
function typeInto(input: HTMLInputElement, value: string) {
  act(() => {
    nativeValueSetter.call(input, value)
    input.dispatchEvent(new Event('input', { bubbles: true }))
  })
}

/** Render the form in Italian with the given designation list. */
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
    typeInto(q<HTMLInputElement>(c, 'input'), '250')

    expect(c.textContent).toContain('Call the office')
    expect(c.querySelector('button[type=submit]')).toBeNull()
  })

  it('refuses an amount below the floor without calling the server', async () => {
    const c = mount()
    typeInto(q<HTMLInputElement>(c, 'input'), '3')
    await act(async () => {
      q<HTMLFormElement>(c, 'form').dispatchEvent(
        new Event('submit', { bubbles: true, cancelable: true })
      )
    })

    expect(c.textContent).toContain('The minimum gift is $5.')
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('clears a stale error as soon as the donor changes the amount', async () => {
    const c = mount()
    typeInto(q<HTMLInputElement>(c, 'input'), '3')
    await act(async () => {
      q<HTMLFormElement>(c, 'form').dispatchEvent(
        new Event('submit', { bubbles: true, cancelable: true })
      )
    })
    expect(c.textContent).toContain('The minimum gift is $5.')

    typeInto(q<HTMLInputElement>(c, 'input'), '30')
    expect(c.textContent).not.toContain('The minimum gift is $5.')

    typeInto(q<HTMLInputElement>(c, 'input'), '3')
    await act(async () => {
      q<HTMLFormElement>(c, 'form').dispatchEvent(
        new Event('submit', { bubbles: true, cancelable: true })
      )
    })
    const preset = buttonNamed(c, '$25')
    act(() => preset.click())
    expect(c.textContent).not.toContain('The minimum gift is $5.')
  })

  it('posts cents, frequency, designation and locale to create-session', async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ error: 'x' }), { status: 502 })
    )
    const c = mount()
    typeInto(q<HTMLInputElement>(c, 'input'), '50')
    const monthly = buttonNamed(c, 'Monthly')
    act(() => monthly.click())
    const select = q<HTMLSelectElement>(c, 'select')
    act(() => {
      select.value = 'bibleget'
      select.dispatchEvent(new Event('change', { bubbles: true }))
    })
    await act(async () => {
      q<HTMLFormElement>(c, 'form').dispatchEvent(
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
  /** Render the status switch around a stand-in for the form. */
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

  describe('bringing the returning donor to the message', () => {
    let scrolled: ScrollIntoViewOptions[]
    let reduceMotion: boolean

    beforeEach(() => {
      scrolled = []
      reduceMotion = false
      vi.spyOn(HTMLElement.prototype, 'scrollIntoView').mockImplementation(
        (options?: boolean | ScrollIntoViewOptions) => {
          scrolled.push(options as ScrollIntoViewOptions)
        }
      )
      vi.stubGlobal('matchMedia', (query: string) => ({
        matches: query === '(prefers-reduced-motion: reduce)' && reduceMotion,
      }))
    })

    afterEach(() => {
      vi.restoreAllMocks()
      vi.unstubAllGlobals()
    })

    it('smoothly scrolls to the thank-you copy and focuses it', () => {
      search.value = 'status=success'
      const container = status()
      const message = q(container, '[data-donation-status]')
      expect(scrolled).toEqual([{ behavior: 'smooth', block: 'start' }])
      expect(document.activeElement).toBe(message)
      expect(message.textContent).toContain('Thank you!')
    })

    it('jumps without animation when the visitor prefers reduced motion', () => {
      reduceMotion = true
      search.value = 'status=success'
      status()
      expect(scrolled).toEqual([{ behavior: 'auto', block: 'start' }])
    })

    it('scrolls to the cancelled notice too', () => {
      search.value = 'status=cancelled'
      const container = status()
      expect(scrolled).toHaveLength(1)
      expect(document.activeElement?.textContent).toBe('Cancelled.')
      expect(q(container, '[data-donation-status]').textContent).toBe(
        'Cancelled.'
      )
    })

    it('leaves a plain visit where it is', () => {
      search.value = ''
      status()
      expect(scrolled).toEqual([])
    })
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
