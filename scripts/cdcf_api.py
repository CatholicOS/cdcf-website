#!/usr/bin/env python3
"""CDCF CMS API client library and CLI.

Wraps all REST API endpoints from the CDCF headless CMS,
reading credentials from .env.local and .env so that callers
never need to handle secrets directly.

Usage as library:
    from cdcf_api import CdcfClient
    client = CdcfClient()
    result = client.get_relationship(post_id=5, field="team_members")

Usage as CLI:
    python scripts/cdcf_api.py get-relationship --post-id 5 --field team_members
    python scripts/cdcf_api.py create-team-member --title "Jane" --content "<p>Bio</p>" --council technical_council
"""

import argparse
import json
import os
import sys
from pathlib import Path
from urllib.parse import urljoin

import requests
from dotenv import dotenv_values


class CdcfClient:
    """Client for the CDCF CMS REST API and WPGraphQL."""

    def __init__(self, project_root: str | None = None):
        root = Path(project_root) if project_root else Path(__file__).resolve().parent.parent
        env_local = dotenv_values(root / ".env.local")
        env_file = dotenv_values(root / ".env")
        env = {**env_file, **env_local}

        self.wp_rest_url = env.get("WP_REST_URL", "").rstrip("/")
        self.wp_graphql_url = env.get("WP_GRAPHQL_URL", "")
        wp_username = env.get("WP_APP_USERNAME", "")
        wp_password = env.get("WP_APP_PASSWORD", "")
        self.wp_auth = (wp_username, wp_password) if wp_username and wp_password else None
        self.preview_secret = env.get("WP_PREVIEW_SECRET", "")

        # Derive Next.js base URL: if WP_REST_URL is http://localhost:8000/wp-json,
        # Next.js is at http://localhost:3000. For production, use NEXTJS_URL if set.
        self.nextjs_url = env.get("NEXTJS_URL", "http://localhost:3000").rstrip("/")

        if not self.wp_rest_url:
            raise ValueError("WP_REST_URL not found in .env.local or .env")
        if not self.wp_graphql_url:
            raise ValueError("WP_GRAPHQL_URL not found in .env.local or .env")

    def _wp_url(self, path: str) -> str:
        return f"{self.wp_rest_url}/{path.lstrip('/')}"

    def _wp_get(self, path: str, params: dict | None = None) -> dict:
        resp = requests.get(self._wp_url(path), params=params, auth=self.wp_auth, timeout=120)
        resp.raise_for_status()
        return resp.json()

    def _wp_post(self, path: str, data: dict | None = None) -> dict:
        resp = requests.post(self._wp_url(path), json=data, auth=self.wp_auth, timeout=120)
        resp.raise_for_status()
        return resp.json()

    # -- Post Meta / ACF Fields --

    def get_post(self, post_id: int, post_type: str = "posts") -> dict:
        """GET a post via WP REST API. Returns full post object including meta.

        post_type: REST API slug — "posts", "pages", "project", "team_member",
                   "community_channel", "local_group", "acad_collab", "sponsor",
                   "stat_item", "community_project"
        """
        return self._wp_get(f"wp/v2/{post_type}/{post_id}")

    def get_meta(self, post_id: int, post_type: str = "posts", field: str | None = None) -> dict:
        """Read meta/ACF fields for a post.

        Returns the full meta dict, or a single field value if `field` is given.
        """
        data = self.get_post(post_id, post_type)
        meta = data.get("meta", {})
        if field:
            return {"post_id": post_id, "field": field, "value": meta.get(field)}
        return {"post_id": post_id, "meta": meta}

    def update_meta(self, post_id: int, post_type: str = "posts", **fields) -> dict:
        """Update one or more meta/ACF fields on a post.

        post_type: REST API slug (see get_post).
        fields: key=value pairs of meta fields to set.

        Returns the updated meta dict.
        """
        resp = requests.post(
            self._wp_url(f"wp/v2/{post_type}/{post_id}"),
            json={"meta": fields},
            auth=self.wp_auth,
            timeout=120,
        )
        resp.raise_for_status()
        return {"post_id": post_id, "meta": resp.json().get("meta", {})}

    # -- GraphQL --

    def graphql(self, query: str, variables: dict | None = None) -> dict:
        """Execute an arbitrary WPGraphQL query and return the data dict.

        Raises ValueError if the response contains GraphQL errors.
        """
        payload: dict = {"query": query}
        if variables:
            payload["variables"] = variables
        resp = requests.post(
            self.wp_graphql_url,
            json=payload,
            headers={"Content-Type": "application/json"},
            timeout=120,
        )
        resp.raise_for_status()
        body = resp.json()
        if body.get("errors"):
            msgs = [e.get("message", str(e)) for e in body["errors"]]
            raise ValueError(f"WPGraphQL errors: {'; '.join(msgs)}")
        return body.get("data", {})

    def get_translation_ids(self, post_id: int, post_type: str = "page") -> dict[str, int]:
        """Get all Polylang translation post IDs for a given post.

        Returns a dict mapping language code to database ID,
        e.g. {"en": 10, "it": 12, "es": 14, "fr": 16, "pt": 18, "de": 20}

        post_type: the WPGraphQL singular type name — "page", "post", "project",
                   "teamMember", "communityChannel", "localGroup", "sponsor", "statItem"
        """
        # WPGraphQL uses the singular type name as the root query field,
        # and accepts `id` + `idType: DATABASE_ID` for lookup.
        query = """
        query GetTranslationIds($id: ID!) {
            %(type)s(id: $id, idType: DATABASE_ID) {
                databaseId
                language {
                    code
                }
                translations {
                    databaseId
                    language {
                        code
                    }
                }
            }
        }
        """ % {"type": post_type}
        data = self.graphql(query, {"id": post_id})
        root = data.get(post_type)
        if not root:
            return {}
        result: dict[str, int] = {}
        # Include the queried post itself
        if root.get("language") and root.get("databaseId"):
            result[root["language"]["code"].lower()] = root["databaseId"]
        # Add all translations
        for t in root.get("translations") or []:
            if t.get("language") and t.get("databaseId"):
                result[t["language"]["code"].lower()] = t["databaseId"]
        return result

    def get_post_language(self, post_id: int, post_type: str = "page") -> str | None:
        """Get the Polylang language code for a post. Returns e.g. "en" or None."""
        query = """
        query GetPostLanguage($id: ID!) {
            %(type)s(id: $id, idType: DATABASE_ID) {
                language {
                    code
                }
            }
        }
        """ % {"type": post_type}
        data = self.graphql(query, {"id": post_id})
        root = data.get(post_type)
        if root and root.get("language"):
            return root["language"]["code"].lower()
        return None

    # -- Relationship --

    def get_relationship(self, post_id: int, field: str) -> dict:
        """GET /cdcf/v1/relationship"""
        return self._wp_get("cdcf/v1/relationship", {"post_id": post_id, "field": field})

    def update_relationship(self, post_id: int, field: str, value: list[int]) -> dict:
        """POST /cdcf/v1/relationship"""
        return self._wp_post("cdcf/v1/relationship", {
            "post_id": post_id, "field": field, "value": value,
        })

    # -- Team Member --

    def create_team_member(self, title: str, content: str, council: str, **kwargs) -> dict:
        """POST /cdcf/v1/team-member

        Optional kwargs: member_title, member_role, member_linkedin_url,
                         member_github_url, featured_image_id (int)
        """
        data = {"title": title, "content": content, "council": council, **kwargs}
        return self._wp_post("cdcf/v1/team-member", data)

    # -- Community Channel --

    def create_community_channel(self, title: str, channel_description: str,
                                  channel_url: str, **kwargs) -> dict:
        """POST /cdcf/v1/community-channel

        Optional kwargs: channel_icon
        """
        data = {
            "title": title, "channel_description": channel_description,
            "channel_url": channel_url, **kwargs,
        }
        return self._wp_post("cdcf/v1/community-channel", data)

    # -- Local Group --

    def create_local_group(self, title: str, group_description: str,
                           group_url: str, **kwargs) -> dict:
        """POST /cdcf/v1/local-group

        Optional kwargs: group_location
        """
        data = {
            "title": title, "group_description": group_description,
            "group_url": group_url, **kwargs,
        }
        return self._wp_post("cdcf/v1/local-group", data)

    # -- Academic Collaboration --

    def create_academic_collaboration(self, title: str, collab_description: str,
                                       collab_university: str, **kwargs) -> dict:
        """POST /cdcf/v1/academic-collaboration

        Optional kwargs: collab_department, collab_location, collab_website_url
        """
        data = {
            "title": title, "collab_description": collab_description,
            "collab_university": collab_university, **kwargs,
        }
        return self._wp_post("cdcf/v1/academic-collaboration", data)

    # -- Refer Local Group (public) --

    def refer_local_group(self, group_name: str, description: str, url: str,
                          submitter_name: str, submitter_email: str, **kwargs) -> dict:
        """POST /cdcf/v1/refer-local-group

        Optional kwargs: location
        """
        data = {
            "group_name": group_name, "description": description, "url": url,
            "submitter_name": submitter_name, "submitter_email": submitter_email, **kwargs,
        }
        return self._wp_post("cdcf/v1/refer-local-group", data)

    # -- Submit Project --

    def submit_project_send_code(self, project_name: str, description: str, url: str,
                                  submitter_name: str, submitter_email: str, **kwargs) -> dict:
        """POST /cdcf/v1/submit-project/send-code

        Optional kwargs: repo_urls (list[str]), honeypot, elapsed_ms (float)
        """
        data = {
            "project_name": project_name, "description": description, "url": url,
            "submitter_name": submitter_name, "submitter_email": submitter_email, **kwargs,
        }
        return self._wp_post("cdcf/v1/submit-project/send-code", data)

    def submit_project(self, project_name: str, description: str, url: str,
                       submitter_name: str, submitter_email: str,
                       verification_code: str, **kwargs) -> dict:
        """POST /cdcf/v1/submit-project

        Optional kwargs: repo_urls (list[str])
        """
        data = {
            "project_name": project_name, "description": description, "url": url,
            "submitter_name": submitter_name, "submitter_email": submitter_email,
            "verification_code": verification_code, **kwargs,
        }
        return self._wp_post("cdcf/v1/submit-project", data)

    # -- Translation --

    def translate_post(self, source_id: int, target_lang: str, post_id: int = 0) -> dict:
        """POST /cdcf/v1/translate"""
        data = {"source_id": source_id, "target_lang": target_lang}
        if post_id:
            data["post_id"] = post_id
        return self._wp_post("cdcf/v1/translate", data)

    def deploy_translation(self, source_id: int, target_lang: str, content: str,
                           title: str | None = None) -> dict:
        """POST /cdcf/v1/deploy-translation"""
        data = {"source_id": source_id, "target_lang": target_lang, "content": content}
        if title is not None:
            data["title"] = title
        return self._wp_post("cdcf/v1/deploy-translation", data)

    def link_translations(self, translations: dict[str, int]) -> dict:
        """POST /cdcf/v1/link-translations

        translations: dict mapping language code to post ID, e.g. {"en": 10, "it": 12}
        """
        return self._wp_post("cdcf/v1/link-translations", {"translations": translations})

    # -- Project Status --

    def update_project_status(self, post_id: int, status: str) -> dict:
        """POST /cdcf/v1/project-status"""
        return self._wp_post("cdcf/v1/project-status", {
            "post_id": post_id, "status": status,
        })

    # -- Next.js Revalidation --

    def revalidate(self, path: str | None = None, tags: list[str] | None = None) -> dict:
        """POST /api/revalidate on the Next.js server."""
        data: dict = {}
        if self.preview_secret:
            data["secret"] = self.preview_secret
        if path:
            data["path"] = path
        if tags:
            data["tags"] = tags
        resp = requests.post(f"{self.nextjs_url}/api/revalidate", json=data, timeout=30)
        resp.raise_for_status()
        return resp.json()


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def _build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog="cdcf_api",
        description="CLI for the CDCF CMS REST API",
    )
    sub = parser.add_subparsers(dest="command", required=True)

    # get-relationship
    p = sub.add_parser("get-relationship", help="Read an ACF relationship field")
    p.add_argument("--post-id", type=int, required=True)
    p.add_argument("--field", required=True)

    # update-relationship
    p = sub.add_parser("update-relationship", help="Update an ACF relationship field")
    p.add_argument("--post-id", type=int, required=True)
    p.add_argument("--field", required=True)
    p.add_argument("--value", type=int, nargs="+", required=True, help="List of post IDs")

    # create-team-member
    p = sub.add_parser("create-team-member", help="Create team member with auto-translation")
    p.add_argument("--title", required=True)
    p.add_argument("--content", required=True)
    p.add_argument("--council", default="",
                   choices=["", "team_members", "ecclesial_council", "technical_council", "academic_council"],
                   help="Council to link to on the About page. Omit for project-only members.")
    p.add_argument("--member-title")
    p.add_argument("--member-role")
    p.add_argument("--member-linkedin-url")
    p.add_argument("--member-github-url")
    p.add_argument("--featured-image-id", type=int)
    p.add_argument("--collab-post-id", type=int, help="Academic collaboration post ID (required for academic_council)")

    # create-community-channel
    p = sub.add_parser("create-community-channel", help="Create community channel with auto-translation")
    p.add_argument("--title", required=True)
    p.add_argument("--channel-description", required=True)
    p.add_argument("--channel-url", required=True)
    p.add_argument("--channel-icon")

    # create-local-group
    p = sub.add_parser("create-local-group", help="Create local group with auto-translation")
    p.add_argument("--title", required=True)
    p.add_argument("--group-description", required=True)
    p.add_argument("--group-url", required=True)
    p.add_argument("--group-location")

    # create-academic-collaboration
    p = sub.add_parser("create-academic-collaboration", help="Create academic collaboration with auto-translation")
    p.add_argument("--title", required=True)
    p.add_argument("--collab-description", required=True)
    p.add_argument("--collab-university", required=True)
    p.add_argument("--collab-department")
    p.add_argument("--collab-location")
    p.add_argument("--collab-website-url")
    p.add_argument("--featured-image-id", type=int)

    # refer-local-group
    p = sub.add_parser("refer-local-group", help="Submit a local group referral")
    p.add_argument("--group-name", required=True)
    p.add_argument("--description", required=True)
    p.add_argument("--url", required=True)
    p.add_argument("--submitter-name", required=True)
    p.add_argument("--submitter-email", required=True)
    p.add_argument("--location")

    # submit-project-send-code
    p = sub.add_parser("submit-project-send-code", help="Request verification code for project submission")
    p.add_argument("--project-name", required=True)
    p.add_argument("--description", required=True)
    p.add_argument("--url", required=True)
    p.add_argument("--submitter-name", required=True)
    p.add_argument("--submitter-email", required=True)
    p.add_argument("--repo-urls", nargs="*")

    # submit-project
    p = sub.add_parser("submit-project", help="Submit a project with verification code")
    p.add_argument("--project-name", required=True)
    p.add_argument("--description", required=True)
    p.add_argument("--url", required=True)
    p.add_argument("--submitter-name", required=True)
    p.add_argument("--submitter-email", required=True)
    p.add_argument("--verification-code", required=True)
    p.add_argument("--repo-urls", nargs="*")

    # translate-post
    p = sub.add_parser("translate-post", help="Translate a post via OpenAI")
    p.add_argument("--source-id", type=int, required=True)
    p.add_argument("--target-lang", required=True)
    p.add_argument("--post-id", type=int, default=0)

    # deploy-translation
    p = sub.add_parser("deploy-translation", help="Deploy translated content to a post")
    p.add_argument("--source-id", type=int, required=True)
    p.add_argument("--target-lang", required=True)
    p.add_argument("--content", required=True)
    p.add_argument("--title")

    # link-translations
    p = sub.add_parser("link-translations", help="Link translation post IDs across languages")
    p.add_argument("--translations", required=True,
                   help='JSON object mapping lang to post ID, e.g. \'{"en":10,"it":12}\'')

    # update-project-status
    p = sub.add_parser("update-project-status", help="Update project approval status")
    p.add_argument("--post-id", type=int, required=True)
    p.add_argument("--status", required=True, choices=["approved", "rejected", "pending"])

    # revalidate
    p = sub.add_parser("revalidate", help="Revalidate Next.js cache")
    p.add_argument("--path", help="Path to revalidate")
    p.add_argument("--tags", nargs="*", help="Cache tags to revalidate")

    # -- Post Meta / ACF Fields --

    # get-post
    p = sub.add_parser("get-post", help="Get a post via WP REST API (includes meta)")
    p.add_argument("--post-id", type=int, required=True)
    p.add_argument("--post-type", default="posts",
                   help="REST API slug (posts, pages, project, team_member, etc.)")

    # get-meta
    p = sub.add_parser("get-meta", help="Read meta/ACF fields for a post")
    p.add_argument("--post-id", type=int, required=True)
    p.add_argument("--post-type", default="posts",
                   help="REST API slug (posts, pages, project, team_member, etc.)")
    p.add_argument("--field", help="Single field name to read (omit for all meta)")

    # update-meta
    p = sub.add_parser("update-meta", help="Update meta/ACF fields on a post")
    p.add_argument("--post-id", type=int, required=True)
    p.add_argument("--post-type", default="posts",
                   help="REST API slug (posts, pages, project, team_member, etc.)")
    p.add_argument("--fields", required=True,
                   help='JSON object of fields to set, e.g. \'{"member_title": "Lead Dev"}\'')

    # -- GraphQL commands --

    # graphql
    p = sub.add_parser("graphql", help="Execute an arbitrary WPGraphQL query")
    p.add_argument("--query", required=True, help="GraphQL query string")
    p.add_argument("--variables", help="JSON object of variables")

    # get-translation-ids
    p = sub.add_parser("get-translation-ids",
                       help="Get all Polylang translation post IDs for a post")
    p.add_argument("--post-id", type=int, required=True)
    p.add_argument("--post-type", default="page",
                   help="WPGraphQL type name (page, post, project, teamMember, "
                        "communityChannel, localGroup, sponsor, statItem)")

    # get-post-language
    p = sub.add_parser("get-post-language",
                       help="Get the Polylang language code for a post")
    p.add_argument("--post-id", type=int, required=True)
    p.add_argument("--post-type", default="page",
                   help="WPGraphQL type name (default: page)")

    return parser


