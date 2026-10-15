import { act, type ReactNode } from 'react'
import { createRoot } from 'react-dom/client'
import { NextIntlClientProvider } from 'next-intl'
import messages from '@/messages/en.json'

/**
 * Mounts a client component into the (happy-dom) document for tests that
 * need effects, events and focus. Test files using it start with
 * `// @vitest-environment happy-dom`.
 */
export function renderDom(node: ReactNode) {
  ;(
    globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }
  ).IS_REACT_ACT_ENVIRONMENT = true
  const container = document.createElement('div')
  document.body.append(container)
  const root = createRoot(container)
  const wrap = (n: ReactNode) => (
    <NextIntlClientProvider locale="en" messages={messages}>
      {n}
    </NextIntlClientProvider>
  )
  act(() => root.render(wrap(node)))
  return {
    container,
    rerender: (next: ReactNode) => act(() => root.render(wrap(next))),
    unmount: () => {
      act(() => root.unmount())
      container.remove()
    },
  }
}

export function click(el: Element | null | undefined) {
  if (!el) throw new Error('element not found')
  act(() => {
    ;(el as HTMLElement).click()
  })
}

export function typeInto(el: Element | null | undefined, value: string) {
  if (!el) throw new Error('element not found')
  const input = el as HTMLInputElement | HTMLTextAreaElement
  const proto = Object.getPrototypeOf(input)
  act(() => {
    Object.getOwnPropertyDescriptor(proto, 'value')?.set?.call(input, value)
    input.dispatchEvent(new Event('input', { bubbles: true }))
  })
}

export function byLabel(root: ParentNode, label: string) {
  return root.querySelector(`[aria-label="${label}"]`)
}

export function buttonByText(root: ParentNode, text: string) {
  return [...root.querySelectorAll('button')].find(
    (b) => b.textContent?.trim() === text
  )
}
