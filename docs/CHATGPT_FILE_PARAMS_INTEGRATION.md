# ChatGPT File Descriptors & File Params for MCP

## Overview

This document describes how ilmu360° MCP tools support JSON file descriptors, including ChatGPT-style keys (`download_url`, `file_id`), alongside `content_base64` and `content_url`.

## What Changed

### Current behavior

#### 1. **McpFilePayloadNormalizer** (`app/Support/Mcp/McpFilePayloadNormalizer.php`)
- **Now accepts `download_url`** as a synonym for `content_url`
- **Ignores `file_id`** metadata (informational only from ChatGPT)
- **Improved error messages** to mention ChatGPT file param support
- **Supports both naming conventions**:
  - `content_url`, `contentUrl` (existing)
  - `download_url`, `downloadUrl` (ChatGPT)
  - `file_id`, `fileId` (metadata; ignored)

#### 2. **McpWriteSchemaFormatter** (`app/Support/Mcp/McpWriteSchemaFormatter.php`)
- **Updated `media_upload_transport`** from `'json_base64_descriptor'` to `'json_base64_descriptor_or_download_url'`
- **Enhanced `file_descriptor_shape`** documentation to list all supported fields:
  - `filename` (required)
  - `mime_type` (optional)
  - `content_base64` or `content_url` or `download_url` (required, one of)
  - `file_id` (ChatGPT metadata; optional, ignored)

#### 3. **MCP Tool Metadata policy**
- OpenAI Apps/Connectors `openai/fileParams` supports only **top-level object** fields, not arrays or nested subfields.
- In proxy-mounted environments, connector rewrite can fail before MCP dispatch (`ValueError: File arg rewrite paths are required when proxied mounts are present`).
- Therefore, ilmu360° event image tools currently use **descriptor-first mode** (no `openai/fileParams` on event upload/create tools) and rely on explicit descriptor objects in tool arguments.

## File Parameter Shape

### Accepted Formats

#### Descriptor with ChatGPT-style URL keys
```json
{
  "image": {
    "filename": "cover.png",
    "download_url": "https://api.openai.com/files/file_id/content",
    "file_id": "file_12345",
    "mime_type": "image/png"
  }
}
```

#### Traditional Base64 (Fallback)
```json
{
  "evidence": [
    {
      "filename": "proof.png",
      "content_base64": "iVBORw0KGgoAAAANSUhEUgAAAAUA...",
      "mime_type": "image/png"
    }
  ]
}
```

#### Traditional Content URL (Fallback)
```json
{
  "evidence": [
    {
      "filename": "proof.png",
      "content_url": "https://example.com/uploads/proof.png",
      "mime_type": "image/png"
    }
  ]
}
```

### Field Semantics

| Field | Required | Type | Notes |
|-------|----------|------|-------|
| `filename` | Yes | String | Client filename with extension; used for staged file naming |
| `content_base64` | No* | String or Data URL | Raw base64 bytes; supports `data:mime;base64,...` |
| `content_url` | No* | URL String | HTTP(S) URL; server fetches with no-redirect, no multipart |
| `download_url` | No* | URL String | ChatGPT file param; equivalent to `content_url` |
| `file_id` | No | String | ChatGPT file ID; metadata only, ignored by server |
| `mime_type` | No | String | IANA media type (e.g., `image/jpeg`); inferred from Content-Type if omitted |

**One of `content_base64`, `content_url`, or `download_url` is required per file descriptor.*

## Migration Path

### For ChatGPT Connector Developers

1. **Detect media fields** in the tool schema via `media_uploads_supported` and `file_descriptor_shape`
2. **Construct explicit descriptor objects** in tool arguments.
3. **Pass descriptors** with `content_base64` (most reliable in proxied clients) or `download_url`/`file_id`:
   ```javascript
     {
       image: {
         filename: "generated-cover.png",
         content_base64: "...",
         mime_type: "image/png"
       }
     }
   ```
4. **Server normalizes** to `UploadedFile` internally; validation continues as normal

### For Non-ChatGPT MCP Clients

- Continue using `content_base64` or `content_url`
- No changes required; full backward compatibility

