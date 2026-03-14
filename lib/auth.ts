import NextAuth from "next-auth";
import Zitadel from "next-auth/providers/zitadel";

function getWpRestUrl(): string {
  const graphqlUrl = process.env.WP_GRAPHQL_URL || "";
  return graphqlUrl.replace(/\/graphql$/, "/wp-json");
}

function mapZitadelRoleToWp(
  roles: Record<string, unknown> | undefined
): string {
  if (!roles) return "subscriber";
  if ("admin" in roles) return "administrator";
  if ("editor" in roles) return "editor";
  return "subscriber";
}

export const { handlers, auth, signIn, signOut } = NextAuth({
  providers: [
    Zitadel({
      clientId: process.env.AUTH_ZITADEL_ID,
      clientSecret: process.env.AUTH_ZITADEL_SECRET,
      issuer: process.env.ZITADEL_ISSUER_URL,
      authorization: {
        params: {
          scope:
            "openid profile email offline_access urn:zitadel:iam:org:project:roles",
        },
      },
    }),
  ],
  events: {
    async signIn({ user, profile }) {
      // Fire-and-forget: sync user to WordPress after Zitadel sign-in.
      try {
        const wpRestUrl = getWpRestUrl();
        const wpUsername = process.env.WP_APP_USERNAME;
        const wpPassword = process.env.WP_APP_PASSWORD;

        if (!wpRestUrl || !wpUsername || !wpPassword) return;

        const rolesClaim = profile?.[
          "urn:zitadel:iam:org:project:roles"
        ] as Record<string, unknown> | undefined;
        const wpRole = mapZitadelRoleToWp(rolesClaim);

        const username =
          (profile?.preferred_username as string) ||
          user.email?.split("@")[0] ||
          "user";

        await fetch(`${wpRestUrl}/cdcf/v1/sync-user`, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            Authorization:
              "Basic " +
              Buffer.from(`${wpUsername}:${wpPassword}`).toString("base64"),
          },
          body: JSON.stringify({
            email: user.email,
            username,
            display_name: user.name || username,
            role: wpRole,
          }),
        });
      } catch (error) {
        console.error("[auth] Failed to sync user to WordPress:", error);
      }
    },
  },
  callbacks: {
    async jwt({ token, account, profile }) {
      // Initial sign-in: capture all tokens
      if (account) {
        token.accessToken = account.access_token;
        token.refreshToken = account.refresh_token;
        token.expiresAt = account.expires_at;
      }

      if (profile) {
        const rolesClaim = profile["urn:zitadel:iam:org:project:roles"];
        token.roles = rolesClaim ? Object.keys(rolesClaim) : [];
      }

      // Token still valid — return as-is
      if (Date.now() < ((token.expiresAt ?? 0) as number) * 1000) {
        return token;
      }

      // Token expired — attempt refresh
      if (token.refreshToken) {
        try {
          const issuer = process.env.ZITADEL_ISSUER_URL;
          const response = await fetch(`${issuer}/oauth/v2/token`, {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: new URLSearchParams({
              client_id: process.env.AUTH_ZITADEL_ID || "",
              client_secret: process.env.AUTH_ZITADEL_SECRET || "",
              grant_type: "refresh_token",
              refresh_token: token.refreshToken as string,
            }),
          });

          const tokens = await response.json();

          if (!response.ok) {
            throw new Error(
              tokens.error_description || "Token refresh failed"
            );
          }

          return {
            ...token,
            accessToken: tokens.access_token,
            expiresAt: Math.floor(Date.now() / 1000 + tokens.expires_in),
            refreshToken: tokens.refresh_token ?? token.refreshToken,
            error: undefined,
          };
        } catch {
          return { ...token, error: "RefreshAccessTokenError" };
        }
      }

      return token;
    },
    async session({ session, token }) {
      session.accessToken = token.accessToken as string | undefined;
      session.error = token.error as string | undefined;
      if (session.user) {
        session.user.roles = (token.roles as string[]) || [];
      }
      return session;
    },
  },
  session: { strategy: "jwt" },
  trustHost: true,
});
