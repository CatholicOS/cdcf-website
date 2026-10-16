// @vitest-environment happy-dom
import { act } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const push = vi.fn();
let pathname = "/review";
vi.mock("@/src/i18n/navigation", () => ({
  useRouter: () => ({ push }),
  usePathname: () => pathname,
  Link: ({
    href,
    children,
    onClick,
    className,
  }: {
    href: string;
    children: React.ReactNode;
    onClick?: () => void;
    className?: string;
  }) => (
    <a href={href} onClick={onClick} className={className}>
      {children}
    </a>
  ),
}));

import { BELL_POLL_MS, NotificationBell } from "./NotificationBell";
import { INBOX_CHANGED_EVENT } from "@/lib/review-inbox";
import { buttonByText, click, renderDom } from "@/components/testing/renderDom";
import type { InboxItem } from "@/lib/review-types";

const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json" },
  });

const item = (over: Partial<InboxItem> = {}): InboxItem => ({
  event_id: 12,
  type: "reply_posted",
  project: { id: 40, title: "Liturgy Helper" },
  actor: { id: 8, name: "Fr. A" },
  entry_id: 5,
  data: {},
  created_at: "2026-10-16 09:00:00.000000",
  read: false,
  to_you: true,
  ...over,
});

let unmount = () => {};
let rerender = () => {};
let fetchMock: ReturnType<typeof vi.fn>;
beforeEach(() => {
  push.mockReset();
  pathname = "/review";
  fetchMock = vi.fn();
  vi.stubGlobal("fetch", fetchMock);
});
afterEach(() => {
  unmount();
  vi.unstubAllGlobals();
  vi.useRealTimers();
});

async function flush() {
  await act(async () => {
    await Promise.resolve();
    await Promise.resolve();
  });
}

async function mount() {
  const r = renderDom(<NotificationBell />);
  unmount = r.unmount;
  rerender = () => r.rerender(<NotificationBell />);
  await flush();
  return r.container;
}

/** The latest fetch call's [url, init]; fails the test when there is none. */
function lastCall(): [string, RequestInit & { body: string }] {
  const call = fetchMock.mock.calls.at(-1);
  expect(call).toBeDefined();
  return call as [string, RequestInit & { body: string }];
}

const panelOf = (c: HTMLElement) =>
  document.getElementById(bell(c).getAttribute("aria-controls") ?? "");

const bell = (c: HTMLElement) =>
  c.querySelector("button[aria-expanded]") as HTMLButtonElement;

// The item button also holds the age, so match the sentence by substring.
const itemButton = (c: HTMLElement, sentence: string) =>
  [...c.querySelectorAll("button")].find((b) =>
    b.textContent?.includes(sentence),
  );