## Tools Supporting Descriptor Uploads

### Member-Facing (Per-User Evidence)
- **member-submit-membership-claim** accepts descriptor arrays in `evidence` (max 8)

### Admin-Facing (Dynamic Resource Media)
- **admin-create-record** (payload object fields):
  - `cover`, `poster`, `gallery` (Event)
  - `logo`, `cover`, `gallery` (Institution)
  - `front_cover`, `back_cover`, `gallery` (Reference)
  - `evidence` (Report)
  - `avatar`, `cover`, `gallery` (Speaker)
  - `cover`, `gallery` (Venue)
  - `cover`, `gallery` (Series)
  - `qr` (DonationChannel)
  - `main` (Inspiration, Space)

- **admin-update-record** (same fields as create)

## Testing

### Unit Tests
- `tests/Unit/McpFilePayloadNormalizerTest.php`:
  - ✅ Accepts camelCase keys
  - ✅ Accepts `content_url` with charset params
  - ✅ **NEW**: Accepts ChatGPT `download_url`
  - ✅ **NEW**: Ignores `file_id` metadata

### Integration Tests
- `tests/Feature/Mcp/MemberServerTest.php`:
  - ✅ Member write schema includes file descriptor shape
  - ✅ Media upload transport updated to `json_base64_descriptor_or_download_url`

- `tests/Feature/Mcp/AdminServerTest.php`:
  - ✅ Admin write schema includes file descriptor shape
  - ✅ Media upload transport updated

## Error Handling

When files cannot be processed, the normalizer provides context-aware errors:

```
The MCP file descriptor must include either content_base64, content_url, or download_url. 
MCP tools do not accept multipart/form-data payloads. 
Clients may pass `{download_url, file_id}` inside descriptor objects.
```

For failed downloads:
```
The MCP file descriptor (file_id: file_12345) could not be decoded.
```

## Security Notes

- **SSRF Protection**: `content_url` and `download_url` are validated as safe HTTP(S) URLs
  - No redirects allowed
  - Localhost/private IPs rejected (see `assertSafeContentUrl()`)
  - 5s connect timeout, 20s read timeout

- **File Validation**: Same as non-ChatGPT paths
  - MIME type whitelist per field
  - Max file size enforced
  - Max files per collection enforced

- **Metadata**: `file_id` is never persisted; only used during normalization

## References

- **OpenAI Apps SDK**: https://developers.openai.com/apps-sdk/build/mcp-server
- **OpenAI File Params**: https://developers.openai.com/api/docs/mcp#file-params-meta-extension
- **MCP Protocol**: https://modelcontextprotocol.io/

## Troubleshooting

### ChatGPT Connector Not Recognizing File Fields

**Check**:
1. Do not declare `_meta['openai/fileParams']` for array fields or nested payload subfields
2. Schema includes `file_descriptor_shape` documentation
3. Field is declared with type `file` or `array<file>`

If your connector environment is proxy-mounted, prefer explicit descriptors (`content_base64`) and avoid rewrite-dependent file-param metadata.

### Download URL Fails

**Check**:
1. URL is absolute HTTP(S)
2. No redirects are used (e.g., short URLs must resolve to final URL)
3. Server is accessible from Laravel app environment
4. File is non-empty (>0 bytes)
5. Content-Type header is present and valid
6. If the client/proxy layer throws rewrite/mount errors before MCP dispatch (e.g., "File arg rewrite paths are required when proxied mounts are present"), retry with `content_base64` instead of `download_url`.

### Event upload tools and fileParams

- `admin-upload-event-cover-image`
- `admin-upload-event-poster-image`
- `member-upload-event-cover-image`
- `member-upload-event-poster-image`
- `admin-create-event`

These tools currently run in descriptor-first mode for compatibility with proxied connector mounts. They accept descriptor objects directly and do not rely on `openai/fileParams` rewrite behavior.

### MIME Type Mismatch

**Check**:
1. `mime_type` in descriptor matches allowed types for field
2. `Content-Type` response header from `download_url` is valid
3. File extension in `filename` can infer correct type if MIME omitted

---

**Last Updated**: May 2026  
**Status**: Implemented & Tested ✅
