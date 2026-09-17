# Trusted Content Discovery

Drupal module that indexes trusted, syndicatable content from other CU Boulder Drupal sites and makes it searchable on a discovery hub.

**Installed on:** https://www.colorado.edu/trust/discovery

Producer sites mark content as trusted with [ucb_trust_schema](https://github.com/CuBoulder/ucb_trust_schema). This module pulls that metadata over JSON:API and stores local reference records so editors can use the Discovery View browse, filter, and reuse it on their sites.

## How it works

```
Producer sites (ucb_trust_schema)
        │
        │  JSON:API
        │  /jsonapi/trust_metadata/trust_metadata
        │  filter: trust_syndication_enabled = 1
        ▼
Discovery site (this module)
        │
        │  cron → TrustedContentSyncService
        ▼
ucb_trusted_content_reference entities
        │
        ▼
/content/discovery  (Views)
```

1. On a producer site, an editor opens a node, uses the **Trust Syndication** tab from Trust Schema, fills in trust metadata, and enables syndication.

2. On cron, this module reads the configured producer list from `ucb_trusted_content_discovery.sites` and requests each site’s Trust Schema JSON:API endpoint.

3. For every syndicated item, it creates or updates a **Trusted Content Reference** entity (title, summary, trust fields, images, source URL, telemetry). It does not copy the full node onto the hub.

4. Items that disappear from a producer’s feed are unpublished locally (soft delete), not removed. If they reappear, they are published again.

5. The **Discover CU Content** view at `/content/discovery` lists the indexed references with exposed filters.

## Supported remote node types:

- Basic page (`node--basic_page`)
- Article (`node--ucb_article`)
- Person (`node--ucb_person`)

## Configuring producer sites

Edit config `ucb_trusted_content_discovery.sites`. Each entry needs a public base URL. Optionally set an internal URL for DDEV development:

```yaml
sites:
  provider-a:
    public: 'https://www.colorado.edu/example-unit/'
    internal: ''
  provider-b:
    public: 'https://www.colorado.edu/another-unit/'
    internal: 'https://another-unit.ddev.site'
```

- **public** — canonical site URL
- **internal** — used only when `IS_DDEV_PROJECT=true`, so local environments can reach producers without going through the public host

## Sync behavior

The service `ucb_trusted_content_discovery.sync_service` (`TrustedContentSyncService`) runs from `hook_cron()`.

For each configured site it:

1. Calls `/jsonapi/trust_metadata/trust_metadata` with `trust_syndication_enabled=1`
2. Identifies each item with `md5(public_base_url + ':' + remote_uuid)`.
3. Creates a new reference, or updates an existing one when the remote node’s `changed` timestamp is newer (or when unpublished items return, or when type/timeliness/audience/telemetry need a backfill).
4. Maps remote Trust Topics to local `trust_topics` terms **by name**. Unmatched topic names are logged and skipped.
5. Copies wide/square focal image URLs and alt text from article thumbnails, person photos, or basic-page social sharing images.
6. Writes a telemetry row with consumer-site count, consumer-site list, and total views.
7. Unpublishes local references for that source site that were not in the latest feed.

### Manually run a sync:

```bash
drush cron
```

Watch the `ucb_trusted_content_discovery` log channel for fetch, skip, and unpublished-count messages.

## Local entities

### Trusted Content Reference (`ucb_trusted_content_reference`)

A lightweight index of one remote node. Notable fields:

| Field | Purpose |
| --- | --- |
| `title`, `summary` | Copied from the remote node |
| `trust_role`, `trust_scope`, `timeliness`, `type`, `audience` | Trust Schema metadata |
| `trust_topics` | Local taxonomy matches |
| `site_affiliation`, `content_authority` | Producer affiliation |
| `source_site`, `source_url`, `remote_path`, `remote_nid` | Where the original lives |
| `focal_image_wide`, `focal_image_square`, `focal_image_alt` | Image URLs for display |
| `syndication_consumer_sites`, `syndication_total_views` | Latest telemetry snapshot |
| `is_published` | Soft-delete flag |
| `last_updated`, `last_fetched` | Remote `changed` timestamps |
| `jsonapi_payload` | Raw JSON:API item |


### Trusted Content Telemetry (`ucb_trusted_content_telemetry`)

One row per sync of a reference: when it was fetched, how many consumer sites reported, the site list, and total views. The discovery view can join this table on `remote_uuid`.

## Discovery view
This provides the Discovery site with a visitor-filterable View of all the syndicated trusted content from across the configured sites, according to last sync.

Path: `/content/discovery`  
View ID: `ucb_trusted_discovery`  
Title: Discover CU Content

Exposed filters:

- Trust Scope
- Trust Role
- Subject (Trust Topics)
- Audience
- Timeliness

Exposed sorts:

- Consumer Sites Count
- Total Views

## Related modules

| Module | Role |
| --- | --- |
| [ucb_trust_schema](https://github.com/CuBoulder/ucb_trust_schema) | Producer: editors mark nodes as trusted and expose them over JSON:API |
| **ucb_trusted_content_discovery** (this module) | Hub: pulls those nodes and indexes them for discovery |
