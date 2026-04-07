# Journal Entries API Documentation

## Overview
The Journal Entries API provides comprehensive access to journal entry data for integration with the Administrative module and external systems. This API uses API key authentication and follows RESTful principles.

## Base URL
```
https://your-domain.com/api/v1/journal_entries.php
```

## Authentication
All requests require API key authentication. Include your API key in one of the following ways:

### Option 1: Authorization Header (Recommended)
```
Authorization: Bearer YOUR_API_KEY
```

### Option 2: X-API-Key Header
```
X-API-Key: YOUR_API_KEY
```

### Option 3: Query Parameter (Less Secure)
```
Authorization: Bearer YOUR_API_KEY
```

## Rate Limits
- Default: 100 requests per hour per API client
- Configurable in system settings

---

## Endpoints

### 1. Get All Journal Entries

Retrieve a paginated list of journal entries with optional filters.

**Request:**
```
GET /api/v1/journal_entries.php
```

**Query Parameters:**
| Parameter | Type | Description |
|-----------|------|-------------|
| `limit` | integer | Number of records per page (default: 50, max: 200) |
| `offset` | integer | Offset for pagination (default: 0) |
| `status` | string | Filter by status: `draft`, `approved`, `posted` |
| `date_from` | date | Filter entries from this date (YYYY-MM-DD) |
| `date_to` | date | Filter entries up to this date (YYYY-MM-DD) |
| `account_id` | integer | Filter entries containing this account |
| `entry_number` | string | Search by entry number (partial match) |
| `min_amount` | decimal | Minimum total debit amount |
| `max_amount` | decimal | Maximum total debit amount |
| `include_lines` | boolean | Include journal entry lines (default: false) |

**Example Request:**
```bash
curl -X GET "https://your-domain.com/api/v1/journal_entries.php?status=posted&date_from=2025-01-01&limit=20&include_lines=true" \
  -H "Authorization: Bearer YOUR_API_KEY"
```

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 123,
      "entry_number": "JE-2025-1001-0001",
      "entry_date": "2025-01-15",
      "description": "Monthly revenue recognition",
      "total_debit": 5000.00,
      "total_credit": 5000.00,
      "status": "posted",
      "created_by": 1,
      "created_by_name": "John Doe",
      "posted_by": 2,
      "posted_by_name": "Jane Smith",
      "posted_at": "2025-01-15 14:30:00",
      "created_at": "2025-01-15 10:00:00",
      "updated_at": "2025-01-15 14:30:00",
      "line_count": 2,
      "lines": [
        {
          "id": 245,
          "journal_entry_id": 123,
          "account_id": 15,
          "account_code": "1101",
          "account_name": "Accounts Receivable",
          "account_type": "Asset",
          "debit": 5000.00,
          "credit": 0.00,
          "description": "Customer invoice revenue"
        },
        {
          "id": 246,
          "journal_entry_id": 123,
          "account_id": 45,
          "account_code": "4001",
          "account_name": "Sales Revenue",
          "account_type": "Revenue",
          "debit": 0.00,
          "credit": 5000.00,
          "description": "Revenue from services"
        }
      ]
    }
  ],
  "pagination": {
    "total": 150,
    "limit": 20,
    "offset": 0,
    "has_more": true
  }
}
```

---

### 2. Get Single Journal Entry by ID

Retrieve detailed information about a specific journal entry including all lines.

**Request:**
```
GET /api/v1/journal_entries.php?id={entry_id}
```

**Example Request:**
```bash
curl -X GET "https://your-domain.com/api/v1/journal_entries.php?id=123" \
  -H "Authorization: Bearer YOUR_API_KEY"
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 123,
    "entry_number": "JE-2025-1001-0001",
    "entry_date": "2025-01-15",
    "description": "Monthly revenue recognition",
    "total_debit": 5000.00,
    "total_credit": 5000.00,
    "status": "posted",
    "created_by": 1,
    "created_by_name": "John Doe",
    "created_by_email": "john@example.com",
    "posted_by": 2,
    "posted_by_name": "Jane Smith",
    "posted_by_email": "jane@example.com",
    "posted_at": "2025-01-15 14:30:00",
    "created_at": "2025-01-15 10:00:00",
    "updated_at": "2025-01-15 14:30:00",
    "lines": [
      {
        "id": 245,
        "journal_entry_id": 123,
        "account_id": 15,
        "account_code": "1101",
        "account_name": "Accounts Receivable",
        "account_type": "Asset",
        "normal_balance": "debit",
        "debit": 5000.00,
        "credit": 0.00,
        "description": "Customer invoice revenue"
      },
      {
        "id": 246,
        "journal_entry_id": 123,
        "account_id": 45,
        "account_code": "4001",
        "account_name": "Sales Revenue",
        "account_type": "Revenue",
        "normal_balance": "credit",
        "debit": 0.00,
        "credit": 5000.00,
        "description": "Revenue from services"
      }
    ],
    "statistics": {
      "total_lines": 2,
      "balanced": true
    }
  }
}
```

---

### 3. Get Journal Entry by Reference Number

Retrieve a journal entry using its entry number (reference).

**Request:**
```
GET /api/v1/journal_entries.php?reference={entry_number}
```

**Example Request:**
```bash
curl -X GET "https://your-domain.com/api/v1/journal_entries.php?reference=JE-2025-1001-0001" \
  -H "Authorization: Bearer YOUR_API_KEY"
