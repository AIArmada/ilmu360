# MCP file descriptor contract

MCP write tools accept files inside JSON descriptor objects. Multipart uploads and alternate field spellings are not part of the contract.

## Canonical descriptor

Every descriptor requires `filename`, may include `mime_type`, and must include exactly one content source: `content_base64`, `content_url`, or `download_url`.

```json
{
  "filename": "authorization-letter.pdf",
  "mime_type": "application/pdf",
  "download_url": "https://api.openai.com/files/file_123/content"
}
```

`content_base64` accepts raw base64 or a data URL. `content_url` and `download_url` accept absolute HTTP(S) URLs. Descriptors reject unknown keys and reject requests containing multiple content sources.

Generic create/update tools accept this descriptor in a media field inside `payload`. Single-file fields take one descriptor object; multi-file fields take an array of descriptor objects. The generic descriptor does not accept `file_id`.

Standalone event image upload tools (`admin-upload-event-cover-image`, `admin-upload-event-poster-image`, `member-upload-event-cover-image`, and `member-upload-event-poster-image`) accept the descriptor under `image`. Those tools also accept optional `file_id` metadata, which is stored as `chatgpt_file_id`; it is not a content source. Event image upload schemas expose this field explicitly.

## Schema discovery

Call `admin-get-write-schema` or `member-get-write-schema` before a generic write. The returned field metadata identifies the upload transport, descriptor shape, replacement behavior, MIME types, file-size limit, and file-count limit. `exactly_one_of` lists the required content-source choice.

The transport is `json_file_descriptor`. Use the advertised field schema as the authority for each resource and operation.

## URL and file validation

- URL sources must use HTTP(S), resolve to public hosts, contain no embedded credentials, and must not redirect.
- The field schema's accepted MIME types, maximum size, and maximum file count are enforced by the server.
- Include a filename extension when available. Otherwise, the server derives the staged extension from the MIME type or response content type.
- Validation previews normalize descriptors without persisting media; they still clean up temporary files.

## Examples

Base64 evidence file:

```json
{
  "filename": "authorization-letter.pdf",
  "mime_type": "application/pdf",
  "content_base64": "JVBERi0xLjQK..."
}
```

Public URL evidence file:

```json
{
  "filename": "authorization-letter.pdf",
  "content_url": "https://files.example.org/authorization-letter.pdf"
}
```

ChatGPT generated event image:

```json
{
  "event_key": "weekly-lecture",
  "image": {
    "filename": "event-cover.png",
    "mime_type": "image/png",
    "download_url": "https://api.openai.com/files/file_456/content",
    "file_id": "file_456"
  }
}
```

The event upload tools validate cover images at 16:9 and poster images at 3:4. Membership evidence uses the `member-submit-membership-application` tool.