def _run_cli(args: argparse.Namespace, client: CdcfClient) -> dict:
    cmd = args.command

    if cmd == "get-relationship":
        return client.get_relationship(args.post_id, args.field)

    if cmd == "update-relationship":
        return client.update_relationship(args.post_id, args.field, args.value)

    if cmd == "create-team-member":
        kwargs = {}
        for key in ("member_title", "member_role", "member_linkedin_url",
                     "member_github_url", "featured_image_id", "collab_post_id"):
            val = getattr(args, key.replace("-", "_"), None)
            if val is not None:
                kwargs[key] = val
        return client.create_team_member(args.title, args.content, args.council, **kwargs)

    if cmd == "create-community-channel":
        kwargs = {}
        if args.channel_icon:
            kwargs["channel_icon"] = args.channel_icon
        return client.create_community_channel(
            args.title, args.channel_description, args.channel_url, **kwargs)

    if cmd == "create-local-group":
        kwargs = {}
        if args.group_location:
            kwargs["group_location"] = args.group_location
        return client.create_local_group(
            args.title, args.group_description, args.group_url, **kwargs)

    if cmd == "create-academic-collaboration":
        kwargs = {}
        if args.collab_department:
            kwargs["collab_department"] = args.collab_department
        if args.collab_location:
            kwargs["collab_location"] = args.collab_location
        if args.collab_website_url:
            kwargs["collab_website_url"] = args.collab_website_url
        if args.featured_image_id:
            kwargs["featured_image_id"] = args.featured_image_id
        return client.create_academic_collaboration(
            args.title, args.collab_description, args.collab_university, **kwargs)

    if cmd == "refer-local-group":
        kwargs = {}
        if args.location:
            kwargs["location"] = args.location
        return client.refer_local_group(
            args.group_name, args.description, args.url,
            args.submitter_name, args.submitter_email, **kwargs)

    if cmd == "submit-project-send-code":
        kwargs = {}
        if args.repo_urls:
            kwargs["repo_urls"] = args.repo_urls
        return client.submit_project_send_code(
            args.project_name, args.description, args.url,
            args.submitter_name, args.submitter_email, **kwargs)

    if cmd == "submit-project":
        kwargs = {}
        if args.repo_urls:
            kwargs["repo_urls"] = args.repo_urls
        return client.submit_project(
            args.project_name, args.description, args.url,
            args.submitter_name, args.submitter_email,
            args.verification_code, **kwargs)

    if cmd == "translate-post":
        return client.translate_post(args.source_id, args.target_lang, args.post_id)

    if cmd == "deploy-translation":
        kwargs = {}
        if args.title:
            kwargs["title"] = args.title
        return client.deploy_translation(
            args.source_id, args.target_lang, args.content, **kwargs)

    if cmd == "link-translations":
        translations = json.loads(args.translations)
        return client.link_translations(translations)

    if cmd == "update-project-status":
        return client.update_project_status(args.post_id, args.status)

    if cmd == "revalidate":
        return client.revalidate(path=args.path, tags=args.tags)

    if cmd == "get-post":
        return client.get_post(args.post_id, args.post_type)

    if cmd == "get-meta":
        return client.get_meta(args.post_id, args.post_type, field=args.field)

    if cmd == "update-meta":
        fields = json.loads(args.fields)
        return client.update_meta(args.post_id, args.post_type, **fields)

    if cmd == "graphql":
        variables = json.loads(args.variables) if args.variables else None
        return client.graphql(args.query, variables)

    if cmd == "get-translation-ids":
        return client.get_translation_ids(args.post_id, args.post_type)

    if cmd == "get-post-language":
        lang = client.get_post_language(args.post_id, args.post_type)
        return {"post_id": args.post_id, "language": lang}

    raise SystemExit(f"Unknown command: {cmd}")


def main():
    parser = _build_parser()
    args = parser.parse_args()
    try:
        client = CdcfClient()
        result = _run_cli(args, client)
        print(json.dumps(result, indent=2, ensure_ascii=False))
    except requests.HTTPError as exc:
        print(json.dumps({
            "error": str(exc),
            "status_code": exc.response.status_code if exc.response is not None else None,
            "body": exc.response.text if exc.response is not None else None,
        }, indent=2, ensure_ascii=False), file=sys.stderr)
        raise SystemExit(1)
    except Exception as exc:
        print(json.dumps({"error": str(exc)}, indent=2), file=sys.stderr)
        raise SystemExit(1)


if __name__ == "__main__":
    main()