```

**Response:** Same format as "Get Single Journal Entry by ID"

---

### 4. Get Journal Entries Summary

Retrieve comprehensive summary statistics for administrative reporting.

**Request:**
```
GET /api/v1/journal_entries.php?action=summary
```

**Example Request:**
```bash
curl -X GET "https://your-domain.com/api/v1/journal_entries.php?action=summary" \
  -H "Authorization: Bearer YOUR_API_KEY"
```

**Response:**
```json
{
  "success": true,
  "data": {
    "overall_statistics": {
      "total_entries": 1250,
      "posted_count": 1100,
      "draft_count": 120,
      "approved_count": 30,
      "total_debit": 15500000.00,
      "total_credit": 15500000.00,
      "earliest_entry": "2023-01-01",
      "latest_entry": "2025-10-25"
    },
    "status_summary": [
      {
        "status": "posted",
        "count": 1100,
        "total_debit": 14000000.00,
        "total_credit": 14000000.00
      },
      {
        "status": "draft",
        "count": 120,
        "total_debit": 1200000.00,
        "total_credit": 1200000.00
      },
      {
        "status": "approved",
        "count": 30,
        "total_debit": 300000.00,
        "total_credit": 300000.00
      }
    ],
    "monthly_summary": [
      {
        "month": "2025-10",
        "count": 85,
        "total_debit": 850000.00,
        "total_credit": 850000.00
      },
      {
        "month": "2025-09",
        "count": 92,
        "total_debit": 920000.00,
        "total_credit": 920000.00
      }
    ],
    "top_accounts": [
      {
        "account_code": "1101",
        "account_name": "Accounts Receivable",
        "account_type": "Asset",
        "entry_count": 450,
        "total_debit": 2500000.00,
        "total_credit": 0.00
      },
      {
        "account_code": "4001",
        "account_name": "Sales Revenue",
        "account_type": "Revenue",
        "entry_count": 450,
        "total_debit": 0.00,
        "total_credit": 2500000.00
      }
    ]
  }
}
```

---

### 5. Create Journal Entry

Create a new journal entry with multiple lines.

**Request:**
```
POST /api/v1/journal_entries.php
Content-Type: application/json
```

**Request Body:**
```json
{
  "entry_date": "2025-10-25",
  "description": "Monthly expense allocation",
  "status": "draft",
  "lines": [
    {
      "account_id": 25,
      "debit": 1500.00,
      "credit": 0.00,
      "description": "Office rent expense"
    },
    {
      "account_id": 10,
      "debit": 0.00,
      "credit": 1500.00,
      "description": "Cash payment for rent"
    }
  ]
}
```

**Optional Fields:**
- `entry_number`: Custom entry number (must be unique)
- `status`: Entry status - `draft` (default), `approved`, or `posted`

**Validation Rules:**
1. At least 2 lines required (double-entry accounting)
2. Total debits must equal total credits
3. Each line must have either debit or credit (not both)
4. Each line must have a valid account_id

**Example Request:**
```bash
curl -X POST "https://your-domain.com/api/v1/journal_entries.php" \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "entry_date": "2025-10-25",
    "description": "Monthly expense allocation",
    "status": "draft",
    "lines": [
      {
        "account_id": 25,
        "debit": 1500.00,
        "credit": 0.00,
        "description": "Office rent expense"
      },
      {
        "account_id": 10,
        "debit": 0.00,
        "credit": 1500.00,
        "description": "Cash payment for rent"
      }
    ]
  }'
```

**Success Response:**
```json
{
  "success": true,
  "data": {
    "id": 124,
    "entry_number": "JE-2025-1001-0124",
    "total_debit": 1500.00,
    "total_credit": 1500.00,
    "status": "draft"
  }
}
```

**Error Response:**
```json
{
  "success": false,
  "error": "Debits must equal credits. Debit: 1500.00, Credit: 1400.00"
}
```

---

### 6. Update Journal Entry

Update an existing journal entry.

**Request:**
```
PUT /api/v1/journal_entries.php?id={entry_id}
Content-Type: application/json
```

**Request Body:**
```json
{
  "entry_date": "2025-10-26",
  "description": "Updated description",
  "status": "approved",
  "lines": [
    {
      "account_id": 25,
      "debit": 1600.00,
      "credit": 0.00,
      "description": "Office rent expense - updated"
    },
    {
      "account_id": 10,
      "debit": 0.00,
      "credit": 1600.00,
      "description": "Cash payment for rent - updated"
    }
  ]
}
```

**Notes:**
- By default, only `draft` entries can be updated
- Use `force_update: true` to update posted/approved entries
- All fields are optional - only provided fields will be updated
- If `lines` is provided, all existing lines will be replaced

**Example Request:**
```bash
curl -X PUT "https://your-domain.com/api/v1/journal_entries.php?id=124" \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "description": "Updated monthly expense allocation",
    "status": "approved"
  }'
