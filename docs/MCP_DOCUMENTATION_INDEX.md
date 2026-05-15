# ilmu360° MCP Documentation - Complete Index

**Choose the right guide for your role and audience — and yes, the user guides are written in normal human language, not programmer language, so regular members and committee people can actually use them.**

---

## 📚 Guide Selection Matrix

### For Users (Non-Technical)

| Audience | Language | Guide | Read If... |
|----------|----------|-------|-----------|
| **End Users / Members** | English | [MCP User Guide (English)](MCP_USER_GUIDE_ENGLISH.md) | You want to learn how to use MCP through ChatGPT or other AI assistants. Written in simple, conversational language with practical examples. |
| **Pengguna Akhir / Ahli** | Bahasa Melayu | [Panduan Pengguna MCP (Bahasa Melayu)](MCP_USER_GUIDE_MALAY.md) | Anda ingin belajar menggunakan MCP melalui ChatGPT atau pembantu AI lain. Ditulis dengan gaya santai, bahasa harian, dan nada yang lebih dekat dengan cara orang kita biasa bercakap. |

### For Developers & AI Agents

| Audience | Language | Guide | Read If... |
|----------|----------|-------|-----------|
| **Programmers & Developers** | English | [ilmu360_mcp_guide.md](ilmu360_mcp_guide.md) | You need technical details, architecture, authentication, and connector setup. |
| **Admin AI Agents** | English | [ilmu360_mcp_admin_agent_guide.md](ilmu360_mcp_admin_agent_guide.md) | You're writing AI agents to access the admin MCP surface. Covers tools, resources, workflows. |
| **Member AI Agents** | English | [ilmu360_mcp_member_agent_guide.md](ilmu360_mcp_member_agent_guide.md) | You're writing AI agents to access the member MCP surface. Covers member workflows and limitations. |
| **Tool Integrators** | English | [ilmu360_mcp_tool_examples.md](ilmu360_mcp_tool_examples.md) | You need copy-paste examples of how to call MCP tools in JSON. |
| **ChatGPT Connector Builders** | English | [CHATGPT_FILE_PARAMS_INTEGRATION.md](CHATGPT_FILE_PARAMS_INTEGRATION.md) | You're building a ChatGPT connector and need to understand file upload support. |
| **CSV/JSON Event Import Operators** | English | [ilmu360_mcp_event_csv_json_creation_guide.md](ilmu360_mcp_event_csv_json_creation_guide.md) | You need a safe, correction-aware workflow for creating events from spreadsheet-style data through MCP tools. |

---

## 🎯 Quick Start: What Should I Read?

### "I'm a regular member who wants to use AI to access ilmu360°"
→ Read: **[MCP User Guide (English)](MCP_USER_GUIDE_ENGLISH.md)** or **[Panduan Pengguna MCP (Bahasa Melayu)](MCP_USER_GUIDE_MALAY.md)**

✅ You'll learn:
- How to connect ChatGPT to ilmu360°
- How to ask questions naturally
- What responses look like
- How to upload files
- How to track your submissions
- Common questions answered in plain, normal language
- Explanations that don’t assume you are technical

---

### "I'm developing a ChatGPT connector that uses MCP"
→ Read: **[ilmu360_mcp_guide.md](ilmu360_mcp_guide.md)** + **[ilmu360_mcp_admin_agent_guide.md](ilmu360_mcp_admin_agent_guide.md)** or **[ilmu360_mcp_member_agent_guide.md](ilmu360_mcp_member_agent_guide.md)**

✅ You'll learn:
- Authentication & bearer tokens
- OAuth setup
- Available tools and their signatures
- How to handle responses
- Schema discovery
- Error handling
- Widget behavior (if ChatGPT-specific)

---

### "I'm writing an AI agent to interact with MCP"
→ Read: **[ilmu360_mcp_admin_agent_guide.md](ilmu360_mcp_admin_agent_guide.md)** or **[ilmu360_mcp_member_agent_guide.md](ilmu360_mcp_member_agent_guide.md)**

✅ You'll learn:
- What the agent can access
- How to structure tool calls
- Workflow patterns
- Error recovery
- Best practices
- When to use which tools

---

### "I need JSON examples of tool calls"
→ Read: **[ilmu360_mcp_tool_examples.md](ilmu360_mcp_tool_examples.md)**

