// @vitest-environment happy-dom
import { act } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import ManageGivingForm from './ManageGivingForm'
import { renderDom } from '@/components/testing/renderDom'

let unmount = () => {}
const fetchMock = vi.fn()

beforeEach(() => {
  fetchMock.mockReset()
  vi.stubGlobal('fetch', fetchMock)
})
afterEach(() => {
  unmount()
  vi.unstubAllGlobals()
})

/** Render the manage-gift form on a French page. */
function mount() {
  const r = renderDom(<ManageGivingForm locale="fr" />)
  unmount = r.unmount
  return r.container
}

/** Type into the React-controlled email input the way a browser would. */
function typeEmail(input: HTMLInputElement, value: string) {
  const setter = Object.getOwnPropertyDescriptor(
    HTMLInputElement.prototype,
    'value'
  )!.set!
  act(() => {
    setter.call(input, value)
    input.dispatchEvent(new Event('input', { bubbles: true }))
  })
}

/** Submit the form and let its request settle. */
async function submit(c: HTMLElement) {
  await act(async () => {
    c.querySelector('form')!.dispatchEvent(
      new Event('submit', { bubbles: true, cancelable: true })
    )
  })
}

describe('ManageGivingForm', () => {
  it('asks the portal endpoint for a link in the page language', async () => {
    fetchMock.mockResolvedValue(
      new Response('{"success":true}', { status: 200 })
    )
    const c = mount()
    typeEmail(c.querySelector('input[type=email]')!, 'donor@example.org')
    await submit(c)

    const [url, init] = fetchMock.mock.calls[0]
    expect(url).toBe('/api/donate/portal')
    expect(JSON.parse(init.body)).toEqual({
      email: 'donor@example.org',
      locale: 'fr',
    })
  })

  it('shows the same neutral confirmation whatever the address', async () => {
    fetchMock.mockResolvedValue(
      new Response('{"success":true}', { status: 200 })
    )
    const c = mount()
    typeEmail(c.querySelector('input[type=email]')!, 'anyone@example.org')
    await submit(c)

    expect(c.textContent).toContain(
      'If this address has a monthly gift with us, we have emailed it a link'
    )
    expect(c.querySelector('form')).toBeNull()
  })

  it('reports a rate limit or outage instead of pretending it worked', async () => {
    fetchMock.mockResolvedValue(new Response('{}', { status: 429 }))
    const c = mount()
    typeEmail(c.querySelector('input[type=email]')!, 'x@example.org')
    await submit(c)

    expect(c.textContent).toContain('Please try again later.')
    expect(c.querySelector('form')).not.toBeNull()
  })
})
