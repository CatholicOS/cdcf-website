/**
 * Server-only client for the council review endpoints
 * (`/cdcf/v1/review/*`). Every call forwards the signed-in user's Zitadel
 * access token as a bearer, so WordPress enforces council membership the
 * same way it does for wp-admin. Mirrors lib/bio-api.ts.
 */
import "server-only";
import type { Session } from "next-auth";
import { getAccessToken } from "@/lib/auth-utils";
import type {
  ReviewAttachment,
  ReviewEntry,
  ReviewMe,
  ReviewProject,
  ReviewProjectList,
  ReviewStage,
  ReviewVote,
} from "@/lib/review-types";

export class ReviewApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly code?: string,
  ) {
    super(message);
    this.name = "ReviewApiError";
  }
}

function getWpRestUrl(): string {
  const url =
    process.env.WP_REST_URL ??
    process.env.WP_GRAPHQL_URL?.replace(/\/graphql\/?$/, "/wp-json");
  if (!url) {
    throw new ReviewApiError(
      "Neither WP_REST_URL nor WP_GRAPHQL_URL is configured",
      500,
      "config_missing",
    );
  }
  return url.replace(/\/$/, "");
}

function bearerHeader(
  session: Session | null | undefined,
): Record<string, string> {
  const token = getAccessToken(session);
  if (!token) {
    throw new ReviewApiError("No access token on session", 401, "no_token");
  }
  return { Authorization: `Bearer ${token}` };
}

async function readJson(response: Response): Promise<unknown> {
  try {
    return await response.json();
  } catch {
    return null;
  }
}

function toReviewApiError(
  body: unknown,
  fallback: string,
  status: number,
): ReviewApiError {
  if (body && typeof body === "object") {
    const obj = body as { message?: unknown; code?: unknown };
    const message = typeof obj.message === "string" ? obj.message : fallback;
    const code = typeof obj.code === "string" ? obj.code : undefined;
    return new ReviewApiError(message, status, code);
  }
  // A proxy in front of WordPress (nginx) rejects oversized bodies with an
  // HTML 413 page that never reaches PHP.
  if (status === 413) {
    return new ReviewApiError(
      "The file is too large for the server",
      413,
      "file_too_large",
    );
  }
  return new ReviewApiError(fallback, status);
}

function assertId(id: number): void {
  if (!Number.isInteger(id) || id <= 0) {
    throw new ReviewApiError("Invalid id", 400, "invalid_id");
  }
}

interface WpRequestInit {
  method?: "GET" | "POST" | "PUT" | "PATCH";
  json?: unknown;
  body?: FormData;
}

async function wpRequest<T>(
  session: Session | null,
  path: string,
  init: WpRequestInit = {},
): Promise<T> {
  const headers = bearerHeader(session);
  let body: BodyInit | undefined;
  if (init.json !== undefined) {
    headers["Content-Type"] = "application/json";
    body = JSON.stringify(init.json);
  } else if (init.body) {
    body = init.body;
  }
  const response = await fetch(`${getWpRestUrl()}${path}`, {
    method: init.method ?? "GET",
    headers,
    body,
    cache: "no-store",
  });
  const data = await readJson(response);
  if (!response.ok) {
    throw toReviewApiError(
      data,
      `Review request failed: ${path}`,
      response.status,
    );
  }
  return data as T;
}

export function fetchReviewMe(session: Session | null): Promise<ReviewMe> {
  return wpRequest<ReviewMe>(session, "/cdcf/v1/review/me");
}

export function fetchReviewProjects(
  session: Session | null,
  options: { stage?: ReviewStage; page?: number } = {},
): Promise<ReviewProjectList> {
  const params = new URLSearchParams();
  if (options.stage) params.set("stage", options.stage);
  if (options.page && options.page > 1)
    params.set("page", String(options.page));
  const qs = params.toString();
  return wpRequest<ReviewProjectList>(
    session,
    `/cdcf/v1/review/projects${qs ? `?${qs}` : ""}`,
  );
}

export async function fetchReviewProject(
  session: Session | null,
  id: number,
): Promise<ReviewProject> {
  assertId(id);
  return wpRequest<ReviewProject>(session, `/cdcf/v1/review/projects/${id}`);
}

export interface MyReviewPayload {
  vote: ReviewVote;
  body_md: string;
  version?: string;
}

export async function saveMyReview(
  session: Session | null,
  projectId: number,
  payload: MyReviewPayload,
): Promise<ReviewEntry> {
  assertId(projectId);
  return wpRequest<ReviewEntry>(
    session,
    `/cdcf/v1/review/projects/${projectId}/my-review`,
    { method: "PUT", json: payload },
  );
}

export interface EntryPayload {
  kind: "reply" | "discussion";
  parent_id?: number;
  body_md: string;
}

export async function postReviewEntry(
  session: Session | null,
  projectId: number,
  payload: EntryPayload,
): Promise<ReviewEntry> {
  assertId(projectId);
  return wpRequest<ReviewEntry>(
    session,
    `/cdcf/v1/review/projects/${projectId}/entries`,
    { method: "POST", json: payload },
  );
}

export interface EntryEditPayload {
  body_md: string;
  version: string;
}

export async function patchReviewEntry(
  session: Session | null,
  entryId: number,
  payload: EntryEditPayload,
): Promise<ReviewEntry> {
  assertId(entryId);
  return wpRequest<ReviewEntry>(session, `/cdcf/v1/review/entries/${entryId}`, {
    method: "PATCH",
    json: payload,
  });
}

export async function uploadReviewAttachment(
  session: Session | null,
  entryId: number,
  upload: FormData | { title: string; markdown: string },
): Promise<ReviewAttachment> {
  assertId(entryId);
  return wpRequest<ReviewAttachment>(
    session,
    `/cdcf/v1/review/entries/${entryId}/attachments`,
    upload instanceof FormData
      ? { method: "POST", body: upload }
      : { method: "POST", json: upload },
  );
}

export async function fetchReviewAttachment(
  session: Session | null,
  id: number,
  download = false,
): Promise<Response> {
  assertId(id);
  const response = await fetch(
    `${getWpRestUrl()}/cdcf/v1/review/attachments/${id}${download ? "?download=1" : ""}`,
    { headers: bearerHeader(session), cache: "no-store" },
  );
  if (!response.ok) {
    throw toReviewApiError(
      await readJson(response),
      "Attachment request failed",
      response.status,
    );
  }
  return response;
}
