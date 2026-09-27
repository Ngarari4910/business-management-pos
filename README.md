# Business Management & POS System

A full-stack business management and point-of-sale system built to support day-to-day retail and wholesale operations.

> **Portfolio / demo repository for the upGrowth Professional Vibe Coder application.**
>
> This repository is a sanitized demonstration build. Production customer records, credentials, receipts, financial documents and private configuration have been removed.

## Why I built it

The project started from a real business workflow rather than a tutorial CRUD exercise. The goal was to connect operational processes that normally live in separate spreadsheets, notebooks and tools into one application.

The system brings together:

- Inventory and stock management
- Purchasing and supplier intake
- Retail and wholesale sales
- Cashier shifts and reconciliation
- Customer credit and outstanding balances
- Receipts and payment records
- Business expenses
- Payroll records
- Sales and inventory reporting
- Barcode-based product lookup
- Stock batches and expiry tracking
- Payment workflow support, including M-Pesa integration hooks
- Role-based admin/cashier workflows

## Product workflow

```text
Supplier / Stock Intake
        ↓
Inventory + Stock Batches
        ↓
Product Lookup / Barcode
        ↓
Cashier Sale
        ↓
Payment + Receipt
        ↓
Stock Movement
        ↓
Reports / Reconciliation / Management
```

## What I would bring to upGrowth

My approach is AI-first but workflow-first: I start by understanding the business problem, users, constraints and success criteria, then use AI-assisted development to prototype, test, debug and iterate quickly.

For example, the same approach used in this POS system can be applied to growth operations:

```text
Lead captured
    ↓
Qualification rules
    ↓
Follow-up status
    ↓
Automated action
    ↓
Team dashboard
    ↓
Performance reporting
```

A natural extension would be a lightweight lead qualification and reporting tool that combines lead information, qualification criteria, campaign source, follow-up status and reporting in one internal product.

## Highlights

### Admin / management

- Operational dashboard
- Inventory monitoring
- Stock analysis
- Employee sales reporting
- Cashier management
- Shift history and reconciliation
- Expense management
- Payroll workflow
- Debt monitoring
- Growth and business analytics

### Cashier / sales

- Cashier authentication
- Shift opening and closing
- Product search and barcode lookup
- Retail and wholesale pricing
- Partial / package quantity handling
- Multiple payment methods
- Receipt generation
- Customer credit workflows
- Payment verification support

### Inventory

- Supplier management
- Stock intake
- Package-to-unit quantities
- Stock movement history
- Batch tracking
- Expiry tracking
- Inventory loss handling
- Barcode mappings

### Engineering

- PHP + MySQL/MariaDB
- JavaScript
- HTML/CSS
- PDO prepared statements
- CSRF protection
- Session-based role controls
- Password hashing
- Database migrations
- Automated flow tests
- API integration hooks
- PDF/data processing utilities

## Project structure

```text
.
├── admin*.php              # Management workflows and reporting
├── cashier*.php            # Cashier authentication and shift workflows
├── sales.php               # Sales interface
├── stock_intake.php        # Stock receiving workflow
├── product_lookup.php      # Product / barcode lookup
├── payment_flow.php        # Payment and business workflow logic
├── stock_batches.php       # Batch allocation and stock deduction
├── receipt*.php            # Receipt generation / display
├── security.php            # Session, CSRF and login protection
├── tests/                  # Focused application tests
├── tools/                  # PDF/data processing utilities
├── database/
│   └── demo_database.sql   # Fictional demo schema + sample records
├── db.example.php          # Safe database configuration template
└── README.md
```

## Running the demo locally

### Requirements

- PHP 8+
- MySQL or MariaDB
- PHP PDO MySQL extension
- Node.js/npm for frontend dependencies
- A local web server such as Apache/XAMPP

### 1. Clone the repository

```bash
git clone <your-repository-url>
cd business-management-pos
```

### 2. Install frontend dependencies

```bash
npm install
```

### 3. Create a local database

Create a database called `pos2_demo`, then import:

```text
database/demo_database.sql
```

### 4. Configure the application

Copy:

```text
db.example.php
```

to:

```text
db.php
```

Then update the local database credentials. `db.php` is ignored by Git.

### Demo credentials

The sanitized database includes a fictional demo account:

```text
Username: admin
Password: DemoPass123!
```

The same demo password is used for the included cashier account. **Change it for any local deployment.**

## Testing

The repository includes focused tests covering areas such as:

- Payment method normalization
- Receipt logic
- Stock intake flow
- Barcode / price schema expectations
- Database-related stock intake behavior

The tests are intentionally kept with the project so the workflow is easier to review.

## Security / privacy note

This public version intentionally excludes:

- Production database exports
- Customer and employee records
- Real phone numbers and email addresses
- Uploaded receipts
- Bank / financial statement documents
- Runtime logs
- Local database credentials
- API credentials and secrets
- `node_modules`

M-Pesa configuration is read from environment variables in the application code; no live credentials are included in this repository.

## Portfolio note

This is a demonstration version of a system built around a real operational business problem. Some production-only integrations and private modules are intentionally omitted or sanitized for portfolio use.

For a walkthrough, see the accompanying Loom video supplied with the upGrowth application.
