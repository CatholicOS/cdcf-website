# Google Search Console access

The CDCF website domain is registered in Google Search Console as the property
`sc-domain:catholicdigitalcommons.org` (owner: `priest@johnromanodorazio.com`).

## Querying the GSC API from the CLI

Access is set up through gcloud Application Default Credentials with the
`webmasters.readonly` scope, using the GCP project **`linen-centaur-497501-a0`**
("Search Console for Claude") as the quota project.

One-time setup (already done on the dev machine):

```bash
gcloud auth application-default login \
  --scopes="openid,https://www.googleapis.com/auth/userinfo.email,https://www.googleapis.com/auth/cloud-platform,https://www.googleapis.com/auth/webmasters.readonly"
gcloud auth application-default set-quota-project linen-centaur-497501-a0
gcloud services enable searchconsole.googleapis.com --project=linen-centaur-497501-a0
```

> Note: `gcloud auth login` does **not** accept `--scopes` in recent gcloud
> versions — use `application-default login`, and `cloud-platform` must be
> included in the scope list.

### Example calls

```bash
TOKEN=$(gcloud auth application-default print-access-token)
HDR=(-H "Authorization: Bearer $TOKEN" -H "X-Goog-User-Project: linen-centaur-497501-a0")
SITE="sc-domain:catholicdigitalcommons.org"
ENC="sc-domain%3Acatholicdigitalcommons.org"

# List submitted sitemaps + index status
curl -s "${HDR[@]}" "https://searchconsole.googleapis.com/webmasters/v3/sites/$ENC/sitemaps"

# Inspect indexing status of a single URL
curl -s "${HDR[@]}" -X POST \
  "https://searchconsole.googleapis.com/v1/urlInspection/index:inspect" \
  -H "Content-Type: application/json" \
  -d '{"inspectionUrl":"https://catholicdigitalcommons.org/","siteUrl":"'"$SITE"'"}'

# Search performance (clicks/impressions)
curl -s "${HDR[@]}" -X POST \
  "https://searchconsole.googleapis.com/webmasters/v3/sites/$ENC/searchAnalytics/query" \
  -H "Content-Type: application/json" \
  -d '{"startDate":"2026-04-01","endDate":"2026-04-30","dimensions":["query"]}'
```

The `X-Goog-User-Project` header is required; without it the API returns 403
(quota project not set). The bulk Index Coverage report is UI-only — programmatic
indexing data comes from the Sitemaps and URL Inspection APIs.