```

**Success Response:**
```json
{
  "success": true,
  "message": "Journal entry updated successfully"
}
```

---

### 7. Delete Journal Entry

Delete a journal entry (only draft entries can be deleted).

**Request:**
```
DELETE /api/v1/journal_entries.php?id={entry_id}
```

**Example Request:**
```bash
curl -X DELETE "https://your-domain.com/api/v1/journal_entries.php?id=124" \
  -H "Authorization: Bearer YOUR_API_KEY"
```

**Success Response:**
```json
{
  "success": true,
  "message": "Journal entry deleted successfully"
}
```

**Error Response:**
```json
{
  "success": false,
  "error": "Cannot delete posted or approved entries"
}
```

---

## Error Codes

| HTTP Code | Description |
|-----------|-------------|
| 200 | Success |
| 400 | Bad Request - Invalid data or validation error |
| 401 | Unauthorized - Invalid or missing API key |
| 404 | Not Found - Resource doesn't exist |
| 405 | Method Not Allowed |
| 429 | Too Many Requests - Rate limit exceeded |
| 500 | Internal Server Error |

## Common Error Response Format
```json
{
  "success": false,
  "error": "Error message description",
  "timestamp": "2025-10-25T10:30:00+00:00"
}
```

---

## Usage Examples

### Example 1: Get All Posted Journal Entries for October 2025
```bash
curl -X GET "https://your-domain.com/api/v1/journal_entries.php?status=posted&date_from=2025-10-01&date_to=2025-10-31&include_lines=true" \
  -H "Authorization: Bearer YOUR_API_KEY"
```

### Example 2: Create a Simple Journal Entry
```bash
curl -X POST "https://your-domain.com/api/v1/journal_entries.php" \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "entry_date": "2025-10-25",
    "description": "Payment received from customer",
    "lines": [
      {"account_id": 5, "debit": 2500.00, "credit": 0.00, "description": "Cash"},
      {"account_id": 15, "debit": 0.00, "credit": 2500.00, "description": "Accounts Receivable"}
    ]
  }'
```

### Example 3: Get Summary Report for Dashboard
```bash
curl -X GET "https://your-domain.com/api/v1/journal_entries.php?action=summary" \
  -H "Authorization: Bearer YOUR_API_KEY"
```

---

## Integration with Administrative Module

### Recommended Workflow

1. **Initial Sync**: Fetch all journal entries with pagination
```bash
GET /api/v1/journal_entries.php?limit=200&offset=0&include_lines=true
```

2. **Incremental Updates**: Fetch recent changes
```bash
GET /api/v1/journal_entries.php?date_from=2025-10-24&include_lines=true
```

3. **Dashboard Statistics**: Use summary endpoint
```bash
GET /api/v1/journal_entries.php?action=summary
```

4. **Create Entries**: Post new journal entries from Administrative module
```bash
POST /api/v1/journal_entries.php
```

### Logging and Audit Trail
All API operations are automatically logged with:
- API client information
- Timestamp
- Request details
- User actions

Access audit logs through the admin panel: `admin/audit.php`

---

## Getting an API Key

To obtain an API key for the Administrative module:

1. Log in to the admin panel
2. Navigate to **Settings** > **API Clients** ([admin/api_clients.php](../../admin/api_clients.php))
3. Click **Create New API Client**
4. Enter:
   - Name: "Administrative Module"
   - Description: "API access for administrative module integration"
5. Copy the generated API key (format: `ak_...`)
6. Store securely - it will only be shown once

**Security Best Practices:**
- Never commit API keys to version control
- Use environment variables to store keys
- Rotate API keys periodically
- Monitor API usage in the admin panel
- Disable unused API clients

---

## Support

For issues or questions:
- Check the audit log: [admin/audit.php](../../admin/audit.php)
- View API documentation: [admin/api_docs.php](../../admin/api_docs.php)
- Review integration logs: Check `api/logs/` directory

---

## Version History

**v1.0** (2025-10-25)
- Initial release
- Full CRUD operations for journal entries
- Summary reporting endpoint
- API key authentication
- Rate limiting
- Comprehensive audit logging
