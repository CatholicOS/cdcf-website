import { auth } from "./auth";
import { redirect } from "next/navigation";
import { Session } from "next-auth";

/**
 * Server-side helper to ensure the user is authenticated.
 * Redirects to sign in if not.
 */
export async function requireAuth() {
  const session = await auth();
  if (!session) {
    redirect("/api/auth/signin");
  }
  return session;
}

/**
 * Server-side helper to ensure the user has a specific role.
 * Throws an error or redirects if not.
 */
export async function requireRole(role: string) {
  const session = await requireAuth();
  if (!hasRole(session, role)) {
    // In a real app, you might redirect to an unauthorized page
    throw new Error(`Unauthorized: Role '${role}' required.`);
  }
  return session;
}

/**
 * Checks if a session contains a specific role.
 */
export function hasRole(session: Session | null, role: string) {
  return session?.user?.roles?.includes(role) ?? false;
}