describe("NotificationBell", () => {
  it("shows the unread count from the count route", async () => {
    fetchMock.mockResolvedValue(json({ unread: 3 }));
    const c = await mount();
    expect(fetchMock).toHaveBeenCalledWith(
      "/api/review/inbox/count",
      expect.anything(),
    );
    expect(bell(c).getAttribute("aria-label")).toBe("Notifications, 3 unread");
    expect(c.textContent).toContain("3");
  });

  it("keeps the last count when the count route fails", async () => {
    vi.useFakeTimers();
    fetchMock.mockResolvedValueOnce(json({ unread: 2 }));
    fetchMock.mockResolvedValueOnce(json({ error: "unauthorized" }, 401));
    const c = await mount();
    await act(async () => {
      vi.advanceTimersByTime(BELL_POLL_MS);
    });
    await flush();
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(bell(c).getAttribute("aria-label")).toBe("Notifications, 2 unread");
  });

  it("opens the latest items and follows one, marking it read", async () => {
    fetchMock.mockResolvedValueOnce(json({ unread: 1 }));
    const c = await mount();
    fetchMock.mockResolvedValueOnce(
      json({ items: [item()], page: 1, has_more: false, unread: 1 }),
    );
    await act(async () => click(bell(c)));
    await flush();
    expect(c.textContent).toContain(
      "Fr. A replied to your review · Liturgy Helper",
    );

    fetchMock.mockResolvedValueOnce(json({ unread: 0 }));
    await act(async () =>
      click(itemButton(c, "Fr. A replied to your review · Liturgy Helper")),
    );
    await flush();
    const [url, init] = lastCall();
    expect(url).toBe("/api/review/inbox/read");
    expect(JSON.parse(init.body)).toEqual({ event_ids: [12] });
    expect(push).toHaveBeenCalledWith("/review/40#entry-5");
  });

  it("marks all read", async () => {
    fetchMock.mockResolvedValueOnce(json({ unread: 1 }));
    const c = await mount();
    fetchMock.mockResolvedValueOnce(
      json({ items: [item()], page: 1, has_more: false, unread: 1 }),
    );
    await act(async () => click(bell(c)));
    await flush();
    fetchMock.mockResolvedValueOnce(json({ unread: 0 }));
    await act(async () => click(buttonByText(c, "Mark all read")));
    await flush();
    expect(JSON.parse(lastCall()[1].body)).toEqual({
      all: true,
    });
    expect(bell(c).getAttribute("aria-label")).toBe("Notifications");
  });

  it("says so when the list cannot load", async () => {
    fetchMock.mockResolvedValueOnce(json({ unread: 0 }));
    const c = await mount();
    fetchMock.mockResolvedValueOnce(json({ error: "x" }, 500));
    await act(async () => click(bell(c)));
    await flush();
    expect(c.textContent).toContain("Could not load notifications.");
  });

  it("closes on Escape and returns focus to the bell", async () => {
    fetchMock.mockResolvedValueOnce(json({ unread: 0 }));
    const c = await mount();
    fetchMock.mockResolvedValueOnce(
      json({ items: [], page: 1, has_more: false, unread: 0 }),
    );
    await act(async () => click(bell(c)));
    await flush();
    expect(bell(c).getAttribute("aria-expanded")).toBe("true");
    expect(panelOf(c)).toBeTruthy();
    expect(bell(c).hasAttribute("aria-haspopup")).toBe(false);
    await act(async () => {
      document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape" }));
    });
    expect(bell(c).getAttribute("aria-expanded")).toBe("false");
    expect(document.activeElement).toBe(bell(c));
  });

  it("gives unread items a screen-reader cue", async () => {
    fetchMock.mockResolvedValueOnce(json({ unread: 1 }));
    const c = await mount();
    fetchMock.mockResolvedValueOnce(
      json({
        items: [item(), item({ event_id: 13, read: true })],
        page: 1,
        has_more: false,
        unread: 1,
      }),
    );
    await act(async () => click(bell(c)));
    await flush();
    const markers = c.querySelectorAll("li .sr-only");
    expect(markers).toHaveLength(1);
    expect(markers[0].textContent).toBe("Unread");
  });

  it("drops a list response that arrives after the panel closed", async () => {
    fetchMock.mockResolvedValueOnce(json({ unread: 0 }));
    const c = await mount();
    let resolve!: (r: Response) => void;
    fetchMock.mockReturnValueOnce(new Promise<Response>((r) => (resolve = r)));
    await act(async () => click(bell(c)));
    await act(async () => click(bell(c)));
    await act(async () => {
      resolve(json({ items: [item()], page: 1, has_more: false, unread: 5 }));
    });
    await flush();
    expect(bell(c).getAttribute("aria-label")).toBe("Notifications");
  });

  it("treats a 200 non-JSON body as a failure without throwing", async () => {
    fetchMock.mockResolvedValueOnce(new Response("<html>", { status: 200 }));
    const c = await mount();
    expect(bell(c).getAttribute("aria-label")).toBe("Notifications");
    fetchMock.mockResolvedValueOnce(new Response("<html>", { status: 200 }));
    await act(async () => click(bell(c)));
    await flush();
    expect(c.textContent).toContain("Could not load notifications.");
  });

  it("navigates without waiting for the mark-read request", async () => {
    fetchMock.mockResolvedValueOnce(json({ unread: 1 }));
    const c = await mount();
    fetchMock.mockResolvedValueOnce(
      json({ items: [item()], page: 1, has_more: false, unread: 1 }),
    );
    await act(async () => click(bell(c)));
    await flush();
    fetchMock.mockReturnValueOnce(new Promise(() => {}));
    await act(async () =>
      click(itemButton(c, "Fr. A replied to your review · Liturgy Helper")),
    );
    expect(push).toHaveBeenCalledWith("/review/40#entry-5");
  });

  it("does not poll while the tab is hidden", async () => {
    vi.useFakeTimers();
    fetchMock.mockResolvedValue(json({ unread: 0 }));
    await mount();
    Object.defineProperty(document, "visibilityState", {
      configurable: true,
      get: () => "hidden",
    });
    await act(async () => {
      document.dispatchEvent(new Event("visibilitychange"));
      vi.advanceTimersByTime(BELL_POLL_MS * 3);
    });
    expect(fetchMock).toHaveBeenCalledTimes(1);
    Object.defineProperty(document, "visibilityState", {
      configurable: true,
      get: () => "visible",
    });
  });

  it("names the panel it controls only while it is open", async () => {
    fetchMock.mockResolvedValueOnce(json({ unread: 0 }));
    const c = await mount();
    expect(bell(c).hasAttribute("aria-controls")).toBe(false);
    fetchMock.mockResolvedValueOnce(
      json({ items: [], page: 1, has_more: false, unread: 0 }),
    );
    await act(async () => click(bell(c)));
    await flush();
    expect(panelOf(c)).toBeTruthy();
  });

  it("moves focus into the panel when it opens", async () => {
    fetchMock.mockResolvedValueOnce(json({ unread: 0 }));
    const c = await mount();
    fetchMock.mockResolvedValueOnce(
      json({ items: [], page: 1, has_more: false, unread: 0 }),
    );
    await act(async () => click(bell(c)));
    await flush();
    expect(document.activeElement).toBe(panelOf(c));
  });

  it("closes when focus leaves the bell and its panel", async () => {
    fetchMock.mockResolvedValueOnce(json({ unread: 0 }));
    const c = await mount();
    const outside = document.createElement("button");
    document.body.append(outside);
    fetchMock.mockResolvedValueOnce(
      json({ items: [], page: 1, has_more: false, unread: 0 }),
    );
    await act(async () => click(bell(c)));
    await flush();
    await act(async () => outside.focus());
    expect(bell(c).getAttribute("aria-expanded")).toBe("false");
    outside.remove();
  });

  it("drops a list response that arrives after an outside click closed the panel", async () => {
    fetchMock.mockResolvedValueOnce(json({ unread: 0 }));
    const c = await mount();
    let resolve!: (r: Response) => void;
    fetchMock.mockReturnValueOnce(new Promise<Response>((r) => (resolve = r)));
    await act(async () => click(bell(c)));
    await act(async () => {
      document.body.dispatchEvent(
        new MouseEvent("mousedown", { bubbles: true }),
      );
    });
    expect(bell(c).getAttribute("aria-expanded")).toBe("false");
    await act(async () => {
      resolve(json({ items: [item()], page: 1, has_more: false, unread: 5 }));
    });
    await flush();
    expect(bell(c).getAttribute("aria-label")).toBe("Notifications");
  });

  it("refreshes the count when the page changes", async () => {
    fetchMock.mockResolvedValueOnce(json({ unread: 3 }));
    const c = await mount();
    fetchMock.mockResolvedValueOnce(json({ unread: 0 }));
    pathname = "/review/40";
    await act(async () => rerender());
    await flush();
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(bell(c).getAttribute("aria-label")).toBe("Notifications");
  });

  it("refreshes the count when the inbox reports a change", async () => {
    fetchMock.mockResolvedValueOnce(json({ unread: 3 }));
    const c = await mount();
    fetchMock.mockResolvedValueOnce(json({ unread: 0 }));
    await act(async () => {
      window.dispatchEvent(new Event(INBOX_CHANGED_EVENT));
    });
    await flush();
    expect(bell(c).getAttribute("aria-label")).toBe("Notifications");
  });
});
