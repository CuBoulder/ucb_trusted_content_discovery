<!-- This is just to test the entity creation via CLI -->

```bash
ddev drush php:eval '
use Drupal\ucb_trusted_content_discovery\Entity\TrustedContentReference;

$entity = TrustedContentReference::create([
  "title" => "Test Item",
  "summary" => "This is a summary.",
  "trust_role" => "primary_source",
  "trust_scope" => "college_level",
  "source_site" => "trusted-site.local",
  "source_url" => "https://trusted-site.local/test-item",
  "remote_uuid" => "abcd1234-5678-90ef-ghij-klmnopqrstuv",
  "remote_type" => "ucb_article",
  "last_fetched" => \Drupal::time()->getRequestTime(),
  "jsonapi_payload" => "{}",
]);

$entity->save();
\Drupal::logger("custom")->notice("Entity created with ID: " . $entity->id());
'
```

```text
jsonapi/trust_metadata/trust_metadata
?include=trust_topics,node_id,node_id.field_ucb_article_thumbnail,node_id.field_ucb_article_thumbnail.field_media_image
&fields[trust_metadata--trust_metadata]=trust_role,trust_scope,trust_contact,trust_topics,node_id
&fields[taxonomy_term--trust_topics]=name
&fields[node--basic_page]=title,body
&fields[node--ucb_person]=title,body
&fields[node--ucb_article]=title,field_ucb_article_summary,field_ucb_article_thumbnail
&fields[media--image]=field_media_image
&fields[file--file]=uri,url
```