✅ You'll get:
- Copy-paste JSON for common operations
- File upload examples
- Filter syntax
- Pagination examples

---

### "I'm importing events from CSV/JSON and need a safe workflow"
→ Read: **[ilmu360_mcp_event_csv_json_creation_guide.md](ilmu360_mcp_event_csv_json_creation_guide.md)**

✅ You'll learn:
- Source-of-truth and correction precedence
- Institution/speaker/reference resolution strategy
- Prayer-time and event-type normalization
- Duplicate checks before create
- Validate-first, chunked batch execution

---

### "I'm integrating MCP file descriptors (content_base64 / download_url)"
→ Read: **[CHATGPT_FILE_PARAMS_INTEGRATION.md](CHATGPT_FILE_PARAMS_INTEGRATION.md)**

✅ You'll learn:
- Descriptor formats (`content_base64`, `content_url`, `download_url`, `file_id`)
- Proxy-safe upload guidance for connector environments
- How to detect media-capable fields
- Security validation rules

---

## 📖 Document Purposes at a Glance

| Document | Purpose | Audience | Length |
|----------|---------|----------|--------|
| **MCP_USER_GUIDE_ENGLISH.md** | Teach non-technical users how to use MCP through AI | Members, volunteers | Long (comprehensive) |
| **MCP_USER_GUIDE_MALAY.md** | Teach Malay-speaking users how to use MCP | Ahli, staff | Long (comprehensive) |
| **ilmu360_mcp_guide.md** | Overview of MCP architecture, setup, connectors | Developers | Medium |
| **ilmu360_mcp_admin_agent_guide.md** | Complete guide for AI agents on admin surface | AI agents, tool builders | Medium |
| **ilmu360_mcp_member_agent_guide.md** | Complete guide for AI agents on member surface | AI agents, tool builders | Medium |
| **ilmu360_mcp_tool_examples.md** | JSON code examples for tool calls | Developers, integrators | Short |
| **CHATGPT_FILE_PARAMS_INTEGRATION.md** | ChatGPT file parameter support documentation | Connector builders | Short |
| **ilmu360_mcp_event_csv_json_creation_guide.md** | Safe event-creation workflow from CSV/JSON source data via MCP | MCP operators, AI agents | Medium |

---

## 🌐 Language Support

| Document | English | Bahasa Melayu | Notes |
|----------|---------|---------------|-------|
| User Guide | ✅ [MCP_USER_GUIDE_ENGLISH.md](MCP_USER_GUIDE_ENGLISH.md) | ✅ [MCP_USER_GUIDE_MALAY.md](MCP_USER_GUIDE_MALAY.md) | Identical content, both fully translated |
| Developer Guide | ✅ [ilmu360_mcp_guide.md](ilmu360_mcp_guide.md) | — | Technical docs in English only |
| Admin Agent Guide | ✅ [ilmu360_mcp_admin_agent_guide.md](ilmu360_mcp_admin_agent_guide.md) | — | For AI agents (English sufficient) |
| Member Agent Guide | ✅ [ilmu360_mcp_member_agent_guide.md](ilmu360_mcp_member_agent_guide.md) | — | For AI agents (English sufficient) |
| Tool Examples | ✅ [ilmu360_mcp_tool_examples.md](ilmu360_mcp_tool_examples.md) | — | JSON is language-agnostic |
| File Descriptors | ✅ [CHATGPT_FILE_PARAMS_INTEGRATION.md](CHATGPT_FILE_PARAMS_INTEGRATION.md) | — | For technical integrators |
| CSV/JSON Event Creation Playbook | ✅ [ilmu360_mcp_event_csv_json_creation_guide.md](ilmu360_mcp_event_csv_json_creation_guide.md) | — | Operational safety checklist for event imports |

---

## 📑 Document Structure Overview

### User Guides (English & Malay)
1. **What is MCP?** - Simple explanation
2. **Setup** - How to connect
3. **Examples** - Real-world questions
4. **Understanding Responses** - What you'll see
5. **Widgets** - Interactive elements explained
6. **What You Can Do** - Complete feature list
7. **FAQ** - Common questions
8. **Privacy & Security** - What's protected
9. **Troubleshooting** - How to fix problems
10. **Glossary** - Definitions of terms

