#!/usr/bin/env python3
"""
Fix broken Polylang translation linkages for ai-governance child pages.

Reads the current state from WPGraphQL, identifies:
  1. Existing translations with wrong post_parent (fixes via REST API)
  2. Missing translations (triggers /cdcf/v1/translate to create them)

Usage:
  scripts/.venv/bin/python scripts/fix-governance-translations.py [--dry-run]
"""

import argparse
import sys
import time

sys.path.insert(0, "scripts")
from cdcf_api import CdcfClient  # noqa: E402

LANGS = ["it", "es", "fr", "pt", "de"]

# ai-governance parent page IDs per language (EN=977)
AI_GOV_PARENTS = {
    "en": 977,
    "it": 989,
    "es": 990,
    "fr": 991,
    "pt": 992,
    "de": 993,
}


def get_child_pages(client: CdcfClient, parent_id: int) -> list[dict]:
    """Get English child pages under a parent."""
    result = client.graphql(
        """
        query($parentId: ID!) {
          pages(where: { parent: $parentId, language: EN }, first: 50) {
            nodes {
              databaseId title slug
              translations {
                databaseId slug
                language { slug }
              }
            }
          }
        }
        """,
        variables={"parentId": parent_id},
    )
    return result["pages"]["nodes"]


def get_post_parent(client: CdcfClient, post_id: int) -> int:
    """Get the post_parent of a post via REST API."""
    import requests

    resp = requests.get(
        client._wp_url(f"/wp/v2/pages/{post_id}"),
        auth=client.wp_auth,
        params={"_fields": "id,parent"},
    )
    resp.raise_for_status()
    return resp.json()["parent"]


def set_post_parent(client: CdcfClient, post_id: int, parent_id: int) -> None:
    """Update a post's parent via REST API."""
    import requests

    resp = requests.post(
        client._wp_url(f"/wp/v2/pages/{post_id}"),
        auth=client.wp_auth,
        json={"parent": parent_id},
    )
    resp.raise_for_status()


def trigger_translate(client: CdcfClient, source_id: int, target_lang: str) -> dict:
    """Call /cdcf/v1/translate to create a translation."""
    import requests

    resp = requests.post(
        client._wp_url("/cdcf/v1/translate"),
        auth=client.wp_auth,
        json={"source_id": source_id, "target_lang": target_lang},
    )
    resp.raise_for_status()
    return resp.json()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="Show what would be done without making changes",
    )
    args = parser.parse_args()

    client = CdcfClient()
    pages = get_child_pages(client, AI_GOV_PARENTS["en"])

    fixes_needed = []
    translations_needed = []

    # Audit
    for page in pages:
        en_id = page["databaseId"]
        translations = {
            t["language"]["slug"]: t["databaseId"]
            for t in (page.get("translations") or [])
        }

        for lang in LANGS:
            expected_parent = AI_GOV_PARENTS[lang]

            if lang in translations:
                # Translation exists — check parent
                trans_id = translations[lang]
                actual_parent = get_post_parent(client, trans_id)
                if actual_parent != expected_parent:
                    fixes_needed.append(
                        {
                            "en_id": en_id,
                            "trans_id": trans_id,
                            "lang": lang,
                            "slug": page["slug"],
                            "actual_parent": actual_parent,
                            "expected_parent": expected_parent,
                        }
                    )
            else:
                # Translation missing entirely
                translations_needed.append(
                    {
                        "en_id": en_id,
                        "lang": lang,
                        "slug": page["slug"],
                        "title": page["title"],
                    }
                )

    # Report
    print(f"Parent fixes needed: {len(fixes_needed)}")
    for f in fixes_needed:
        print(
            f"  [{f['lang']}] page {f['trans_id']} ({f['slug']}): "
            f"parent {f['actual_parent']} -> {f['expected_parent']}"
        )

    print(f"\nTranslations to create: {len(translations_needed)}")
    for t in translations_needed:
        print(f"  [{t['lang']}] {t['title'][:50]} (EN id={t['en_id']})")

    if not fixes_needed and not translations_needed:
        print("\nNothing to fix!")
        return

    if args.dry_run:
        print("\n--dry-run: no changes made.")
        return

    # Apply fixes
    print("\nApplying fixes...")

    for f in fixes_needed:
        print(
            f"  Fixing parent for page {f['trans_id']} ({f['lang']})...",
            end=" ",
        )
        set_post_parent(client, f["trans_id"], f["expected_parent"])
        print("done")

    for t in translations_needed:
        print(
            f"  Creating {t['lang']} translation for '{t['title'][:40]}'...",
            end=" ",
        )
        result = trigger_translate(client, t["en_id"], t["lang"])
        post_id = result.get("post_id", "?")
        print(f"queued (post_id={post_id})")
        time.sleep(0.5)  # be gentle with the API

    print("\nDone. Translations are queued and will be processed asynchronously.")


if __name__ == "__main__":
    main()