### Developer Guides
1. **Overview** - Purpose and scope
2. **Setup/Auth** - Connection details
3. **Tools** - Available operations
4. **Resources** - Data access
5. **Workflows** - Common patterns
6. **Error Handling** - What can go wrong
7. **Best Practices** - How to integrate well
8. **Catalog** - Complete tool reference

---

## 🔍 Search Tips

**Looking for:**
- "How do I...?" → See **User Guides**
- "How do I set up..." → See **ilmu360_mcp_guide.md**
- "What JSON do I send?" → See **ilmu360_mcp_tool_examples.md**
- "How do I handle file uploads?" → See **CHATGPT_FILE_PARAMS_INTEGRATION.md**
- "What can a member do?" → See **ilmu360_mcp_member_agent_guide.md**
- "What errors can happen?" → See **ilmu360_mcp_admin_agent_guide.md**

---

## 📝 Version & Updates

| Document | Last Updated | Status |
|----------|--------------|--------|
| MCP_USER_GUIDE_ENGLISH.md | April 29, 2026 | ✅ Current |
| MCP_USER_GUIDE_MALAY.md | April 29, 2026 | ✅ Current |
| ilmu360_mcp_guide.md | April 25, 2026 | ✅ Current |
| ilmu360_mcp_admin_agent_guide.md | April 28, 2026 | ✅ Current |
| ilmu360_mcp_member_agent_guide.md | April 28, 2026 | ✅ Current |
| ilmu360_mcp_tool_examples.md | April 29, 2026 | ✅ Current |
| CHATGPT_FILE_PARAMS_INTEGRATION.md | April 29, 2026 | ✅ Current |
| ilmu360_mcp_event_csv_json_creation_guide.md | May 5, 2026 | ✅ Current |

---

## 🎓 Learning Path Recommendations

### For Members (Non-Technical)

**Beginner:**
1. Read: [MCP User Guide (English)](MCP_USER_GUIDE_ENGLISH.md) or [Panduan Pengguna MCP](MCP_USER_GUIDE_MALAY.md)
2. Try: Connect to ilmu360° through ChatGPT
3. Ask: "Show me events near me"
4. Reference: FAQ section when confused

**Intermediate:**
1. Reference: "What You Can Do" section
2. Try: Upload documents for membership claims
3. Explore: Advanced search filters
4. Track: Your submissions and status

**Advanced:**
1. Automate: Recurring searches using ChatGPT workflows
2. Integrate: MCP into your AI assistant setup
3. Feedback: Share feature requests

---

### For Developers

**Beginner:**
1. Read: [ilmu360_mcp_guide.md](ilmu360_mcp_guide.md)
2. Setup: Get a bearer token via `php artisan mcp:token`
3. Test: List resources using local MCP handle

**Intermediate:**
1. Read: [ilmu360_mcp_tool_examples.md](ilmu360_mcp_tool_examples.md)
2. Study: JSON examples
3. Test: Call tools from your client
4. Reference: Error handling section

**Advanced:**
1. Read: [ilmu360_mcp_admin_agent_guide.md](ilmu360_mcp_admin_agent_guide.md) or [ilmu360_mcp_member_agent_guide.md](ilmu360_mcp_member_agent_guide.md)
2. Integrate: Into ChatGPT or custom AI
3. Reference: [CHATGPT_FILE_PARAMS_INTEGRATION.md](CHATGPT_FILE_PARAMS_INTEGRATION.md) for media support
4. Deploy: Production connector

---

## 💡 Pro Tips

- **Bookmark** the User Guide that matches your language
- **Refer** to the appropriate Developer Guide based on your surface (admin/member)
- **Copy** JSON from Tool Examples into your integration
- **Search** this index first when looking for documentation
- **Share** the User Guide link with non-technical team members, committee members, and volunteers first before sending them the technical docs

---

## 🆘 Still Can't Find What You Need?

1. Check the **Glossary** in the User Guides (terms explained)
2. Try **Ctrl+F** (browser search) to find a keyword
3. Read the **FAQ** section in the User Guide
4. Ask your **AI assistant** directly (e.g., ChatGPT)
5. **Contact support** with a screenshot and your question

---

**Questions? Start with the User Guide or Developer Guide that matches your role above!**

Last updated: April 29, 2026
